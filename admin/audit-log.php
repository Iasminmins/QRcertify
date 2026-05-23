<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$actionFilter = trim($_GET['action'] ?? '');
$dateFrom     = $_GET['from'] ?? '';
$dateTo       = $_GET['to']   ?? '';

$sql    = 'SELECT a.id, a.action, a.target, a.detail, a.ip, a.created_at,
                  u.name AS user_name, u.role AS user_role
           FROM audit_log a
           LEFT JOIN users u ON u.id = a.user_id
           WHERE a.company_id = ? OR (a.user_id IN (
               SELECT id FROM users WHERE company_id = ?
           ))';
$params = [$companyId, $companyId];

if ($actionFilter !== '') {
    $sql .= ' AND a.action LIKE ?';
    $params[] = '%' . $actionFilter . '%';
}
if ($dateFrom !== '') {
    $sql .= ' AND DATE(a.created_at) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $sql .= ' AND DATE(a.created_at) <= ?';
    $params[] = $dateTo;
}
$sql .= ' ORDER BY a.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$allLogs = $stmt->fetchAll();

$pag  = paginate($allLogs, (int)($_GET['page'] ?? 1), 30);
$logs = $pag['items'];

// Ações distintas para o filtro
$actions = $db->prepare(
    'SELECT DISTINCT action FROM audit_log
     WHERE company_id = ? OR user_id IN (SELECT id FROM users WHERE company_id = ?)
     ORDER BY action'
);
$actions->execute([$companyId, $companyId]);
$allActions = $actions->fetchAll(\PDO::FETCH_COLUMN);

$pageTitle = 'Log de Auditoria';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

// Mapa de cores e ícones por categoria de ação
function actionStyle(string $action): array {
    if (str_starts_with($action, 'login.fail'))    return ['bg-red-100 text-red-700',   '✗'];
    if (str_starts_with($action, 'login.success')) return ['bg-green-100 text-green-700','✓'];
    if (str_starts_with($action, 'employee.create')) return ['bg-blue-100 text-blue-700', '+'];
    if (str_starts_with($action, 'employee.update')) return ['bg-amber-100 text-amber-700','↻'];
    if (str_starts_with($action, 'employee.import')) return ['bg-purple-100 text-purple-700','⇪'];
    if (str_starts_with($action, 'cert.create'))   return ['bg-blue-100 text-blue-700', '+'];
    if (str_starts_with($action, 'cert.update'))   return ['bg-amber-100 text-amber-700','↻'];
    if (str_starts_with($action, 'cert.delete'))   return ['bg-red-100 text-red-700',   '✗'];
    if (str_starts_with($action, 'export'))        return ['bg-teal-100 text-teal-700', '↓'];
    if (str_starts_with($action, 'notify'))        return ['bg-indigo-100 text-indigo-700','✉'];
    if (str_starts_with($action, 'company'))       return ['bg-gray-100 text-gray-700', '⚙'];
    if (str_starts_with($action, 'user.password')) return ['bg-orange-100 text-orange-700','🔑'];
    return ['bg-gray-100 text-gray-600', '·'];
}
?>

<div class="mb-6 flex items-center justify-between flex-wrap gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Log de Auditoria</h1>
        <p class="text-gray-500 text-sm mt-1">
            <?= $pag['total'] ?> evento(s) registrado(s)
            <?= $actionFilter ? "· filtrado por <strong>" . e($actionFilter) . "</strong>" : '' ?>
        </p>
    </div>
</div>

<!-- Filtros -->
<form method="GET" class="bg-white rounded-lg shadow-sm border p-4 mb-5">
    <div class="flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Ação</label>
            <select name="action"
                    class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-industrial-blue outline-none">
                <option value="">Todas</option>
                <?php foreach ($allActions as $a): ?>
                    <option value="<?= e($a) ?>" <?= $actionFilter === $a ? 'selected':'' ?>><?= e($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">De</label>
            <input type="date" name="from" value="<?= e($dateFrom) ?>"
                   class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-industrial-blue outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Até</label>
            <input type="date" name="to" value="<?= e($dateTo) ?>"
                   class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-industrial-blue outline-none">
        </div>
        <button type="submit"
                class="px-4 py-2 bg-industrial-blue text-white rounded-md text-sm font-medium hover:bg-blue-800 transition">
            Filtrar
        </button>
        <?php if ($actionFilter || $dateFrom || $dateTo): ?>
            <a href="?" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-600 hover:bg-gray-50 transition">
                Limpar
            </a>
        <?php endif; ?>
    </div>
</form>

<?php if (empty($logs)): ?>
    <div class="bg-white rounded-lg shadow-sm border p-10 text-center text-gray-500">
        <p>Nenhum registro encontrado para os filtros selecionados.</p>
    </div>
<?php else: ?>

<div class="bg-white rounded-lg shadow-sm border overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Data / Hora</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ação</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden md:table-cell">Alvo</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden lg:table-cell">Usuário</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden xl:table-cell">IP</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden xl:table-cell">Detalhe</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($logs as $log):
                    [$badgeCls, $symbol] = actionStyle($log['action']);
                    $detail = '';
                    if ($log['detail']) {
                        $decoded = json_decode($log['detail'], true);
                        if ($decoded) {
                            $parts = [];
                            foreach ($decoded as $k => $v) {
                                if (!is_array($v)) $parts[] = "{$k}: {$v}";
                            }
                            $detail = implode(' · ', $parts);
                        } else {
                            $detail = $log['detail'];
                        }
                    }
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
                        <div class="font-medium text-gray-800"><?= date('d/m/Y', strtotime($log['created_at'])) ?></div>
                        <div class="text-xs text-gray-400"><?= date('H:i:s', strtotime($log['created_at'])) ?></div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-full <?= $badgeCls ?>">
                            <span><?= $symbol ?></span>
                            <?= e($log['action']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-600 text-xs hidden md:table-cell">
                        <?= e($log['target'] ?? '—') ?>
                    </td>
                    <td class="px-4 py-3 hidden lg:table-cell">
                        <?php if ($log['user_name']): ?>
                            <div class="text-xs font-medium text-gray-800"><?= e($log['user_name']) ?></div>
                            <div class="text-xs text-gray-400"><?= e($log['user_role'] ?? '') ?></div>
                        <?php else: ?>
                            <span class="text-xs text-gray-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500 font-mono hidden xl:table-cell">
                        <?= e($log['ip'] ?? '—') ?>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500 hidden xl:table-cell max-w-xs truncate" title="<?= e($detail) ?>">
                        <?= e(mb_strimwidth($detail, 0, 60, '…')) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $pag['html'] ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
