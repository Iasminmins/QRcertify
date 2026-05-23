<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

// Funcionários SEM nenhum certificado
$stmtNone = $db->prepare(
    'SELECT e.id, e.name, e.email, e.cpf, e.role, e.phone, e.status, e.created_at
     FROM employees e
     WHERE e.company_id = ?
       AND e.status = "active"
       AND NOT EXISTS (SELECT 1 FROM certificates c WHERE c.employee_id = e.id)
     ORDER BY e.name ASC'
);
$stmtNone->execute([$companyId]);
$noCerts = $stmtNone->fetchAll();

// Funcionários com TODOS os certificados expirados (nenhum válido ou expirando)
$stmtAllExp = $db->prepare(
    'SELECT e.id, e.name, e.email, e.cpf, e.role, e.status,
            COUNT(c.id) AS total,
            SUM(c.status = "expired") AS expired_count,
            MAX(c.expiry_date) AS last_expiry
     FROM employees e
     JOIN certificates c ON c.employee_id = e.id
     WHERE e.company_id = ?
       AND e.status = "active"
     GROUP BY e.id
     HAVING total > 0 AND total = expired_count
     ORDER BY e.name ASC'
);
$stmtAllExp->execute([$companyId]);
$allExpired = $stmtAllExp->fetchAll();

// Funcionários com pelo menos 1 certificado expirando (mas não todos expirados)
$stmtExpiring = $db->prepare(
    'SELECT e.id, e.name, e.email, e.role,
            GROUP_CONCAT(DISTINCT c.type ORDER BY c.expiry_date SEPARATOR ", ") AS types,
            MIN(c.expiry_date) AS nearest_expiry
     FROM employees e
     JOIN certificates c ON c.employee_id = e.id
     WHERE e.company_id = ?
       AND e.status = "active"
       AND c.status = "expiring"
     GROUP BY e.id
     ORDER BY nearest_expiry ASC'
);
$stmtExpiring->execute([$companyId]);
$expiring = $stmtExpiring->fetchAll();

$pageTitle = 'Relatório de Conformidade';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="mb-6 flex items-start justify-between flex-wrap gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Relatório de Conformidade</h1>
        <p class="text-gray-500 text-sm mt-1">Funcionários ativos sem certificação válida ou em situação irregular.</p>
    </div>
    <button onclick="window.print()"
            class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-md text-sm font-medium transition print:hidden">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
        </svg>
        Imprimir / Salvar PDF
    </button>
</div>

<!-- Cards resumo -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
    <div class="bg-white rounded-lg border shadow-sm p-5">
        <p class="text-sm text-gray-500">Sem nenhum certificado</p>
        <p class="text-3xl font-bold text-gray-800 mt-1"><?= count($noCerts) ?></p>
        <p class="text-xs text-gray-400 mt-1">funcionário(s) ativos</p>
    </div>
    <div class="bg-white rounded-lg border shadow-sm p-5">
        <p class="text-sm text-gray-500">Todos os certs. expirados</p>
        <p class="text-3xl font-bold text-red-600 mt-1"><?= count($allExpired) ?></p>
        <p class="text-xs text-gray-400 mt-1">nenhum válido ou expirando</p>
    </div>
    <div class="bg-white rounded-lg border shadow-sm p-5">
        <p class="text-sm text-gray-500">Com cert. expirando</p>
        <p class="text-3xl font-bold text-amber-600 mt-1"><?= count($expiring) ?></p>
        <p class="text-xs text-gray-400 mt-1">atenção nos próximos 30 dias</p>
    </div>
</div>

