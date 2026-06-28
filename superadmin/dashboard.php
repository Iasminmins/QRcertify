<?php
require_once __DIR__ . '/../config/config.php';
requireRole('superadmin');

$db = getDB();

$plansCatalog = getPlansCatalog();
$planPrices = array_map(fn(array $p) => $p['price'], $plansCatalog);

$money = static fn($value): string => 'R$ ' . number_format((float) $value, 0, ',', '.');
$planName = static fn(?string $plan): string => $plansCatalog[normalizePlanKey($plan)]['name'] ?? ucfirst((string) $plan);

$subLabels = [
    'trialing' => 'Trial',
    'active' => 'Ativo',
    'pending_checkout' => 'Checkout pendente',
    'overdue' => 'Inadimplente',
    'canceled' => 'Cancelado',
];
$subBadgeClasses = [
    'trialing' => 'bg-blue-100 text-blue-700',
    'active' => 'bg-green-100 text-green-700',
    'pending_checkout' => 'bg-gray-100 text-gray-600',
    'overdue' => 'bg-red-100 text-red-700',
    'canceled' => 'bg-gray-100 text-gray-600',
];

$stats = [
    'companies' => (int) $db->query('SELECT COUNT(*) FROM companies WHERE status = "active"')->fetchColumn(),
    'users' => (int) $db->query('SELECT COUNT(*) FROM users WHERE status = "active"')->fetchColumn(),
    'employees' => (int) $db->query('SELECT COUNT(*) FROM employees WHERE status = "active"')->fetchColumn(),
    'certs' => (int) $db->query('SELECT COUNT(*) FROM certificates')->fetchColumn(),
];

$newThisMonth = (int) $db->query(
    'SELECT COUNT(*) FROM companies
     WHERE MONTH(created_at) = MONTH(CURDATE())
       AND YEAR(created_at) = YEAR(CURDATE())'
)->fetchColumn();

$trialing = (int) $db->query(
    'SELECT COUNT(*) FROM companies
     WHERE status = "active" AND subscription_status = "trialing"'
)->fetchColumn();

$trialsEndingSoon = (int) $db->query(
    'SELECT COUNT(*) FROM companies
     WHERE status = "active"
       AND subscription_status = "trialing"
       AND trial_ends_at IS NOT NULL
       AND trial_ends_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)'
)->fetchColumn();

$overdue = (int) $db->query(
    'SELECT COUNT(*) FROM companies WHERE status = "active" AND subscription_status = "overdue"'
)->fetchColumn();

$canceledThisMonth = (int) $db->query(
    'SELECT COUNT(*) FROM companies
     WHERE subscription_status = "canceled"
       AND MONTH(updated_at) = MONTH(CURDATE())
       AND YEAR(updated_at) = YEAR(CURDATE())'
)->fetchColumn();

$companiesWithoutEmployees = (int) $db->query(
    'SELECT COUNT(*) FROM companies c
     WHERE c.status = "active"
       AND NOT EXISTS (
           SELECT 1 FROM employees e
           WHERE e.company_id = c.id AND e.status = "active"
       )'
)->fetchColumn();

$stmtMrr = $db->query(
    'SELECT plan, COUNT(*) AS qty
     FROM companies
     WHERE status = "active" AND subscription_status = "active"
     GROUP BY plan'
);
$mrr = 0;
$planCounts = [];
$planRevenue = [];
foreach ($stmtMrr->fetchAll() as $row) {
    $plan = normalizePlanKey($row['plan'] ?? 'starter');
    $qty = (int) $row['qty'];
    $revenue = ($planPrices[$plan] ?? 0) * $qty;
    $mrr += $revenue;
    $planCounts[$plan] = $qty;
    $planRevenue[$plan] = $revenue;
}

$stmtMrrPotential = $db->query(
    'SELECT plan, COUNT(*) AS qty
     FROM companies
     WHERE status = "active" AND subscription_status IN ("active","trialing")
     GROUP BY plan'
);
$mrrPotential = 0;
foreach ($stmtMrrPotential->fetchAll() as $row) {
    $plan = normalizePlanKey($row['plan'] ?? 'starter');
    $mrrPotential += ($planPrices[$plan] ?? 0) * (int) $row['qty'];
}

