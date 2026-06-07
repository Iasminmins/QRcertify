<?php
require_once __DIR__ . '/../config/config.php';
requireRole('employee');

$user = currentUser();
$db   = getDB();

$stmt = $db->prepare('SELECT * FROM employees WHERE email = ? LIMIT 1');
$stmt->execute([$user['email']]);
$employee = $stmt->fetch();

$certificates = [];
$asos         = [];

if ($employee) {
    $eid = (int)$employee['id'];

    $db->prepare(
        'UPDATE certificates SET status = CASE
            WHEN expiry_date < CURDATE() THEN "expired"
            WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "expiring"
            ELSE "valid" END
         WHERE employee_id = ?'
    )->execute([$eid]);

    $stmt = $db->prepare('SELECT * FROM certificates WHERE employee_id = ? ORDER BY expiry_date DESC');
    $stmt->execute([$eid]);
    $certificates = $stmt->fetchAll();

    $db->prepare(
        'UPDATE employee_asos SET document_status = CASE
            WHEN expiration_date < CURDATE() THEN "vencido"
            WHEN expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "vencendo"
            ELSE "valido" END
         WHERE employee_id = ?'
    )->execute([$eid]);

    $stmtAso = $db->prepare('SELECT * FROM employee_asos WHERE employee_id = ? ORDER BY exam_date DESC');
    $stmtAso->execute([$eid]);
    $asos = $stmtAso->fetchAll();
}

$validCount    = count(array_filter($certificates, fn($c) => $c['status'] === 'valid'));
$expiringCount = count(array_filter($certificates, fn($c) => $c['status'] === 'expiring'));
$expiredCount  = count(array_filter($certificates, fn($c) => $c['status'] === 'expired'));

$alertsCert = array_filter($certificates, fn($c) => in_array($c['status'], ['expiring','expired']));
$alertsAso  = array_filter($asos, fn($a) => in_array($a['document_status'], ['vencendo','vencido']));
$hasAlerts  = !empty($alertsCert) || !empty($alertsAso);

$employeePublicUrl = $employee ? getEmployeePublicUrl((int)$employee['id']) : '';
$employeeQrSrc     = $employee ? BASE_URL . '/public/qr.php?size=180&data=' . urlencode($employeePublicUrl) : '';
$authUrl           = $employee ? getAuthorizationPublicUrl((int)$employee['id']) : '';
$authQrSrc         = $employee ? BASE_URL . '/public/qr.php?size=180&data=' . urlencode($authUrl) : '';

$asoTypeLabels = [
    'admissional'      => 'Admissional',
    'periodico'        => 'Periódico',
    'retorno_trabalho' => 'Retorno ao Trabalho',
    'mudanca_risco'    => 'Mudança de Risco',
    'demissional'      => 'Demissional',
];

$pageTitle = 'Minha Área';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- ══ HEADER ═══════════════════════════════════════════════════════ -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Minha Área</h1>
        <p class="text-sm text-gray-500 mt-0.5">Seus certificados e saúde ocupacional</p>
    </div>
    <a href="<?= BASE_URL ?>/employee/change-password.php" class="btn btn-secondary btn-sm">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
        </svg>
        Alterar Senha
    </a>
</div>

<?php renderFlashes(); ?>

<?php if (!$employee): ?>
<div class="section-card">
    <div class="py-12 text-center text-gray-400">
        <svg class="h-12 w-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                  d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        <p class="font-medium text-gray-600">Cadastro não encontrado.</p>
        <p class="text-sm mt-1">Entre em contato com o RH da sua empresa.</p>
    </div>
</div>
<?php else: ?>

