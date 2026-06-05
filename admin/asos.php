<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

// Atualiza document_status automaticamente
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

// Filtros
$search       = trim($_GET['search']   ?? '');
$statusFilter = $_GET['status']        ?? '';
$tipoFilter   = $_GET['tipo']          ?? '';
$empFilter    = $_GET['emp']           ?? '';

// Query principal
$sql    = 'SELECT a.*, e.id AS emp_id, e.name AS emp_name, e.role AS emp_role
           FROM employee_asos a
           JOIN employees e ON e.id = a.employee_id
           WHERE e.company_id = ? AND e.status = "active"';
$params = [$companyId];

if ($search !== '') {
    $sql .= ' AND (e.name LIKE ? OR a.aso_type LIKE ? OR a.doctor_name LIKE ?)';
    $like      = '%' . $search . '%';
    $params[]  = $like; $params[] = $like; $params[] = $like;
}
if (in_array($statusFilter, ['valido','vencendo','vencido'], true)) {
    $sql .= ' AND a.document_status = ?';
    $params[] = $statusFilter;
}
if ($tipoFilter !== '') {
    $sql .= ' AND a.aso_type = ?';
    $params[] = $tipoFilter;
}
if ($empFilter !== '') {
    $sql .= ' AND e.id = ?';
    $params[] = (int)$empFilter;
}
$sql .= ' ORDER BY a.expiration_date ASC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$allAsos = $stmt->fetchAll();

$pag  = paginate($allAsos, (int)($_GET['page'] ?? 1), 25);
$asos = $pag['items'];

// Funcionários para filtro
$stmtEmps = $db->prepare(
    'SELECT DISTINCT e.id, e.name FROM employees e
     JOIN employee_asos a ON a.employee_id = e.id
     WHERE e.company_id = ? AND e.status = "active" ORDER BY e.name'
);
$stmtEmps->execute([$companyId]);
$empList = $stmtEmps->fetchAll();

// Contadores globais (sem filtro) para os badges
$stmtCount = $db->prepare(
    'SELECT document_status, COUNT(*) as total
     FROM employee_asos a JOIN employees e ON e.id = a.employee_id
     WHERE e.company_id = ? AND e.status = "active"
     GROUP BY document_status'
);
$stmtCount->execute([$companyId]);
$counts = ['valido' => 0, 'vencendo' => 0, 'vencido' => 0];
foreach ($stmtCount->fetchAll() as $row) {
    $counts[$row['document_status']] = (int)$row['total'];
}

$asoTypeLabels = [
    'admissional'      => 'Admissional',
    'periodico'        => 'Periódico',
    'retorno_trabalho' => 'Retorno ao Trabalho',
    'mudanca_risco'    => 'Mudança de Risco',
    'demissional'      => 'Demissional',
];

$pageTitle = 'ASOs';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- Page header -->
<div class="page-header">
    <div>
        <h1 class="page-title">ASOs</h1>
        <p class="page-subtitle">
            <?= $pag['total'] ?> ASO(s) encontrado(s)
            <?php if ($counts['vencendo'] + $counts['vencido'] > 0): ?>
            — <span class="text-red-600 font-semibold">
                <?= $counts['vencendo'] + $counts['vencido'] ?> precisam de atenção
            </span>
            <?php endif; ?>
        </p>
    </div>
    <div class="flex gap-2">
        <a href="<?= BASE_URL ?>/admin/aso-notify.php" class="btn btn-secondary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
            Alertas de ASO
            <?php if ($counts['vencendo'] + $counts['vencido'] > 0): ?>
            <span style="background:#dc2626;color:#fff;border-radius:99px;font-size:.65rem;
                         font-weight:700;padding:.1rem .45rem;margin-left:.25rem">
                <?= $counts['vencendo'] + $counts['vencido'] ?>
            </span>
            <?php endif; ?>
        </a>
        <a href="<?= BASE_URL ?>/admin/aso-import.php" class="btn btn-secondary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            Importar
        </a>
    </div>
</div>

<?php renderFlashes(); ?>

