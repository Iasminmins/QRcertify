<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

// ── Atualiza status de todos os certs da empresa ──────────────────────────────
$db->prepare(
    'UPDATE certificates c
     JOIN employees e ON e.id = c.employee_id
     SET c.status = CASE
        WHEN c.expiry_date < CURDATE() THEN "expired"
        WHEN c.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "expiring"
        ELSE "valid"
     END
     WHERE e.company_id = ?'
)->execute([$companyId]);

// ── Estatísticas dos 4 cards ──────────────────────────────────────────────────
$statsQueries = [
    'employees'     => 'SELECT COUNT(*) FROM employees WHERE company_id = ? AND status = "active"',
    'certs_valid'   => 'SELECT COUNT(*) FROM certificates c JOIN employees e ON e.id = c.employee_id WHERE e.company_id = ? AND c.status = "valid"',
    'certs_expiring'=> 'SELECT COUNT(*) FROM certificates c JOIN employees e ON e.id = c.employee_id WHERE e.company_id = ? AND c.status = "expiring"',
    'certs_expired' => 'SELECT COUNT(*) FROM certificates c JOIN employees e ON e.id = c.employee_id WHERE e.company_id = ? AND c.status = "expired"',
];
$stats = [];
foreach ($statsQueries as $key => $sql) {
    $stmt = $db->prepare($sql);
    $stmt->execute([$companyId]);
    $stats[$key] = (int) $stmt->fetchColumn();
}

