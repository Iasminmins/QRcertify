<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$isSuperAdmin = ($user['role'] === 'superadmin');
$db        = getDB();

// ── Filtros ───────────────────────────────────────────────────────────────────
$fDateFrom  = trim($_GET['from']        ?? '');
$fDateTo    = trim($_GET['to']          ?? '');
$fUser      = trim($_GET['user']        ?? '');
$fModule    = trim($_GET['module']      ?? '');
$fAction    = trim($_GET['action']      ?? '');
$fEntity    = trim($_GET['entity']      ?? '');
$fLogId     = (int)($_GET['detail_id'] ?? 0);

// ── Query principal ───────────────────────────────────────────────────────────
$where  = [];
$params = [];

if (!$isSuperAdmin) {
    $where[]  = '(a.company_id = ? OR a.user_id IN (SELECT id FROM users WHERE company_id = ?))';
    $params[] = $companyId;
    $params[] = $companyId;
}
if ($fDateFrom) { $where[] = 'DATE(a.created_at) >= ?'; $params[] = $fDateFrom; }
if ($fDateTo)   { $where[] = 'DATE(a.created_at) <= ?'; $params[] = $fDateTo; }
if ($fUser)     { $where[] = '(a.user_name LIKE ? OR a.user_email LIKE ?)'; $params[] = "%$fUser%"; $params[] = "%$fUser%"; }
if ($fModule)   { $where[] = 'a.module = ?';       $params[] = $fModule; }
if ($fAction)   { $where[] = 'a.action LIKE ?';    $params[] = "%$fAction%"; }
if ($fEntity)   { $where[] = '(a.entity_name LIKE ? OR a.target LIKE ?)'; $params[] = "%$fEntity%"; $params[] = "%$fEntity%"; }

$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT a.*, u.name AS u_name, u.role AS u_role
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.user_id
        $whereStr
        ORDER BY a.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$allLogs = $stmt->fetchAll();

$pag  = paginate($allLogs, (int)($_GET['page'] ?? 1), 30);
$logs = $pag['items'];