<!-- Cards de resumo -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem">
    <?php
    $summaryCards = [
        ['label' => 'Válidos',   'count' => $counts['valido'],   'color' => '#16a34a', 'bg' => '#f0fdf4', 'border' => '#bbf7d0', 'filter' => 'valido'],
        ['label' => 'Vencendo',  'count' => $counts['vencendo'], 'color' => '#b45309', 'bg' => '#fffbeb', 'border' => '#fde68a', 'filter' => 'vencendo'],
        ['label' => 'Vencidos',  'count' => $counts['vencido'],  'color' => '#dc2626', 'bg' => '#fef2f2', 'border' => '#fecaca', 'filter' => 'vencido'],
    ];
    foreach ($summaryCards as $card):
        $active = $statusFilter === $card['filter'];
        $href   = '?' . http_build_query(array_merge($_GET, ['status' => $card['filter'], 'page' => 1]));
        $hrefClear = '?' . http_build_query(array_merge($_GET, ['status' => '', 'page' => 1]));
    ?>
    <a href="<?= $active ? $hrefClear : $href ?>"
       style="background:<?= $active ? $card['bg'] : '#fff' ?>;border:2px solid <?= $active ? $card['color'] : $card['border'] ?>;
              border-radius:.75rem;padding:1rem 1.25rem;text-decoration:none;transition:.15s;display:block">
        <div style="font-size:1.75rem;font-weight:700;color:<?= $card['color'] ?>;line-height:1">
            <?= $card['count'] ?>
        </div>
        <div style="font-size:.8rem;color:#64748b;margin-top:.25rem;font-weight:500"><?= $card['label'] ?></div>
    </a>
    <?php endforeach; ?>
</div>