// Se a migração 023 ainda não rodou, o card mostra 0 em vez de quebrar o dashboard.
$qrViews30Days = 0;
try {
    $stmtViews = $db->prepare('SELECT COUNT(*) FROM public_views WHERE company_id = ? AND viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $stmtViews->execute([$companyId]);
    $qrViews30Days = (int)$stmtViews->fetchColumn();
} catch (Throwable $e) {
    $qrViews30Days = 0;
}

// ── ASO: atualiza status e busca indicadores ──────────────────────────────────
$db->prepare(
    'UPDATE employee_asos a
     JOIN employees e ON e.id = a.employee_id
     SET a.document_status = CASE
        WHEN a.expiration_date < CURDATE() THEN "vencido"
        WHEN a.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "vencendo"
        ELSE "valido"
     END
     WHERE e.company_id = ?'
)->execute([$companyId]);

$asoStats = [];
foreach ([
    'aso_valido'   => 'SELECT COUNT(*) FROM employee_asos a JOIN employees e ON e.id=a.employee_id WHERE e.company_id=? AND a.document_status="valido"',
    'aso_vencendo' => 'SELECT COUNT(*) FROM employee_asos a JOIN employees e ON e.id=a.employee_id WHERE e.company_id=? AND a.document_status="vencendo"',
    'aso_vencido'  => 'SELECT COUNT(*) FROM employee_asos a JOIN employees e ON e.id=a.employee_id WHERE e.company_id=? AND a.document_status="vencido"',
    'aso_sem'      => 'SELECT COUNT(*) FROM employees e WHERE e.company_id=? AND e.status="active" AND NOT EXISTS (SELECT 1 FROM employee_asos a WHERE a.employee_id=e.id)',
] as $key => $sql) {
    $stmt = $db->prepare($sql);
    $stmt->execute([$companyId]);
    $asoStats[$key] = (int) $stmt->fetchColumn();
}

// ── Dados para gráfico: certificados por tipo ─────────────────────────────────
$stmtByType = $db->prepare(
    'SELECT c.type, COUNT(*) AS total
     FROM certificates c
     JOIN employees e ON e.id = c.employee_id
     WHERE e.company_id = ?
     GROUP BY c.type
     ORDER BY total DESC
     LIMIT 8'
);
$stmtByType->execute([$companyId]);
$certsByType = $stmtByType->fetchAll();

// ── Certificados a vencer (próximos 30 dias) ──────────────────────────────────
$stmtExp = $db->prepare(
    'SELECT c.*, e.name AS employee_name
     FROM certificates c
     JOIN employees e ON e.id = c.employee_id
     WHERE e.company_id = ? AND c.status = "expiring"
     ORDER BY c.expiry_date ASC
     LIMIT 5'
);
$stmtExp->execute([$companyId]);
$expiringCerts = $stmtExp->fetchAll();

// ── Onboarding: mostra wizard se empresa não tem funcionários ainda ───────────
$isFirstLogin = ($stats['employees'] === 0 && !isset($_SESSION['onboarding_dismissed']));

// ── Plano da empresa ──────────────────────────────────────────────────────────
$stmtPlan = $db->prepare('SELECT plan, subscription_status FROM companies WHERE id = ?');
$stmtPlan->execute([$companyId]);
$companyInfo = $stmtPlan->fetch();
$currentPlan = normalizePlanKey($companyInfo['plan'] ?? 'starter');
$empLimit    = getPlanEmployeeLimit($currentPlan);
$empUsagePct = $empLimit > 0 ? min(100, round($stats['employees'] / $empLimit * 100)) : 0;

// ── Certificados recentes (últimos 3 adicionados) ─────────────────────────────
$stmtRecent = $db->prepare(
    'SELECT c.*, e.name AS employee_name, e.role AS employee_role
     FROM certificates c
     JOIN employees e ON e.id = c.employee_id
     WHERE e.company_id = ?
     ORDER BY c.created_at DESC
     LIMIT 3'
);
$stmtRecent->execute([$companyId]);
$recentCerts = $stmtRecent->fetchAll();

// ── JSON para Chart.js ────────────────────────────────────────────────────────
$pieData  = json_encode([$stats['certs_valid'], $stats['certs_expiring'], $stats['certs_expired']]);
$barLabels = json_encode(array_column($certsByType, 'type'));
$barData   = json_encode(array_column($certsByType, 'total'));

$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<?php if ($isFirstLogin): ?>
<!-- ── ONBOARDING WIZARD ──────────────────────────────────────────────────── -->
<div id="onboarding-banner" class="mb-6 bg-gradient-to-r from-industrial-blue to-blue-700 rounded-2xl p-6 text-white relative overflow-hidden">
  <div class="absolute right-0 top-0 h-full w-64 opacity-5" style="background:radial-gradient(circle,#fff 0%,transparent 70%)"></div>
  <button onclick="dismissOnboarding()" class="absolute top-4 right-4 text-white/60 hover:text-white text-lg leading-none">✕</button>
  <p class="text-xs font-semibold uppercase tracking-widest text-blue-200 mb-1">Primeiros passos</p>
  <h2 class="text-xl font-bold mb-4">Bem-vindo ao QRCertify! Configure sua conta em 3 passos.</h2>
  <div class="grid gap-3 sm:grid-cols-3">
    <a href="<?= BASE_URL ?>/admin/employee-form.php" class="flex items-start gap-3 bg-white/10 hover:bg-white/20 rounded-xl p-4 transition">
      <div class="w-8 h-8 rounded-full bg-industrial-yellow flex-shrink-0 flex items-center justify-center text-gray-900 font-bold text-sm">1</div>
      <div><p class="font-semibold text-sm">Cadastrar funcionários</p><p class="text-xs text-blue-200 mt-0.5">Adicione sua equipe manualmente ou via CSV</p></div>
    </a>
    <a href="<?= BASE_URL ?>/admin/certificate-form.php" class="flex items-start gap-3 bg-white/10 hover:bg-white/20 rounded-xl p-4 transition">
      <div class="w-8 h-8 rounded-full bg-industrial-yellow flex-shrink-0 flex items-center justify-center text-gray-900 font-bold text-sm">2</div>
      <div><p class="font-semibold text-sm">Adicionar certificados</p><p class="text-xs text-blue-200 mt-0.5">Registre NRs com validade e PDF</p></div>
    </a>
    <a href="<?= BASE_URL ?>/admin/notify-expiring.php" class="flex items-start gap-3 bg-white/10 hover:bg-white/20 rounded-xl p-4 transition">
      <div class="w-8 h-8 rounded-full bg-industrial-yellow flex-shrink-0 flex items-center justify-center text-gray-900 font-bold text-sm">3</div>
      <div><p class="font-semibold text-sm">Ativar notificações</p><p class="text-xs text-blue-200 mt-0.5">Alertas automáticos de vencimento</p></div>
    </a>
  </div>
</div>
<script>
function dismissOnboarding() {
  document.getElementById('onboarding-banner').style.display = 'none';
  fetch('<?= BASE_URL ?>/admin/dismiss-onboarding.php', {method:'POST'});
}
</script>
<?php endif; ?>

<?php if ($empUsagePct >= 80): ?>
<!-- Aviso de limite de plano -->
<div class="mb-4 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 flex items-center justify-between gap-4">
  <div class="flex items-center gap-3">
    <svg width="18" height="18" fill="none" stroke="#d97706" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <span class="text-sm text-amber-800 font-medium">Você usou <?= $empUsagePct ?>% do limite do plano <?= ucfirst($currentPlan) ?> (<?= $stats['employees'] ?>/<?= $empLimit ?> funcionários).</span>
  </div>
  <a href="<?= BASE_URL ?>/admin/plano.php" class="text-xs font-semibold text-amber-700 border border-amber-300 px-3 py-1.5 rounded-lg hover:bg-amber-100 transition whitespace-nowrap">Fazer upgrade</a>
</div>
<?php endif; ?>

<div class="mb-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
        <p class="text-gray-500 text-sm mt-0.5">Visão geral de conformidade — certificados e saúde ocupacional</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/certificate-form.php"
       class="inline-flex items-center gap-2 bg-industrial-blue hover:bg-blue-800 text-white px-4 py-2 rounded-lg font-medium transition w-full sm:w-auto justify-center">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Novo Certificado
    </a>
</div>

<?php renderFlashes(); ?>

<!-- ── Cards de estatísticas ─────────────────────────────────────────────── -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">

    <a href="<?= BASE_URL ?>/admin/employees.php"
       class="bg-white p-5 rounded-lg shadow-sm border hover:shadow-md hover:border-blue-200 transition group cursor-pointer">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">Funcionários Ativos</p>
                <p class="text-3xl font-bold text-gray-900 mt-1"><?= $stats['employees'] ?></p>
                <p class="text-xs text-gray-400 mt-1">colaboradores cadastrados</p>
            </div>
            <div class="h-12 w-12 rounded-lg bg-blue-100 flex items-center justify-center group-hover:bg-blue-200 transition">
                <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
        </div>
    </a>

    <a href="<?= BASE_URL ?>/admin/certificates.php?status=valid"
       class="bg-white p-5 rounded-lg shadow-sm border hover:shadow-md hover:border-green-200 transition group cursor-pointer">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">Certificados Válidos</p>
                <p class="text-3xl font-bold text-green-600 mt-1"><?= $stats['certs_valid'] ?></p>
                <p class="text-xs text-gray-400 mt-1">certificados ativos e em dia</p>
            </div>
            <div class="h-12 w-12 rounded-lg bg-green-100 flex items-center justify-center group-hover:bg-green-200 transition">
                <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
        </div>
    </a>

    <a href="<?= BASE_URL ?>/admin/certificates.php?status=expiring"
       class="bg-white p-5 rounded-lg shadow-sm border hover:shadow-md hover:border-amber-200 transition group cursor-pointer">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">Expirando em 30 dias</p>
                <p class="text-3xl font-bold text-amber-600 mt-1"><?= $stats['certs_expiring'] ?></p>
                <p class="text-xs text-gray-400 mt-1">requerem atenção</p>
            </div>
            <div class="h-12 w-12 rounded-lg bg-amber-100 flex items-center justify-center group-hover:bg-amber-200 transition">
                <svg class="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
        </div>
    </a>

    <a href="<?= BASE_URL ?>/admin/certificates.php?status=expired"
       class="bg-white p-5 rounded-lg shadow-sm border hover:shadow-md hover:border-red-200 transition group cursor-pointer">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">Certificados Expirados</p>
                <p class="text-3xl font-bold text-red-600 mt-1"><?= $stats['certs_expired'] ?></p>
                <p class="text-xs text-gray-400 mt-1">precisam ser renovados</p>
            </div>
            <div class="h-12 w-12 rounded-lg bg-red-100 flex items-center justify-center group-hover:bg-red-200 transition">
                <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
        </div>
    </a>

    <div class="bg-white p-5 rounded-lg shadow-sm border">
        <p class="text-sm text-gray-600">Total de consultas por QR Code nos últimos 30 dias</p>
        <p class="text-3xl font-bold text-industrial-blue mt-1"><?= $qrViews30Days ?></p>
        <p class="text-xs text-gray-400 mt-1">consultas públicas da empresa</p>
    </div>

</div>

<!-- ── Gráficos ───────────────────────────────────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">

    <!-- Gráfico Pizza: Status dos Certificados -->
    <div class="bg-white rounded-lg shadow-sm border p-5">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Status dos Certificados</h2>
        <?php $totalCerts = $stats['certs_valid'] + $stats['certs_expiring'] + $stats['certs_expired']; ?>
        <?php if ($totalCerts === 0): ?>
            <div class="h-64 flex items-center justify-center text-gray-400">
                <p>Nenhum certificado cadastrado.</p>
            </div>
        <?php else: ?>
            <div class="h-64 relative">
                <canvas id="chartPie"></canvas>
            </div>
        <?php endif; ?>
    </div>

    <!-- Gráfico Barras: Certificados por Tipo -->
    <div class="bg-white rounded-lg shadow-sm border p-5">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Certificados por Tipo</h2>
        <?php if (empty($certsByType)): ?>
            <div class="h-64 flex items-center justify-center text-gray-400">
                <p>Nenhum certificado cadastrado.</p>
            </div>
        <?php else: ?>
            <div class="h-64 relative">
                <canvas id="chartBar"></canvas>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- ── Painel ASO — faixa horizontal ────────────────────────────────────── -->
<?php
$asoTotal   = $asoStats['aso_valido'] + $asoStats['aso_vencendo'] + $asoStats['aso_vencido'] + $asoStats['aso_sem'];
$pctValido  = $asoTotal > 0 ? round($asoStats['aso_valido']   / $asoTotal * 100) : 0;
$pctVencend = $asoTotal > 0 ? round($asoStats['aso_vencendo'] / $asoTotal * 100) : 0;
$pctVencido = $asoTotal > 0 ? round($asoStats['aso_vencido']  / $asoTotal * 100) : 0;
$pctSem     = $asoTotal > 0 ? round($asoStats['aso_sem']      / $asoTotal * 100) : 0;
?>
<div class="bg-white rounded-lg shadow-sm border mb-8 overflow-hidden">
    <div class="px-5 py-4 flex items-center justify-between border-b">
        <div class="flex items-center gap-2">
            <svg class="h-4 w-4 text-industrial-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            <h2 class="text-sm font-semibold text-gray-800">Saúde Ocupacional — ASO</h2>
        </div>
        <a href="<?= BASE_URL ?>/admin/notify-expiring.php"
           class="text-xs text-industrial-blue hover:underline">Ver alertas →</a>
    </div>

    <!-- Barra de proporção -->
    <?php if ($asoTotal > 0): ?>
    <div class="px-5 pt-4">
        <div class="flex h-3 rounded-full overflow-hidden gap-px bg-gray-100">
            <?php if ($pctValido  > 0): ?><div class="bg-green-500 transition-all" style="width:<?= $pctValido ?>%"  title="Válidos: <?= $asoStats['aso_valido'] ?>"></div><?php endif; ?>
            <?php if ($pctVencend > 0): ?><div class="bg-amber-400 transition-all" style="width:<?= $pctVencend ?>%" title="Vencendo: <?= $asoStats['aso_vencendo'] ?>"></div><?php endif; ?>
            <?php if ($pctVencido > 0): ?><div class="bg-red-500 transition-all"   style="width:<?= $pctVencido ?>%" title="Vencidos: <?= $asoStats['aso_vencido'] ?>"></div><?php endif; ?>
            <?php if ($pctSem     > 0): ?><div class="bg-gray-300 transition-all"  style="width:<?= $pctSem ?>%"     title="Sem ASO: <?= $asoStats['aso_sem'] ?>"></div><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 4 métricas em linha -->
    <div class="grid grid-cols-2 lg:grid-cols-4 divide-x divide-y lg:divide-y-0 divide-gray-100">

        <div class="px-5 py-4 flex items-center gap-3">
            <div class="h-9 w-9 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0">
                <svg class="h-4 w-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-green-600 leading-none"><?= $asoStats['aso_valido'] ?></p>
                <p class="text-xs text-gray-500 mt-1">Válidos</p>
                <?php if ($asoTotal > 0): ?><p class="text-[10px] text-gray-400"><?= $pctValido ?>% do total</p><?php endif; ?>
            </div>
        </div>

        <a href="<?= BASE_URL ?>/admin/notify-expiring.php"
           class="px-5 py-4 flex items-center gap-3 hover:bg-amber-50 transition">
            <div class="h-9 w-9 rounded-full bg-amber-100 flex items-center justify-center flex-shrink-0">
                <svg class="h-4 w-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-amber-500 leading-none"><?= $asoStats['aso_vencendo'] ?></p>
                <p class="text-xs text-gray-500 mt-1">Vencendo</p>
                <?php if ($asoTotal > 0): ?><p class="text-[10px] text-gray-400"><?= $pctVencend ?>% do total</p><?php endif; ?>
            </div>
        </a>

        <a href="<?= BASE_URL ?>/admin/notify-expiring.php"
           class="px-5 py-4 flex items-center gap-3 hover:bg-red-50 transition">
            <div class="h-9 w-9 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                <svg class="h-4 w-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-red-600 leading-none"><?= $asoStats['aso_vencido'] ?></p>
                <p class="text-xs text-gray-500 mt-1">Vencidos</p>
                <?php if ($asoTotal > 0): ?><p class="text-[10px] text-gray-400"><?= $pctVencido ?>% do total</p><?php endif; ?>
            </div>
        </a>

        <a href="<?= BASE_URL ?>/admin/employees.php"
           class="px-5 py-4 flex items-center gap-3 hover:bg-gray-50 transition">
            <div class="h-9 w-9 rounded-full bg-gray-100 flex items-center justify-center flex-shrink-0">
                <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                </svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-gray-500 leading-none"><?= $asoStats['aso_sem'] ?></p>
                <p class="text-xs text-gray-500 mt-1">Sem ASO cadastrado</p>
                <?php if ($asoTotal > 0): ?><p class="text-[10px] text-gray-400"><?= $pctSem ?>% do total</p><?php endif; ?>
            </div>
        </a>

    </div>
</div>

<!-- ── Certificados Recentes ──────────────────────────────────────────────── -->
<?php if (!empty($recentCerts)): ?>
<div class="mb-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Certificados Recentes</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($recentCerts as $cert):
            $badge = getStatusBadge($cert['status']);
            $borderColor = match($cert['status']) {
                'valid'    => 'border-t-green-500',
                'expiring' => 'border-t-amber-500',
                'expired'  => 'border-t-red-500',
                default    => 'border-t-gray-300',
            };
        ?>
        <div class="bg-white rounded-lg shadow-sm border border-t-4 <?= $borderColor ?> overflow-hidden
                    hover:shadow-md transition cursor-pointer"
             onclick="window.open('<?= getCertificatePublicUrl($cert['hash']) ?>', '_blank')">
            <div class="bg-industrial-blue text-white px-4 py-3 flex items-center justify-between">
                <span class="text-xs font-medium opacity-90">Carteira Digital</span>
                <span class="text-xs px-2 py-0.5 rounded-full bg-white/20 border border-white/30">
                    <?= e($badge['label']) ?>
                </span>
            </div>
            <div class="p-4">
                <p class="text-xs font-semibold text-industrial-blue mb-1"><?= e($cert['type']) ?></p>
                <p class="font-semibold text-gray-900 text-sm leading-snug mb-2"><?= e($cert['title']) ?></p>
                <p class="text-xs text-gray-500"><?= e($cert['employee_name']) ?> · <?= e($cert['employee_role']) ?></p>
                <div class="mt-3 pt-3 border-t grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <div class="text-gray-400">Emissão</div>
                        <div class="font-medium text-gray-700"><?= formatDate($cert['issued_date']) ?></div>
                    </div>
                    <div>
                        <div class="text-gray-400">Validade</div>
                        <div class="font-medium
                            <?= $cert['status'] === 'expired' ? 'text-red-600' : ($cert['status'] === 'expiring' ? 'text-amber-600' : 'text-green-700') ?>">
                            <?= formatDate($cert['expiry_date']) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Tabela: Certificados a vencer ─────────────────────────────────────── -->
<div class="bg-white rounded-lg shadow-sm border">
    <div class="p-5 border-b flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900">Certificados a vencer nos próximos 30 dias</h2>
        <?php if (!empty($expiringCerts)): ?>
            <a href="<?= BASE_URL ?>/admin/certificates.php?status=expiring"
               class="text-sm text-industrial-blue hover:underline">Ver todos →</a>
        <?php endif; ?>
    </div>
    <div class="overflow-x-auto">
        <?php if (empty($expiringCerts)): ?>
            <p class="p-6 text-center text-gray-500">Nenhum certificado a vencer nos próximos 30 dias. 🎉</p>
        <?php else: ?>
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Funcionário</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Certificado</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Tipo</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Validade</th>
                        <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($expiringCerts as $cert): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3 text-sm"><?= e($cert['employee_name']) ?></td>
                        <td class="px-5 py-3 text-sm font-medium"><?= e($cert['title']) ?></td>
                        <td class="px-5 py-3 text-sm text-gray-600 hidden md:table-cell"><?= e($cert['type']) ?></td>
                        <td class="px-5 py-3 text-sm text-amber-600 font-medium"><?= formatDate($cert['expiry_date']) ?></td>
                        <td class="px-5 py-3 text-right">
                            <a href="<?= BASE_URL ?>/admin/certificate-form.php?id=<?= (int)$cert['id'] ?>"
                               class="text-xs text-industrial-blue hover:underline">Editar</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- ── Chart.js via CDN + inicialização dos gráficos ─────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    // ── Gráfico Pizza ─────────────────────────────────────────────────────
    const pieCanvas = document.getElementById('chartPie');
    if (pieCanvas) {
        new Chart(pieCanvas, {
            type: 'doughnut',
            data: {
                labels: ['Válidos', 'Expirando', 'Expirados'],
                datasets: [{
                    data: <?= $pieData ?>,
                    backgroundColor: ['#10B981', '#F59E0B', '#EF4444'],
                    borderColor: ['#fff', '#fff', '#fff'],
                    borderWidth: 3,
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 16, font: { size: 12 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: ctx => ` ${ctx.label}: ${ctx.parsed} certificado(s)`
                        }
                    }
                },
                cutout: '60%',
            }
        });
    }

    // ── Gráfico Barras ────────────────────────────────────────────────────
    const barCanvas = document.getElementById('chartBar');
    if (barCanvas) {
        new Chart(barCanvas, {
            type: 'bar',
            data: {
                labels: <?= $barLabels ?>,
                datasets: [{
                    label: 'Certificados',
                    data: <?= $barData ?>,
                    backgroundColor: '#1e3a8a',
                    borderRadius: 6,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ` ${ctx.parsed.y} certificado(s)`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, precision: 0 },
                        grid: { color: '#f3f4f6' }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