<?php if ($hasAlerts): ?>
<!-- ══ BANNER DE ALERTAS ════════════════════════════════════════════ -->
<div class="mb-5 rounded-xl overflow-hidden border border-red-200 shadow-sm">
    <div class="bg-red-600 text-white px-5 py-3 flex items-center gap-2">
        <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        <span class="font-semibold text-sm">Atenção — Itens vencidos ou a vencer!</span>
    </div>
    <div class="bg-red-50 px-5 py-3 space-y-1.5">
        <?php foreach ($alertsCert as $c): ?>
        <div class="flex items-center gap-2 text-sm text-red-800">
            <span><?= $c['status'] === 'expired' ? '🔴' : '🟡' ?></span>
            <span>
                Certificado: <strong><?= e($c['type']) ?></strong> —
                <?= e($c['title']) ?>
                <span class="text-red-600 font-medium ml-1">· <?= formatDate($c['expiry_date']) ?></span>
            </span>
        </div>
        <?php endforeach; ?>
        <?php foreach ($alertsAso as $a): ?>
        <div class="flex items-center gap-2 text-sm text-red-800">
            <span><?= $a['document_status'] === 'vencido' ? '🔴' : '🟡' ?></span>
            <span>
                ASO: <strong><?= e($asoTypeLabels[$a['aso_type']] ?? $a['aso_type']) ?></strong>
                <span class="text-red-600 font-medium ml-1">· <?= formatDate($a['expiration_date']) ?></span>
            </span>
        </div>
        <?php endforeach; ?>
        <p class="text-xs text-red-600 font-semibold pt-1 border-t border-red-200">
            📋 Procure o RH da sua empresa para regularizar.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- ══ CARD DO COLABORADOR ══════════════════════════════════════════ -->