<?php
function empTable(array $rows, string $extra = ''): void { ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Funcionário</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase hidden md:table-cell">Cargo</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase hidden lg:table-cell">E-mail</th>
                    <?php if ($extra): ?>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase"><?= $extra ?></th>
                    <?php endif; ?>
                    <th class="px-4 py-2.5 text-right text-xs font-medium text-gray-500 uppercase print:hidden">Ação</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($rows as $r): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-900"><?= e($r['name']) ?></div>
                        <div class="text-xs text-gray-400 font-mono">
                            <?= e(preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $r['cpf'] ?? '')) ?>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-600 hidden md:table-cell"><?= e($r['role']) ?></td>
                    <td class="px-4 py-3 text-gray-600 hidden lg:table-cell"><?= e($r['email']) ?></td>
                    <?php if ($extra): ?>
                        <td class="px-4 py-3 text-xs text-gray-600">
                            <?php if (isset($r['last_expiry'])): ?>
                                <span class="text-red-600 font-medium">Último: <?= date('d/m/Y', strtotime($r['last_expiry'])) ?></span>
                                (<?= (int)($r['expired_count'] ?? 0) ?> expirado(s))
                            <?php elseif (isset($r['nearest_expiry'])): ?>
                                <span class="text-amber-600 font-medium"><?= date('d/m/Y', strtotime($r['nearest_expiry'])) ?></span>
                                · <?= e($r['types'] ?? '') ?>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td class="px-4 py-3 text-right print:hidden">
                        <a href="<?= BASE_URL ?>/admin/employee-details.php?id=<?= (int)$r['id'] ?>"
                           class="text-xs text-industrial-blue hover:underline">Ver detalhes</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php } ?>

<!-- Seção 1: Sem nenhum certificado -->
<div class="bg-white rounded-lg shadow-sm border mb-6">
    <div class="p-5 border-b flex items-center justify-between">
        <h2 class="font-semibold text-gray-900 flex items-center gap-2">
            <span class="h-2 w-2 rounded-full bg-gray-400 inline-block"></span>
            Sem nenhum certificado cadastrado
            <span class="ml-1 text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full"><?= count($noCerts) ?></span>
        </h2>
    </div>
    <?php if (empty($noCerts)): ?>
        <p class="p-5 text-sm text-gray-500 text-center">Todos os funcionários possuem ao menos um certificado. ✓</p>
    <?php else: ?>
        <?php empTable($noCerts); ?>
    <?php endif; ?>
</div>

<!-- Seção 2: Todos expirados -->
<div class="bg-white rounded-lg shadow-sm border mb-6">
    <div class="p-5 border-b flex items-center justify-between">
        <h2 class="font-semibold text-gray-900 flex items-center gap-2">
            <span class="h-2 w-2 rounded-full bg-red-500 inline-block"></span>
            Todos os certificados expirados
            <span class="ml-1 text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full"><?= count($allExpired) ?></span>
        </h2>
    </div>
    <?php if (empty($allExpired)): ?>
        <p class="p-5 text-sm text-gray-500 text-center">Nenhum funcionário nesta situação. ✓</p>
    <?php else: ?>
        <?php empTable($allExpired, 'Situação'); ?>
    <?php endif; ?>
</div>

<!-- Seção 3: Expirando em breve -->
<div class="bg-white rounded-lg shadow-sm border mb-6">
    <div class="p-5 border-b flex items-center justify-between">
        <h2 class="font-semibold text-gray-900 flex items-center gap-2">
            <span class="h-2 w-2 rounded-full bg-amber-400 inline-block"></span>
            Certificados expirando em breve
            <span class="ml-1 text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full"><?= count($expiring) ?></span>
        </h2>
        <?php if (!empty($expiring)): ?>
            <a href="<?= BASE_URL ?>/admin/notify-expiring.php"
               class="text-xs text-industrial-blue hover:underline print:hidden">Enviar notificações →</a>
        <?php endif; ?>
    </div>
    <?php if (empty($expiring)): ?>
        <p class="p-5 text-sm text-gray-500 text-center">Nenhum certificado expirando nos próximos 30 dias. ✓</p>
    <?php else: ?>
        <?php empTable($expiring, 'Vencimento mais próximo'); ?>
    <?php endif; ?>
</div>

<style>
@media print {
    .print\:hidden { display: none !important; }
    #sidebar, nav { display: none !important; }
    body { font-size: 12px; }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
