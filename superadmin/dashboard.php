<?php
require_once __DIR__ . '/../config/config.php';
requireRole('superadmin');

$db = getDB();

$planPrices = array_map(fn(array $p) => $p['price'], getPlansCatalog());

// ── Stats gerais ──────────────────────────────────────────────
$stats = [
    'companies' => (int)$db->query('SELECT COUNT(*) FROM companies WHERE status = "active"')->fetchColumn(),
    'users'     => (int)$db->query('SELECT COUNT(*) FROM users WHERE status = "active"')->fetchColumn(),
    'employees' => (int)$db->query('SELECT COUNT(*) FROM employees WHERE status = "active"')->fetchColumn(),
    'certs'     => (int)$db->query('SELECT COUNT(*) FROM certificates')->fetchColumn(),
];

// ── MRR (receita mensal recorrente) ──────────────────────────
$stmtMrr = $db->query(
    'SELECT plan, COUNT(*) AS qty
     FROM companies
     WHERE status = "active" AND subscription_status = "active"
     GROUP BY plan'
);
$mrr = 0;
$planCounts = [];
foreach ($stmtMrr->fetchAll() as $row) {
    $price = $planPrices[$row['plan']] ?? 0;
    $mrr  += $price * $row['qty'];
    $planCounts[$row['plan']] = $row['qty'];
}

// MRR potencial (inclui trialing)
$stmtMrrAll = $db->query(
    'SELECT plan, COUNT(*) AS qty
     FROM companies
     WHERE status = "active" AND subscription_status IN ("active","trialing")
     GROUP BY plan'
);
$mrrPotential = 0;
foreach ($stmtMrrAll->fetchAll() as $row) {
    $mrrPotential += ($planPrices[$row['plan']] ?? 0) * $row['qty'];
}

// ── Trials a vencer nos próximos 7 dias ──────────────────────
$trialing = (int)$db->query(
    'SELECT COUNT(*) FROM companies
     WHERE subscription_status = "trialing" AND status = "active"'
)->fetchColumn();

// ── Inadimplentes ─────────────────────────────────────────────
$overdue = (int)$db->query(
    'SELECT COUNT(*) FROM companies WHERE subscription_status = "overdue"'
)->fetchColumn();

// ── Cancelados no mês ─────────────────────────────────────────
$canceled = (int)$db->query(
    'SELECT COUNT(*) FROM companies
     WHERE subscription_status = "canceled"
       AND MONTH(updated_at) = MONTH(NOW())
       AND YEAR(updated_at) = YEAR(NOW())'
)->fetchColumn();

// ── Novas empresas no mês ─────────────────────────────────────
$newThisMonth = (int)$db->query(
    'SELECT COUNT(*) FROM companies
     WHERE MONTH(created_at) = MONTH(NOW())
       AND YEAR(created_at)  = YEAR(NOW())'
)->fetchColumn();

// ── Lista das empresas com mais detalhes ──────────────────────
$topCompanies = $db->query(
    'SELECT c.name, c.plan, c.subscription_status, c.created_at,
            COUNT(DISTINCT e.id) AS emp_count,
            COUNT(cert.id)       AS cert_count
     FROM companies c
     LEFT JOIN employees e    ON e.company_id   = c.id
     LEFT JOIN certificates cert ON cert.employee_id = e.id
     GROUP BY c.id ORDER BY c.created_at DESC LIMIT 10'
)->fetchAll();

$chartLabels = json_encode(array_column($topCompanies, 'name'));
$chartEmps   = json_encode(array_column($topCompanies, 'emp_count'));
$chartCerts  = json_encode(array_column($topCompanies, 'cert_count'));

$pageTitle = 'Dashboard Plataforma';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Painel da Plataforma</h1>
        <p class="page-subtitle">Monitoramento geral do SaaS QRCertify</p>
    </div>
    <a href="<?= BASE_URL ?>/superadmin/companies.php" class="btn btn-primary">Ver todas as empresas</a>
</div>