<div class="section-card overflow-hidden mb-5">

    <!-- Topo gradiente -->
    <div class="bg-gradient-to-r from-industrial-blue to-blue-700 p-6">
        <div class="flex flex-col sm:flex-row sm:items-center gap-5">

            <!-- Foto/avatar -->
            <div class="h-20 w-20 rounded-2xl bg-white/20 border-2 border-white/30 flex items-center justify-center
                        overflow-hidden flex-shrink-0 shadow-lg">
                <?php if (!empty($employee['photo'])): ?>
                    <img src="<?= e($employee['photo']) ?>" alt="" class="h-full w-full object-cover">
                <?php else: ?>
                    <span class="text-3xl font-bold text-white">
                        <?= strtoupper(mb_substr($employee['name'], 0, 1)) ?>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Nome + dados -->
            <div class="flex-1 min-w-0">
                <h2 class="text-2xl font-bold text-white leading-tight"><?= e($employee['name']) ?></h2>
                <p class="text-blue-200 text-sm mt-0.5"><?= e($employee['role']) ?></p>
                <div class="flex flex-wrap gap-3 mt-2">
                    <?php if (!empty($employee['department'])): ?>
                    <span class="inline-flex items-center gap-1 text-xs bg-white/15 text-white px-2.5 py-1 rounded-full">
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5"/>
                        </svg>
                        <?= e($employee['department']) ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($employee['manager_name'])): ?>
                    <span class="inline-flex items-center gap-1 text-xs bg-white/15 text-white px-2.5 py-1 rounded-full">
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        <?= e($employee['manager_name']) ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($employee['cpf'])): ?>
                    <span class="inline-flex items-center gap-1 text-xs bg-white/15 text-white px-2.5 py-1 rounded-full">
                        <?php
                        $cpf = preg_replace('/\D/', '', $employee['cpf']);
                        echo strlen($cpf) === 11
                            ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf)
                            : e($employee['cpf']);
                        ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Contadores -->
            <div class="flex gap-2 flex-shrink-0">
                <div class="text-center px-4 py-3 bg-white/10 rounded-xl">
                    <div class="text-2xl font-bold text-green-300"><?= $validCount ?></div>
                    <div class="text-xs text-white/70 mt-0.5">Válidos</div>
                </div>
                <?php if ($expiringCount > 0): ?>
                <div class="text-center px-4 py-3 bg-amber-500/30 rounded-xl">
                    <div class="text-2xl font-bold text-amber-300"><?= $expiringCount ?></div>
                    <div class="text-xs text-white/70 mt-0.5">Expirando</div>
                </div>
                <?php endif; ?>
                <?php if ($expiredCount > 0): ?>
                <div class="text-center px-4 py-3 bg-red-500/30 rounded-xl">
                    <div class="text-2xl font-bold text-red-300"><?= $expiredCount ?></div>
                    <div class="text-xs text-white/70 mt-0.5">Expirados</div>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- Carteiras digitais -->
    <div class="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-gray-100">

        <!-- Carteira de Autorização (primeiro) -->
        <div class="flex flex-col sm:flex-row items-center gap-4 p-5 bg-blue-50/50">
            <div class="p-2.5 border-2 border-blue-200 rounded-2xl bg-white shadow-sm flex-shrink-0">
                <img id="qr-auth" src="<?= e($authQrSrc) ?>" alt="QR Autorização" width="110" height="110">
            </div>
            <div class="flex-1 min-w-0 text-center sm:text-left">
                <p class="font-semibold text-gray-800 text-sm">🪪 Carteira de Autorização</p>
                <p class="text-xs text-gray-400 mt-0.5 mb-3">NRs habilitadas, ASO e emergências</p>
                <div class="flex flex-wrap gap-2 justify-center sm:justify-start">
                    <a href="<?= e($authUrl) ?>" target="_blank" class="btn btn-primary btn-sm text-xs">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                        Abrir
                    </a>
                    <button onclick="copyLink('<?= e($authUrl) ?>')" class="btn btn-secondary btn-sm text-xs">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                        </svg>
                        Copiar link
                    </button>
                    <button onclick="downloadQR('qr-auth','carteira-autorizacao')" class="btn btn-secondary btn-sm text-xs">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Baixar QR
                    </button>
                </div>
            </div>
        </div>

        <!-- Carteira Digital (segundo) -->
        <div class="flex flex-col sm:flex-row items-center gap-4 p-5">
            <div class="p-2.5 border-2 border-gray-200 rounded-2xl bg-white shadow-sm flex-shrink-0">
                <img id="qr-digital" src="<?= e($employeeQrSrc) ?>" alt="QR Carteira" width="110" height="110">
            </div>
            <div class="flex-1 min-w-0 text-center sm:text-left">
                <p class="font-semibold text-gray-800 text-sm">📋 Carteira Digital</p>
                <p class="text-xs text-gray-400 mt-0.5 mb-3">Todos os seus certificados e treinamentos</p>
                <div class="flex flex-wrap gap-2 justify-center sm:justify-start">
                    <a href="<?= $employeePublicUrl ?>" target="_blank" class="btn btn-secondary btn-sm text-xs">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                        Abrir
                    </a>
                    <button onclick="copyLink('<?= $employeePublicUrl ?>')" class="btn btn-secondary btn-sm text-xs">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                        </svg>
                        Copiar link
                    </button>
                    <button onclick="downloadQR('qr-digital','carteira-digital')" class="btn btn-secondary btn-sm text-xs">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Baixar QR
                    </button>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ══ ABAS ══════════════════════════════════════════════════════════ -->
<div class="mb-4 border-b border-gray-200">
    <nav class="flex gap-1 -mb-px overflow-x-auto">
        <button onclick="setTab('certificados')" id="tab-btn-certificados"
                class="tab-btn active-tab flex items-center gap-2 px-5 py-3 text-sm font-semibold border-b-2 whitespace-nowrap transition">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            Certificados
            <span class="tab-count px-2 py-0.5 rounded-full text-xs font-bold">
                <?= count($certificates) ?>
            </span>
        </button>
        <button onclick="setTab('aso')" id="tab-btn-aso"
                class="tab-btn inactive-tab flex items-center gap-2 px-5 py-3 text-sm font-semibold border-b-2 whitespace-nowrap transition">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
            </svg>
            Saúde Ocupacional
            <span class="tab-count px-2 py-0.5 rounded-full text-xs font-bold">
                <?= count($asos) ?>
            </span>
        </button>
    </nav>