$topCompanies = $db->query(
    'SELECT c.id, c.name, c.plan, c.subscription_status, c.status, c.created_at, c.updated_at,
            COUNT(DISTINCT e.id) AS emp_count,
            COUNT(cert.id) AS cert_count
     FROM companies c
     LEFT JOIN employees e ON e.company_id = c.id
     LEFT JOIN certificates cert ON cert.employee_id = e.id
     GROUP BY c.id
     ORDER BY c.created_at DESC
     LIMIT 8'
)->fetchAll();

$attentionCompanies = $db->query(
    'SELECT name, plan, subscription_status, trial_ends_at, overdue_since, updated_at
     FROM companies
     WHERE status = "active"
       AND (
           subscription_status = "overdue"
           OR (subscription_status = "trialing"
               AND trial_ends_at IS NOT NULL
               AND trial_ends_at <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))
       )
     ORDER BY
       CASE subscription_status WHEN "overdue" THEN 0 ELSE 1 END,
       COALESCE(trial_ends_at, updated_at) ASC
     LIMIT 5'
)->fetchAll();

$topUsageCompanies = $db->query(
    'SELECT c.name, c.plan,
            COUNT(DISTINCT e.id) AS emp_count,
            COUNT(cert.id) AS cert_count
     FROM companies c
     LEFT JOIN employees e ON e.company_id = c.id AND e.status = "active"
     LEFT JOIN certificates cert ON cert.employee_id = e.id
     WHERE c.status = "active"
     GROUP BY c.id
     ORDER BY cert_count DESC, emp_count DESC, c.name ASC
     LIMIT 5'
)->fetchAll();

$monthlyRows = $db->query(
    'SELECT DATE_FORMAT(created_at, "%Y-%m") AS month_key,
            COUNT(*) AS total
     FROM companies
     WHERE created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), "%Y-%m-01")
     GROUP BY month_key
     ORDER BY month_key ASC'
)->fetchAll();
$monthlyBuckets = [];
$currentMonth = new DateTimeImmutable('first day of this month');
for ($i = 5; $i >= 0; $i--) {
    $month = $currentMonth->modify("-{$i} months");
    $monthlyBuckets[$month->format('Y-m')] = [
        'label' => $month->format('m/Y'),
        'total' => 0,
    ];
}
foreach ($monthlyRows as $row) {
    if (isset($monthlyBuckets[$row['month_key']])) {
        $monthlyBuckets[$row['month_key']]['total'] = (int) $row['total'];
    }
}
$monthlyLabels = array_column($monthlyBuckets, 'label');
$monthlyCompanies = array_column($monthlyBuckets, 'total');

$lastUpdatedAt = date('d/m/Y H:i');
$pageTitle = 'Dashboard Plataforma';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Painel da Plataforma</h1>
        <p class="page-subtitle">Monitoramento geral do SaaS QRCertify</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs text-gray-400">Atualizado em <?= e($lastUpdatedAt) ?></span>
        <a href="<?= BASE_URL ?>/superadmin/companies.php" class="btn btn-primary">Ver empresas</a>
    </div>
</div>