<!-- ── Cards de receita ───────────────────────────────────────── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">MRR Real</p>
        <p class="text-2xl font-extrabold text-green-600">R$ <?= number_format($mrr, 0, ',', '.') ?></p>
        <p class="text-xs text-gray-400 mt-1">assinaturas ativas</p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">MRR Potencial</p>
        <p class="text-2xl font-extrabold text-blue-600">R$ <?= number_format($mrrPotential, 0, ',', '.') ?></p>
        <p class="text-xs text-gray-400 mt-1">ativas + trials</p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Em Trial</p>
        <p class="text-2xl font-extrabold text-amber-500"><?= $trialing ?></p>
        <p class="text-xs text-gray-400 mt-1">empresas no período grátis</p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Inadimplentes</p>
        <p class="text-2xl font-extrabold text-red-500"><?= $overdue ?></p>
        <p class="text-xs text-gray-400 mt-1">cobrança pendente</p>
    </div>
</div>

<!-- ── Cards gerais ───────────────────────────────────────────── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Empresas</p>
        <p class="text-2xl font-extrabold text-gray-900"><?= $stats['companies'] ?></p>
        <p class="text-xs text-green-600 mt-1">+<?= $newThisMonth ?> este mês</p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Usuários</p>
        <p class="text-2xl font-extrabold text-gray-900"><?= $stats['users'] ?></p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Funcionários</p>
        <p class="text-2xl font-extrabold text-gray-900"><?= $stats['employees'] ?></p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Certificados</p>
        <p class="text-2xl font-extrabold text-gray-900"><?= $stats['certs'] ?></p>
    </div>
</div>

<?php if ($overdue > 0): ?>
<div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4 flex items-center gap-3">
    <svg width="20" height="20" fill="none" stroke="#dc2626" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <p class="text-sm text-red-800 font-medium"><?= $overdue ?> empresa(s) inadimplente(s). <a href="<?= BASE_URL ?>/superadmin/companies.php" class="underline">Ver detalhes →</a></p>
</div>
<?php endif; ?>

<!-- ── Distribuição por plano ─────────────────────────────────── -->
<div class="grid lg:grid-cols-2 gap-6 mb-6">
    <div class="card p-6">
        <h2 class="font-bold text-gray-900 mb-4">Distribuição por plano</h2>
        <div class="space-y-3">
            <?php foreach ($planPrices as $key => $price): ?>
            <?php $qty = $planCounts[$key] ?? 0; $pct = $stats['companies'] > 0 ? round($qty / $stats['companies'] * 100) : 0; ?>
            <div>
                <div class="flex justify-between text-sm mb-1">
                    <span class="font-medium text-gray-700"><?= ucfirst($key) ?></span>
                    <span class="text-gray-500"><?= $qty ?> empresa(s) · R$ <?= number_format($price * $qty, 0, ',', '.') ?>/mês</span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-2">
                    <div class="h-2 rounded-full bg-industrial-blue" style="width:<?= $pct ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Últimas empresas -->
    <div class="card p-6">
        <h2 class="font-bold text-gray-900 mb-4">Últimas empresas cadastradas</h2>
        <div class="space-y-3">
            <?php foreach (array_slice($topCompanies, 0, 5) as $co):
                $subColors = ['trialing'=>'blue','active'=>'green','overdue'=>'red','canceled'=>'gray'];
                $subLabels = ['trialing'=>'Trial','active'=>'Ativo','overdue'=>'Inadimplente','canceled'=>'Cancelado'];
                $sc = $subColors[$co['subscription_status']] ?? 'gray';
            ?>
            <div class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                <div>
                    <p class="font-medium text-sm text-gray-900"><?= e($co['name']) ?></p>
                    <p class="text-xs text-gray-400"><?= ucfirst($co['plan'] ?? 'starter') ?> · <?= $co['emp_count'] ?> funcs</p>
                </div>
                <span class="text-xs px-2 py-0.5 rounded-full font-medium
                    <?= $sc==='green'?'bg-green-100 text-green-700':($sc==='blue'?'bg-blue-100 text-blue-700':($sc==='red'?'bg-red-100 text-red-700':'bg-gray-100 text-gray-600')) ?>">
                    <?= $subLabels[$co['subscription_status']] ?? $co['subscription_status'] ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <a href="<?= BASE_URL ?>/superadmin/companies.php" class="text-sm text-industrial-blue font-medium hover:underline mt-3 block">Ver todas →</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1"/>
        </svg>
        Gerenciar Empresas
    </a>