</div>

<style>
.active-tab   { border-color: #1e3a8a; color: #1e3a8a; }
.active-tab   .tab-count { background: #1e3a8a; color: white; }
.inactive-tab { border-color: transparent; color: #6b7280; }
.inactive-tab .tab-count { background: #f1f5f9; color: #64748b; }
.inactive-tab:hover { color: #374151; border-color: #d1d5db; }
</style>

<!-- ══ ABA: CERTIFICADOS ════════════════════════════════════════════ -->
<div id="tab-certificados">
<?php if (empty($certificates)): ?>
    <div class="section-card">
        <div class="py-14 text-center">
            <div class="text-5xl mb-3">📜</div>
            <p class="text-gray-500 font-medium">Nenhum certificado cadastrado ainda.</p>
            <p class="text-sm text-gray-400 mt-1">Fale com o RH para registrar seus treinamentos.</p>
        </div>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php foreach ($certificates as $cert):
            $badge     = getStatusBadge($cert['status']);
            $topColor  = match($cert['status']) { 'valid' => '#10b981', 'expiring' => '#f59e0b', 'expired' => '#ef4444', default => '#94a3b8' };
            $dateClass = match($cert['status']) { 'expired' => 'color:#dc2626', 'expiring' => 'color:#b45309', default => 'color:#16a34a' };
        ?>
        <a href="<?= getCertificatePublicUrl($cert['hash']) ?>" target="_blank"
           class="section-card block hover:shadow-lg transition overflow-hidden group"
           style="border-top: 4px solid <?= $topColor ?>; text-decoration:none">
            <div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;padding:.875rem 1.125rem;
                        display:flex;align-items:center;justify-content:space-between">
                <span style="font-size:.7rem;opacity:.75;font-weight:600;letter-spacing:.05em;text-transform:uppercase">
                    Carteira Digital
                </span>
                <span class="badge badge-<?= $cert['status'] ?>"><?= e($badge['label']) ?></span>
            </div>
            <div class="section-card-body">
                <p style="font-size:.7rem;font-weight:700;color:#1e3a8a;margin-bottom:.4rem;text-transform:uppercase;letter-spacing:.04em">
                    <?= e($cert['type']) ?>
                </p>
                <p style="font-weight:700;color:#0f172a;font-size:.95rem;line-height:1.35;margin-bottom:.875rem">
                    <?= e($cert['title']) ?>
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;font-size:.75rem">
                    <div style="background:#f8fafc;border-radius:.5rem;padding:.5rem .625rem">
                        <div style="color:#94a3b8;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.2rem">Emissão</div>
                        <div style="font-weight:600;color:#334155"><?= formatDate($cert['issued_date']) ?></div>
                    </div>
                    <div style="background:#f8fafc;border-radius:.5rem;padding:.5rem .625rem">
                        <div style="color:#94a3b8;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.2rem">Validade</div>
                        <div style="font-weight:700;<?= $dateClass ?>"><?= formatDate($cert['expiry_date']) ?></div>
                    </div>
                </div>
                <div style="margin-top:.875rem;padding-top:.875rem;border-top:1px solid #f1f5f9;
                            font-size:.75rem;color:#1e3a8a;font-weight:700;text-align:center;
                            display:flex;align-items:center;justify-content:center;gap:.35rem">
                    <svg style="width:.875rem;height:.875rem" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 3.5a.5.5 0 11-1 0 .5.5 0 011 0z"/>
                    </svg>
                    Ver QR Code
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<!-- ══ ABA: ASO ═════════════════════════════════════════════════════ -->
<div id="tab-aso" class="hidden space-y-4">
<?php if (empty($asos)): ?>
    <div class="section-card">
        <div class="py-14 text-center">
            <div class="text-5xl mb-3">🏥</div>
            <p class="text-gray-500 font-medium">Nenhum ASO registrado.</p>
            <p class="text-sm text-gray-400 mt-1">Fale com o RH para registrar seu exame médico ocupacional.</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($asos as $aso):
        $iv = $aso['document_status'] === 'vencido';
        $ie = $aso['document_status'] === 'vencendo';
        $topBg    = $iv ? 'from-red-600 to-red-700' : ($ie ? 'from-amber-500 to-amber-600' : 'from-green-600 to-green-700');
        $border   = $iv ? 'border-red-200' : ($ie ? 'border-amber-200' : 'border-green-200');
        $sl       = $iv ? 'Vencido' : ($ie ? 'Vencendo' : 'Válido');
        $dateColor= $iv ? 'text-red-600' : ($ie ? 'text-amber-600' : 'text-green-700');
    ?>
    <div class="section-card overflow-hidden border <?= $border ?>">
        <div class="bg-gradient-to-r <?= $topBg ?> text-white px-5 py-3.5 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                </svg>
                <span class="font-bold text-sm">
                    ASO <?= e($asoTypeLabels[$aso['aso_type']] ?? $aso['aso_type']) ?>
                </span>
            </div>
            <span class="text-xs bg-white/20 border border-white/30 px-3 py-1 rounded-full font-semibold">
                <?= $sl ?>
            </span>
        </div>
        <div class="section-card-body">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="bg-gray-50 rounded-xl p-3">
                    <p class="text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1">Data do Exame</p>
                    <p class="font-bold text-gray-800 text-sm"><?= formatDate($aso['exam_date']) ?></p>
                </div>
                <div class="bg-gray-50 rounded-xl p-3">
                    <p class="text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1">Válido até</p>
                    <p class="font-bold text-sm <?= $dateColor ?>"><?= formatDate($aso['expiration_date']) ?></p>
                </div>
                <div class="bg-gray-50 rounded-xl p-3">
                    <p class="text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1">Resultado</p>
                    <p class="font-bold text-gray-800 text-sm capitalize"><?= e($aso['result_status']) ?></p>
                </div>
                <?php if (!empty($aso['doctor_name'])): ?>
                <div class="bg-gray-50 rounded-xl p-3">
                    <p class="text-xs text-gray-400 font-semibold uppercase tracking-wider mb-1">Médico</p>
                    <p class="font-bold text-gray-800 text-sm"><?= e($aso['doctor_name']) ?></p>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($iv || $ie): ?>
            <div class="mt-4 flex items-center gap-2 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
                <svg class="h-5 w-5 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
                <p class="text-sm text-amber-800 font-medium">
                    Procure o RH para agendar a renovação do exame médico ocupacional.
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<?php endif; // $employee ?>

<!-- Toast -->
<div id="copy-toast"
     class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 bg-gray-900 text-white text-sm
            px-5 py-2.5 rounded-full shadow-xl opacity-0 transition-opacity duration-300 pointer-events-none
            flex items-center gap-2">
    <svg class="h-4 w-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
    </svg>
    Link copiado!
</div>

<script>
function setTab(name) {
    ['certificados','aso'].forEach(t => {
        document.getElementById('tab-' + t).classList.toggle('hidden', t !== name);
        const btn = document.getElementById('tab-btn-' + t);
        btn.className = btn.className
            .replace('active-tab','').replace('inactive-tab','').trim();
        btn.classList.add(t === name ? 'active-tab' : 'inactive-tab');
    });
}

function copyLink(url) {
    navigator.clipboard.writeText(url).then(() => {
        const t = document.getElementById('copy-toast');
        t.style.opacity = '1';
        setTimeout(() => t.style.opacity = '0', 2200);
    });
}

function downloadQR(imgId, filename) {
    const img    = document.getElementById(imgId);
    const canvas = document.createElement('canvas');
    canvas.width  = img.naturalWidth  || 180;
    canvas.height = img.naturalHeight || 180;
    canvas.getContext('2d').drawImage(img, 0, 0);
    const a = document.createElement('a');
    a.href     = canvas.toDataURL('image/png');
    a.download = filename + '.png';
    a.click();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