<!-- Filtros -->
<form method="GET" class="section-card mb-5">
    <div class="section-card-body">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" name="search" value="<?= e($search) ?>"
                       placeholder="Buscar colaborador ou médico..." class="form-input pl-10">
            </div>
            <select name="status" class="form-input">
                <option value="">Todos os Status</option>
                <option value="valido"   <?= $statusFilter==='valido'   ?'selected':'' ?>>Válidos</option>
                <option value="vencendo" <?= $statusFilter==='vencendo' ?'selected':'' ?>>Vencendo</option>
                <option value="vencido"  <?= $statusFilter==='vencido'  ?'selected':'' ?>>Vencidos</option>
            </select>
            <select name="tipo" class="form-input">
                <option value="">Todos os Tipos</option>
                <?php foreach ($asoTypeLabels as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $tipoFilter===$val?'selected':'' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <select name="emp" class="form-input">
                <option value="">Todos os Colaboradores</option>
                <?php foreach ($empList as $emp): ?>
                    <option value="<?= (int)$emp['id'] ?>" <?= $empFilter==(string)$emp['id']?'selected':'' ?>>
                        <?= e($emp['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
            <?php if ($search||$statusFilter||$tipoFilter||$empFilter): ?>
                <a href="<?= BASE_URL ?>/admin/asos.php" class="btn btn-secondary btn-sm">Limpar</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- Tabela de ASOs -->
<?php if (empty($asos)): ?>
<div class="section-card">
    <div class="py-16 text-center text-gray-400">
        <svg class="h-12 w-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                  d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        <p class="font-medium text-gray-500">Nenhum ASO encontrado</p>
        <p class="text-sm mt-1">
            <a href="<?= BASE_URL ?>/admin/aso-import.php" class="text-industrial-blue hover:underline">Importar via planilha</a>
            ou adicione pelo perfil de um colaborador.
        </p>
    </div>
</div>
<?php else: ?>

<div class="section-card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0">
                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Colaborador</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Tipo</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Resultado</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Exame</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Validade</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Médico</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($asos as $aso):
                $ds = $aso['document_status'];
                [$statusLabel, $statusColor, $statusBg] = match($ds) {
                    'valido'   => ['Válido',   '#16a34a', '#f0fdf4'],
                    'vencendo' => ['Vencendo', '#b45309', '#fffbeb'],
                    'vencido'  => ['Vencido',  '#dc2626', '#fef2f2'],
                    default    => ['—',        '#64748b', '#f1f5f9'],
                };
                $resLabel = match($aso['result_status']) {
                    'apto'     => ['Apto',     '#15803d', '#dcfce7'],
                    'inapto'   => ['Inapto',   '#b91c1c', '#fee2e2'],
                    'pendente' => ['Pendente', '#92400e', '#fef3c7'],
                    default    => ['—',        '#64748b', '#f1f5f9'],
                };
                $initials = strtoupper(mb_substr($aso['emp_name'], 0, 1));
                $hoje = new DateTime();
                $exp  = new DateTime($aso['expiration_date']);
                $diff = (int)$hoje->diff($exp)->format('%r%a');
            ?>
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3">
                    <a href="<?= BASE_URL ?>/admin/employee-details.php?id=<?= (int)$aso['emp_id'] ?>"
                       class="flex items-center gap-2 text-gray-900 hover:text-industrial-blue font-medium">
                        <div style="width:1.75rem;height:1.75rem;border-radius:50%;background:#1e3a8a;
                                    color:#fff;display:flex;align-items:center;justify-content:center;
                                    font-size:.7rem;font-weight:700;flex-shrink:0">
                            <?= $initials ?>
                        </div>
                        <div>
                            <div class="text-sm font-medium"><?= e($aso['emp_name']) ?></div>
                            <div class="text-xs text-gray-400"><?= e($aso['emp_role'] ?? '') ?></div>
                        </div>
                    </a>
                </td>
                <td class="px-4 py-3 text-gray-700"><?= $asoTypeLabels[$aso['aso_type']] ?? e($aso['aso_type']) ?></td>
                <td class="px-4 py-3">
                    <span style="background:<?= $resLabel[2] ?>;color:<?= $resLabel[1] ?>;
                                 border-radius:99px;padding:.15rem .6rem;font-size:.72rem;font-weight:600">
                        <?= $resLabel[0] ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-gray-600"><?= date('d/m/Y', strtotime($aso['exam_date'])) ?></td>
                <td class="px-4 py-3">
                    <div class="text-gray-700"><?= date('d/m/Y', strtotime($aso['expiration_date'])) ?></div>
                    <?php if ($diff >= 0 && $diff <= 60): ?>
                    <div style="font-size:.7rem;color:<?= $diff <= 30 ? '#dc2626' : '#b45309' ?>">
                        <?= $diff === 0 ? 'Vence hoje' : "em {$diff} dias" ?>
                    </div>
                    <?php elseif ($diff < 0): ?>
                    <div style="font-size:.7rem;color:#dc2626">Vencido há <?= abs($diff) ?> dias</div>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3">
                    <span style="background:<?= $statusBg ?>;color:<?= $statusColor ?>;
                                 border-radius:99px;padding:.15rem .6rem;font-size:.72rem;font-weight:600">
                        <?= $statusLabel ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?= $aso['doctor_name'] ? e($aso['doctor_name']) : '—' ?></td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-1 justify-end">
                        <?php if (!empty($aso['file_path'])): ?>
                        <a href="<?= BASE_URL ?>/admin/aso-download.php?id=<?= (int)$aso['id'] ?>"
                           title="Baixar PDF"
                           style="padding:.3rem;border-radius:.375rem;color:#64748b;display:inline-flex;
                                  align-items:center;transition:.15s"
                           onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </a>
                        <?php endif; ?>
                        <a href="<?= BASE_URL ?>/admin/aso-form.php?id=<?= (int)$aso['id'] ?>&employee_id=<?= (int)$aso['emp_id'] ?>"
                           title="Editar ASO"
                           style="padding:.3rem;border-radius:.375rem;color:#64748b;display:inline-flex;
                                  align-items:center;transition:.15s"
                           onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </a>
                        <a href="<?= BASE_URL ?>/admin/aso-form.php?employee_id=<?= (int)$aso['emp_id'] ?>"
                           title="Novo ASO para este colaborador"
                           style="padding:.3rem;border-radius:.375rem;color:#1e3a8a;display:inline-flex;
                                  align-items:center;transition:.15s"
                           onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='transparent'">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                        </a>
                        <a href="<?= BASE_URL ?>/admin/aso-delete.php?id=<?= (int)$aso['id'] ?>"
                           title="Excluir"
                           onclick="return confirm('Excluir este ASO?')"
                           style="padding:.3rem;border-radius:.375rem;color:#64748b;display:inline-flex;
                                  align-items:center;transition:.15s"
                           onmouseover="this.style.background='#fef2f2';this.style.color='#dc2626'"
                           onmouseout="this.style.background='transparent';this.style.color='#64748b'">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<!-- Paginação -->
<?php if ($pag['pages'] > 1): ?>
<div class="flex items-center justify-center gap-2 mt-5">
    <?php for ($p = 1; $p <= $pag['pages']; $p++): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
           style="padding:.35rem .7rem;border-radius:.375rem;font-size:.85rem;font-weight:600;
                  text-decoration:none;transition:.15s;
                  background:<?= $p === $pag['page'] ? '#1e3a8a' : '#fff' ?>;
                  color:<?= $p === $pag['page'] ? '#fff' : '#64748b' ?>;
                  border:1px solid <?= $p === $pag['page'] ? '#1e3a8a' : '#e2e8f0' ?>">
            <?= $p ?>
        </a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