</div>

<?php renderFlashes(); ?>

<!-- 4 stat cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <?php
    $cards = [
        ['label'=>'Empresas Ativas',       'value'=>$stats['companies'], 'color'=>'blue',   'icon'=>'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
        ['label'=>'Usuários Ativos',        'value'=>$stats['users'],      'color'=>'violet', 'icon'=>'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
        ['label'=>'Colaboradores',          'value'=>$stats['employees'],  'color'=>'green',  'icon'=>'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
        ['label'=>'Certificados Emitidos',  'value'=>$stats['certs'],      'color'=>'amber',  'icon'=>'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
    ];
    $colorMap = [
        'blue'   => ['bg'=>'#eff6ff','icon_bg'=>'#dbeafe','icon_color'=>'#1d4ed8'],
        'violet' => ['bg'=>'#f5f3ff','icon_bg'=>'#ede9fe','icon_color'=>'#7c3aed'],
        'green'  => ['bg'=>'#f0fdf4','icon_bg'=>'#dcfce7','icon_color'=>'#16a34a'],
        'amber'  => ['bg'=>'#fffbeb','icon_bg'=>'#fef9c3','icon_color'=>'#b45309'],
    ];
    foreach ($cards as $c):
        $cm = $colorMap[$c['color']];
    ?>
    <div class="stat-card">
        <div style="display:flex;align-items:center;justify-content:space-between">
            <div>
                <p style="font-size:.8rem;color:#64748b;margin-bottom:.25rem"><?= $c['label'] ?></p>
                <p style="font-size:2rem;font-weight:700;color:#0f172a;line-height:1"><?= $c['value'] ?></p>
            </div>
            <div style="width:3rem;height:3rem;border-radius:.5rem;background:<?= $cm['icon_bg'] ?>;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg style="width:1.5rem;height:1.5rem;color:<?= $cm['icon_color'] ?>"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="<?= $c['icon'] ?>"/>
                </svg>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Gráfico + tabela em grid -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">

    <!-- Gráfico: colaboradores + certs por empresa -->
    <div class="section-card">
        <div class="section-card-header">
            <h2 class="section-card-title">Colaboradores por Empresa</h2>
        </div>
        <div class="section-card-body">
            <div style="height:260px;position:relative">
                <canvas id="chartCompanies"></canvas>
            </div>
        </div>
    </div>

    <!-- Lista das empresas -->
    <div class="section-card">
        <div class="section-card-header">
            <h2 class="section-card-title">Empresas Registradas</h2>
            <a href="<?= BASE_URL ?>/superadmin/companies.php" class="btn btn-secondary btn-sm">Ver todas</a>
        </div>
        <div class="divide-y divide-gray-100">
            <?php foreach ($topCompanies as $c):
                $badge = getStatusBadge($c['status']);
            ?>
            <div class="px-5 py-3.5 flex items-center justify-between hover:bg-gray-50 transition">
                <div class="flex items-center gap-3">
                    <div class="h-8 w-8 rounded-lg bg-industrial-blue/10 flex items-center justify-center flex-shrink-0">
                        <svg class="h-4 w-4 text-industrial-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1"/>
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-gray-800"><?= e($c['name']) ?></div>
                        <div class="text-xs text-gray-400"><?= (int)$c['emp_count'] ?> colaboradores · <?= (int)$c['cert_count'] ?> certs</div>
                    </div>
                </div>
                <span class="badge badge-<?= $c['status'] === 'active' ? 'active' : 'inactive' ?>">
                    <?= e($badge['label']) ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($topCompanies)): ?>
                <p class="p-6 text-center text-gray-400">Nenhuma empresa cadastrada.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const canvas = document.getElementById('chartCompanies');
if (canvas) {
    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: <?= $chartLabels ?>,
            datasets: [
                {
                    label: 'Colaboradores',
                    data: <?= $chartEmps ?>,
                    backgroundColor: '#1e3a8a',
                    borderRadius: 6,
                },
                {
                    label: 'Certificados',
                    data: <?= $chartCerts ?>,
                    backgroundColor: '#facc15',
                    borderRadius: 6,
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: '#f1f5f9' } },
                x: { grid: { display: false } }
            }
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