<?php renderFlashes(); ?>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">MRR Real</p>
        <p class="text-2xl font-extrabold text-green-600"><?= $money($mrr) ?></p>
        <p class="text-xs text-gray-400 mt-1">assinaturas ativas</p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">MRR Potencial</p>
        <p class="text-2xl font-extrabold text-blue-600"><?= $money($mrrPotential) ?></p>
        <p class="text-xs text-gray-400 mt-1">ativas + trials</p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Empresas</p>
        <p class="text-2xl font-extrabold text-gray-900"><?= $stats['companies'] ?></p>
        <p class="text-xs text-green-600 mt-1">+<?= $newThisMonth ?> este mes</p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Risco de Receita</p>
        <p class="text-2xl font-extrabold text-red-500"><?= $overdue ?></p>
        <p class="text-xs text-gray-400 mt-1">empresas inadimplentes</p>
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Usuarios</p>
        <p class="text-2xl font-extrabold text-gray-900"><?= $stats['users'] ?></p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Funcionarios</p>
        <p class="text-2xl font-extrabold text-gray-900"><?= $stats['employees'] ?></p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Certificados</p>
        <p class="text-2xl font-extrabold text-gray-900"><?= $stats['certs'] ?></p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Trials</p>
        <p class="text-2xl font-extrabold text-amber-500"><?= $trialing ?></p>
        <p class="text-xs text-gray-400 mt-1"><?= $trialsEndingSoon ?> vencem em 7 dias</p>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
    <div class="card p-6 xl:col-span-2">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <h2 class="font-bold text-gray-900">Atencao operacional</h2>
                <p class="text-sm text-gray-400">Itens que merecem acompanhamento comercial ou de suporte.</p>
            </div>
            <a href="<?= BASE_URL ?>/superadmin/companies.php" class="btn btn-secondary btn-sm">Gerenciar</a>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
            <div class="rounded-lg border border-red-100 bg-red-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-red-700">Cobranca</p>
                <p class="text-2xl font-extrabold text-red-600 mt-1"><?= $overdue ?></p>
                <p class="text-xs text-red-700 mt-1">pendentes de regularizacao</p>
            </div>
            <div class="rounded-lg border border-amber-100 bg-amber-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Trial</p>
                <p class="text-2xl font-extrabold text-amber-600 mt-1"><?= $trialsEndingSoon ?></p>
                <p class="text-xs text-amber-700 mt-1">terminam nos proximos 7 dias</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">Ativacao</p>
                <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= $companiesWithoutEmployees ?></p>
                <p class="text-xs text-slate-500 mt-1">sem funcionarios ativos</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-600">Cancelamentos</p>
                <p class="text-2xl font-extrabold text-gray-900 mt-1"><?= $canceledThisMonth ?></p>
                <p class="text-xs text-gray-500 mt-1">registrados este mes</p>
            </div>
        </div>
    </div>

    <div class="card p-6">
        <h2 class="font-bold text-gray-900 mb-4">Acoes rapidas</h2>
        <div class="grid grid-cols-2 gap-3">
            <a href="<?= BASE_URL ?>/superadmin/companies.php" class="rounded-lg border border-gray-200 p-3 hover:border-industrial-blue hover:bg-blue-50 transition">
                <span class="block text-sm font-semibold text-gray-900">Nova empresa</span>
                <span class="block text-xs text-gray-400 mt-1">cadastro e contrato</span>
            </a>
            <a href="<?= BASE_URL ?>/superadmin/companies.php" class="rounded-lg border border-gray-200 p-3 hover:border-industrial-blue hover:bg-blue-50 transition">
                <span class="block text-sm font-semibold text-gray-900">Trials</span>
                <span class="block text-xs text-gray-400 mt-1">acompanhar conversao</span>
            </a>
            <a href="<?= BASE_URL ?>/superadmin/companies.php" class="rounded-lg border border-gray-200 p-3 hover:border-industrial-blue hover:bg-blue-50 transition">
                <span class="block text-sm font-semibold text-gray-900">Inadimplentes</span>
                <span class="block text-xs text-gray-400 mt-1">cobranca pendente</span>
            </a>
            <a href="<?= BASE_URL ?>/superadmin/companies.php" class="rounded-lg border border-gray-200 p-3 hover:border-industrial-blue hover:bg-blue-50 transition">
                <span class="block text-sm font-semibold text-gray-900">Planos</span>
                <span class="block text-xs text-gray-400 mt-1">ajustar assinatura</span>
            </a>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
    <div class="card p-6 xl:col-span-2">
        <div class="flex items-center justify-between gap-3 mb-4">
            <h2 class="font-bold text-gray-900">Novas empresas por mes</h2>
            <span class="text-xs text-gray-400">ultimos 6 meses</span>
        </div>
        <div class="h-64">
            <canvas id="companiesChart"></canvas>
        </div>
    </div>

    <div class="card p-6">
        <h2 class="font-bold text-gray-900 mb-4">Empresas que exigem atencao</h2>
        <div class="space-y-3">
            <?php foreach ($attentionCompanies as $co): ?>
                <?php
                    $status = $co['subscription_status'] ?? 'pending_checkout';
                    $badgeClass = $subBadgeClasses[$status] ?? 'bg-gray-100 text-gray-600';
                    $dateText = $status === 'overdue'
                        ? (!empty($co['overdue_since']) ? 'desde ' . date('d/m/Y', strtotime($co['overdue_since'])) : 'regularizar cobranca')
                        : (!empty($co['trial_ends_at']) ? 'vence em ' . date('d/m/Y', strtotime($co['trial_ends_at'])) : 'trial sem data');
                ?>
                <div class="flex items-center justify-between gap-3 border-b border-gray-100 pb-3 last:border-0 last:pb-0">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-900 truncate"><?= e($co['name']) ?></p>
                        <p class="text-xs text-gray-400"><?= e($planName($co['plan'] ?? 'starter')) ?> · <?= e($dateText) ?></p>
                    </div>
                    <span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $badgeClass ?>">
                        <?= e($subLabels[$status] ?? $status) ?>
                    </span>
                </div>
            <?php endforeach; ?>
            <?php if (empty($attentionCompanies)): ?>
                <p class="text-sm text-gray-400">Nenhuma empresa em alerta agora.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
    <div class="card p-6">
        <h2 class="font-bold text-gray-900 mb-4">Distribuicao por plano</h2>
        <div class="space-y-4">
            <?php foreach ($plansCatalog as $key => $plan): ?>
                <?php
                    $qty = $planCounts[$key] ?? 0;
                    $revenue = $planRevenue[$key] ?? 0;
                    $pct = $stats['companies'] > 0 ? max(3, round($qty / $stats['companies'] * 100)) : 0;
                ?>
                <div>
                    <div class="flex justify-between gap-3 text-sm mb-1">
                        <span class="font-medium text-gray-700"><?= e($plan['name']) ?></span>
                        <span class="text-gray-500"><?= $qty ?> empresa(s) · <?= $money($revenue) ?>/mes</span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-2">
                        <div class="h-2 rounded-full bg-industrial-blue" style="width: <?= $pct ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="text-xs text-gray-400 mt-4">Considera apenas assinaturas ativas para MRR real.</p>
    </div>

    <div class="card p-6">
        <div class="flex items-center justify-between gap-3 mb-4">
            <h2 class="font-bold text-gray-900">Top uso da plataforma</h2>
            <span class="text-xs text-gray-400">certificados + funcionarios</span>
        </div>
        <div class="space-y-3">
            <?php foreach ($topUsageCompanies as $co): ?>
                <div class="flex items-center justify-between gap-4 border-b border-gray-100 pb-3 last:border-0 last:pb-0">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-900 truncate"><?= e($co['name']) ?></p>
                        <p class="text-xs text-gray-400"><?= e($planName($co['plan'] ?? 'starter')) ?></p>
                    </div>
                    <div class="text-right flex-shrink-0">
                        <p class="text-sm font-bold text-gray-900"><?= (int) $co['cert_count'] ?> certs</p>
                        <p class="text-xs text-gray-400"><?= (int) $co['emp_count'] ?> funcs</p>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($topUsageCompanies)): ?>
                <p class="text-sm text-gray-400">Ainda nao ha uso registrado.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card p-6 mb-6">
    <div class="flex items-center justify-between gap-3 mb-4">
        <h2 class="font-bold text-gray-900">Ultimas empresas cadastradas</h2>
        <a href="<?= BASE_URL ?>/superadmin/companies.php" class="text-sm text-industrial-blue font-medium hover:underline">Ver todas</a>
    </div>
    <div class="divide-y divide-gray-100">
        <?php foreach ($topCompanies as $co): ?>
            <?php
                $status = $co['subscription_status'] ?? 'pending_checkout';
                $badgeClass = $subBadgeClasses[$status] ?? 'bg-gray-100 text-gray-600';
            ?>
            <div class="py-3 flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-medium text-sm text-gray-900 truncate"><?= e($co['name']) ?></p>
                    <p class="text-xs text-gray-400">
                        <?= e($planName($co['plan'] ?? 'starter')) ?> ·
                        <?= (int) $co['emp_count'] ?> funcs ·
                        <?= (int) $co['cert_count'] ?> certs ·
                        <?= date('d/m/Y', strtotime($co['created_at'])) ?>
                    </p>
                </div>
                <span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $badgeClass ?>">
                    <?= e($subLabels[$status] ?? $status) ?>
                </span>
            </div>
        <?php endforeach; ?>
        <?php if (empty($topCompanies)): ?>
            <p class="py-6 text-center text-gray-400">Nenhuma empresa cadastrada.</p>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const companiesCanvas = document.getElementById('companiesChart');
if (companiesCanvas && window.Chart) {
    new Chart(companiesCanvas, {
        type: 'bar',
        data: {
            labels: <?= json_encode($monthlyLabels, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                label: 'Novas empresas',
                data: <?= json_encode($monthlyCompanies) ?>,
                backgroundColor: '#1e3a8a',
                borderRadius: 6,
                maxBarThickness: 42
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { displayColors: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, precision: 0 },
                    grid: { color: '#f1f5f9' }
                },
                x: { grid: { display: false } }
            }
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