// ── Detalhe de um log específico (modal via GET) ──────────────────────────────
$detailLog = null;
if ($fLogId > 0) {
    $dWhere  = $isSuperAdmin ? '' : 'AND (a.company_id = ? OR a.user_id IN (SELECT id FROM users WHERE company_id = ?))';
    $dParams = $isSuperAdmin ? [$fLogId] : [$fLogId, $companyId, $companyId];
    $ds = $db->prepare("SELECT a.*, u.name AS u_name, u.role AS u_role
                        FROM audit_log a LEFT JOIN users u ON u.id=a.user_id
                        WHERE a.id=? $dWhere LIMIT 1");
    $ds->execute($dParams);
    $detailLog = $ds->fetch();
}

// ── Listas para selects ───────────────────────────────────────────────────────
$modules = ['autenticacao','colaboradores','certificados','aso','relatorios','importacao','qrcode','configuracoes','areas','notificacoes','sistema'];
$moduleLabels = [
    'autenticacao'  => 'Autenticação',
    'colaboradores' => 'Colaboradores',
    'certificados'  => 'Certificados',
    'aso'           => 'ASO',
    'relatorios'    => 'Relatórios',
    'importacao'    => 'Importação',
    'qrcode'        => 'QR Code',
    'configuracoes' => 'Configurações',
    'areas'         => 'Áreas e Setores',
    'notificacoes'  => 'Notificações',
    'sistema'       => 'Sistema',
];

// ── Helpers visuais ───────────────────────────────────────────────────────────
function auditBadge(string $action): array {
    return match(true) {
        str_contains($action, 'create')         => ['bg-green-100 text-green-800 border-green-200',  'Criado'],
        str_contains($action, 'update')         => ['bg-blue-100 text-blue-800 border-blue-200',     'Editado'],
        str_contains($action, 'delete')         => ['bg-red-100 text-red-800 border-red-200',        'Excluído'],
        str_contains($action, 'login.success')  => ['bg-green-100 text-green-800 border-green-200',  'Login'],
        str_contains($action, 'login.fail')     => ['bg-red-100 text-red-800 border-red-200',        'Login falhou'],
        str_contains($action, 'logout')         => ['bg-gray-100 text-gray-700 border-gray-200',     'Logout'],
        str_contains($action, 'export')         => ['bg-purple-100 text-purple-800 border-purple-200','Exportado'],
        str_contains($action, 'download')       => ['bg-indigo-100 text-indigo-800 border-indigo-200','Download'],
        str_contains($action, 'view')           => ['bg-gray-100 text-gray-700 border-gray-200',     'Visualizado'],
        str_contains($action, 'import')         => ['bg-teal-100 text-teal-800 border-teal-200',     'Importado'],
        str_contains($action, 'password')       => ['bg-orange-100 text-orange-800 border-orange-200','Senha'],
        str_contains($action, 'qr')             => ['bg-cyan-100 text-cyan-800 border-cyan-200',     'QR Code'],
        default                                 => ['bg-gray-100 text-gray-600 border-gray-200',     $action],
    };
}

function moduleBadge(string $module, array $labels): string {
    $label = $labels[$module] ?? $module;
    $color = match($module) {
        'autenticacao'  => 'bg-blue-50 text-blue-700',
        'colaboradores' => 'bg-indigo-50 text-indigo-700',
        'certificados'  => 'bg-amber-50 text-amber-700',
        'aso'           => 'bg-teal-50 text-teal-700',
        'relatorios'    => 'bg-purple-50 text-purple-700',
        'importacao'    => 'bg-cyan-50 text-cyan-700',
        'qrcode'        => 'bg-gray-50 text-gray-600',
        'configuracoes' => 'bg-orange-50 text-orange-700',
        'areas'         => 'bg-green-50 text-green-700',
        default         => 'bg-gray-50 text-gray-600',
    };
    return "<span class=\"text-[10px] font-semibold px-2 py-0.5 rounded-full $color\">$label</span>";
}

function parseJsonValues(?string $json): array {
    if (!$json) return [];
    $d = json_decode($json, true);
    return is_array($d) ? $d : [];
}

$hasFilters = $fDateFrom || $fDateTo || $fUser || $fModule || $fAction || $fEntity;

$pageTitle = 'Log de Auditoria';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- Header -->
<div class="mb-5 flex items-center justify-between flex-wrap gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Log de Auditoria</h1>
        <p class="text-gray-500 text-sm mt-0.5">
            <?= number_format($pag['total']) ?> evento<?= $pag['total'] !== 1 ? 's' : '' ?> registrado<?= $pag['total'] !== 1 ? 's' : '' ?>
            <?php if ($hasFilters): ?> · <span class="text-amber-600 font-medium">Filtros ativos</span><?php endif; ?>
        </p>
    </div>
</div>

<!-- Filtros -->
<form method="GET" class="bg-white rounded-lg shadow-sm border p-4 mb-5">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 mb-3">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Data inicial</label>
            <input type="date" name="from" value="<?= e($fDateFrom) ?>" class="form-input text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Data final</label>
            <input type="date" name="to" value="<?= e($fDateTo) ?>" class="form-input text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Usuário</label>
            <input type="text" name="user" value="<?= e($fUser) ?>" placeholder="Nome ou e-mail..." class="form-input text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Módulo</label>
            <select name="module" class="form-input text-sm">
                <option value="">Todos os módulos</option>
                <?php foreach ($modules as $m): ?>
                    <option value="<?= $m ?>" <?= $fModule === $m ? 'selected' : '' ?>><?= $moduleLabels[$m] ?? $m ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Ação</label>
            <input type="text" name="action" value="<?= e($fAction) ?>" placeholder="create, delete, export..." class="form-input text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Item afetado</label>
            <input type="text" name="entity" value="<?= e($fEntity) ?>" placeholder="Nome do colaborador, cert..." class="form-input text-sm">
        </div>
    </div>
    <div class="flex items-center gap-2">
        <button type="submit" class="btn btn-primary text-sm">Filtrar</button>
        <?php if ($hasFilters): ?>
            <a href="<?= BASE_URL ?>/admin/audit-log.php" class="btn btn-secondary text-sm">Limpar filtros</a>
        <?php endif; ?>
    </div>
</form>

<?php if (empty($logs)): ?>
<div class="bg-white rounded-lg shadow-sm border p-12 text-center">
    <svg class="h-10 w-10 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
    </svg>
    <p class="text-gray-500">Nenhum registro encontrado para os filtros selecionados.</p>
</div>
<?php else: ?>

<!-- Tabela -->
<div class="bg-white rounded-lg shadow-sm border overflow-hidden mb-4">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                    <th class="px-4 py-3 text-left">Data / Hora</th>
                    <th class="px-4 py-3 text-left">Usuário</th>
                    <th class="px-4 py-3 text-left">Ação</th>
                    <th class="px-4 py-3 text-left hidden md:table-cell">Módulo</th>
                    <th class="px-4 py-3 text-left hidden lg:table-cell">Item afetado</th>
                    <th class="px-4 py-3 text-left hidden xl:table-cell">Descrição</th>
                    <th class="px-4 py-3 text-left hidden xl:table-cell">IP</th>
                    <th class="px-4 py-3 text-center">Detalhes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($logs as $log):
                [$badgeCls, $badgeLabel] = auditBadge($log['action']);
                $userName  = $log['user_name'] ?? ($log['u_name'] ?? '—');
                $desc      = $log['description'] ?: ($log['detail'] ? json_decode($log['detail'], true)['desc'] ?? '' : '');
                $entityName= $log['entity_name'] ?: ($log['target'] ?? '');
                $module    = $log['module'] ?? '';
                $hasDetail = $log['old_values'] || $log['new_values'] || $log['user_agent'] || $log['description'];
            ?>
            <tr class="hover:bg-gray-50 transition">
                <!-- Data -->
                <td class="px-4 py-3 whitespace-nowrap">
                    <div class="font-medium text-gray-800 text-xs"><?= date('d/m/Y', strtotime($log['created_at'])) ?></div>
                    <div class="text-gray-400 text-xs"><?= date('H:i:s', strtotime($log['created_at'])) ?></div>
                </td>

                <!-- Usuário -->
                <td class="px-4 py-3">
                    <div class="font-medium text-gray-800 text-xs"><?= e($userName) ?></div>
                    <?php if ($log['user_email']): ?>
                    <div class="text-gray-400 text-[10px] truncate max-w-[140px]"><?= e($log['user_email']) ?></div>
                    <?php endif; ?>
                </td>

                <!-- Ação -->
                <td class="px-4 py-3">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border <?= $badgeCls ?>">
                        <?= e($badgeLabel) ?>
                    </span>
                    <div class="text-[10px] text-gray-400 mt-0.5 font-mono"><?= e($log['action']) ?></div>
                </td>

                <!-- Módulo -->
                <td class="px-4 py-3 hidden md:table-cell">
                    <?= $module ? moduleBadge($module, $moduleLabels) : '<span class="text-gray-300 text-xs">—</span>' ?>
                </td>

                <!-- Item afetado -->
                <td class="px-4 py-3 text-xs text-gray-600 hidden lg:table-cell max-w-[180px]">
                    <span class="truncate block" title="<?= e($entityName) ?>"><?= e($entityName ?: '—') ?></span>
                </td>

                <!-- Descrição -->
                <td class="px-4 py-3 text-xs text-gray-500 hidden xl:table-cell max-w-[240px]">
                    <span class="truncate block" title="<?= e($desc) ?>"><?= e(mb_strimwidth($desc, 0, 80, '…')) ?></span>
                </td>

                <!-- IP -->
                <td class="px-4 py-3 text-xs text-gray-400 font-mono hidden xl:table-cell whitespace-nowrap">
                    <?= e($log['ip'] ?? '—') ?>
                </td>

                <!-- Detalhes -->
                <td class="px-4 py-3 text-center">
                    <a href="?<?= http_build_query(array_merge($_GET, ['detail_id' => $log['id']])) ?>#detail-modal"
                       class="inline-flex items-center gap-1 text-xs text-industrial-blue hover:underline font-medium">
                        Ver
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $pag['html'] ?>
<?php endif; ?>

<!-- Modal de detalhes -->
<?php if ($detailLog): ?>
<div id="detail-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <!-- Header do modal -->
        <div class="flex items-center justify-between px-6 py-4 border-b bg-gray-50 rounded-t-xl">
            <div>
                <h2 class="text-base font-bold text-gray-900">Detalhes do Evento</h2>
                <p class="text-xs text-gray-500 mt-0.5">
                    <?= date('d/m/Y \à\s H:i:s', strtotime($detailLog['created_at'])) ?>
                </p>
            </div>
            <a href="?<?= http_build_query(array_diff_key($_GET, ['detail_id' => ''])) ?>"
               class="text-gray-400 hover:text-gray-700 transition">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </a>
        </div>

        <div class="px-6 py-5 space-y-5">

            <!-- Informações principais -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-xs font-semibold text-gray-400 uppercase mb-1">Usuário</p>
                    <p class="text-sm font-medium text-gray-900"><?= e($detailLog['user_name'] ?? $detailLog['u_name'] ?? '—') ?></p>
                    <?php if ($detailLog['user_email']): ?>
                        <p class="text-xs text-gray-500"><?= e($detailLog['user_email']) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <p class="text-xs font-semibold text-gray-400 uppercase mb-1">Ação</p>
                    <?php [$bc, $bl] = auditBadge($detailLog['action']); ?>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold border <?= $bc ?>">
                        <?= e($bl) ?>
                    </span>
                    <p class="text-[10px] text-gray-400 mt-1 font-mono"><?= e($detailLog['action']) ?></p>
                </div>
                <div>
                    <p class="text-xs font-semibold text-gray-400 uppercase mb-1">Módulo</p>
                    <?php if ($detailLog['module']): ?>
                        <?= moduleBadge($detailLog['module'], $moduleLabels) ?>
                    <?php else: ?>
                        <span class="text-gray-400 text-xs">—</span>
                    <?php endif; ?>
                </div>
                <div>
                    <p class="text-xs font-semibold text-gray-400 uppercase mb-1">Item afetado</p>
                    <p class="text-sm text-gray-700"><?= e($detailLog['entity_name'] ?: ($detailLog['target'] ?? '—')) ?></p>
                </div>
            </div>

            <!-- Descrição -->
            <?php if ($detailLog['description']): ?>
            <div class="bg-blue-50 border border-blue-100 rounded-lg px-4 py-3">
                <p class="text-xs font-semibold text-blue-700 mb-1">Descrição</p>
                <p class="text-sm text-blue-900"><?= e($detailLog['description']) ?></p>
            </div>
            <?php endif; ?>

            <!-- Antes e Depois -->
            <?php
            $old = parseJsonValues($detailLog['old_values']);
            $new = parseJsonValues($detailLog['new_values']);
            if (!empty($old) || !empty($new)):
            ?>
            <div>
                <p class="text-xs font-semibold text-gray-400 uppercase mb-3">Alterações</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="text-left px-3 py-2 text-xs font-semibold text-gray-500 border border-gray-200">Campo</th>
                                <th class="text-left px-3 py-2 text-xs font-semibold text-red-600 border border-gray-200">Antes</th>
                                <th class="text-left px-3 py-2 text-xs font-semibold text-green-600 border border-gray-200">Depois</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $allKeys = array_unique(array_merge(array_keys($old), array_keys($new)));
                        foreach ($allKeys as $key):
                            $vOld = $old[$key] ?? '—';
                            $vNew = $new[$key] ?? '—';
                            $changed = $vOld !== $vNew;
                        ?>
                        <tr class="<?= $changed ? 'bg-amber-50' : '' ?>">
                            <td class="px-3 py-2 font-medium text-gray-700 border border-gray-200 text-xs"><?= e($key) ?></td>
                            <td class="px-3 py-2 text-red-700 border border-gray-200 text-xs"><?= e((string)$vOld) ?></td>
                            <td class="px-3 py-2 text-green-700 border border-gray-200 text-xs"><?= e((string)$vNew) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Técnico -->
            <div class="border-t pt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs text-gray-500">
                <div>
                    <p class="font-semibold text-gray-400 uppercase mb-1">Endereço IP</p>
                    <p class="font-mono"><?= e($detailLog['ip'] ?? '—') ?></p>
                </div>
                <div>
                    <p class="font-semibold text-gray-400 uppercase mb-1">Data / Hora</p>
                    <p><?= date('d/m/Y \à\s H:i:s', strtotime($detailLog['created_at'])) ?></p>
                </div>
                <?php if ($detailLog['user_agent']): ?>
                <div class="sm:col-span-2">
                    <p class="font-semibold text-gray-400 uppercase mb-1">Navegador / Dispositivo</p>
                    <p class="break-all"><?= e($detailLog['user_agent']) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
