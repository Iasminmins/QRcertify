<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

// ── Filtros ───────────────────────────────────────────────────────────────────
$search     = trim($_GET['search']      ?? '');
$typeFilter = $_GET['doc_type']         ?? '';
$empFilter  = (int)($_GET['employee_id'] ?? 0);
$statusFilter = $_GET['status']         ?? '';

$docTypes = [
    'aso'                  => ['label' => 'ASO',                   'color' => 'blue',   'icon' => '🏥'],
    'nr_certificado'       => ['label' => 'NR / Certificado',      'color' => 'orange', 'icon' => '📋'],
    'certificado_assinado' => ['label' => 'Certificado Assinado',  'color' => 'green',  'icon' => '✅'],
    'foto'                 => ['label' => 'Foto',                  'color' => 'pink',   'icon' => '🖼️'],
    'pdf_geral'            => ['label' => 'PDF Geral',             'color' => 'red',    'icon' => '📄'],
    'doc_word'             => ['label' => 'Documento Word',        'color' => 'indigo', 'icon' => '📝'],
    'outro'                => ['label' => 'Outro',                 'color' => 'gray',   'icon' => '📎'],
];

$statusColors = [
    'valido'       => 'green',
    'vencendo'     => 'amber',
    'vencido'      => 'red',
    'sem_validade' => 'gray',
];
$statusLabels = [
    'valido'       => 'Válido',
    'vencendo'     => 'Vencendo',
    'vencido'      => 'Vencido',
    'sem_validade' => 'Sem validade',
];

// Atualiza status dos documentos com validade
$db->prepare(
    'UPDATE employee_documents
     SET status = CASE
        WHEN expiry_date IS NULL THEN "sem_validade"
        WHEN expiry_date < CURDATE() THEN "vencido"
        WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "vencendo"
        ELSE "valido"
     END
     WHERE company_id = ?'
)->execute([$companyId]);

// ── Query principal ───────────────────────────────────────────────────────────
$sql    = 'SELECT d.*, e.name AS employee_name, e.role AS employee_role
           FROM employee_documents d
           JOIN employees e ON e.id = d.employee_id
           WHERE d.company_id = ?';
$params = [$companyId];

if ($search !== '') {
    $sql    .= ' AND (d.title LIKE ? OR e.name LIKE ? OR d.description LIKE ?)';
    $like    = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($typeFilter !== '' && isset($docTypes[$typeFilter])) {
    $sql     .= ' AND d.doc_type = ?';
    $params[] = $typeFilter;
}
if ($empFilter > 0) {
    $sql     .= ' AND d.employee_id = ?';
    $params[] = $empFilter;
}
if ($statusFilter !== '' && isset($statusLabels[$statusFilter])) {
    $sql     .= ' AND d.status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY d.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$allDocs = $stmt->fetchAll();

$pag  = paginate($allDocs, (int)($_GET['page'] ?? 1), 20);
$docs = $pag['items'];

// ── Colaboradores para o filtro select ───────────────────────────────────────
$empStmt = $db->prepare(
    'SELECT id, name FROM employees WHERE company_id = ? AND status = "active" ORDER BY name ASC'
);
$empStmt->execute([$companyId]);
$allEmployees = $empStmt->fetchAll();

// ── Totais por tipo ───────────────────────────────────────────────────────────
$totStmt = $db->prepare(
    'SELECT doc_type, COUNT(*) as total FROM employee_documents WHERE company_id = ? GROUP BY doc_type'
);
$totStmt->execute([$companyId]);
$typeTotals = [];
foreach ($totStmt->fetchAll() as $row) {
    $typeTotals[$row['doc_type']] = $row['total'];
}
$totalDocs = array_sum($typeTotals);

$pageTitle = 'Documentos';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- ══════════════════════════════════════════════════════════
     HEADER DA PÁGINA
═══════════════════════════════════════════════════════════ -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <svg class="h-7 w-7 text-industrial-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/>
            </svg>
            Documentos
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">
            ASOs, NRs, certificados, fotos e arquivos dos colaboradores
        </p>
    </div>
    <a href="<?= BASE_URL ?>/admin/document-upload.php"
       class="btn btn-primary flex-shrink-0">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Adicionar Documento
    </a>
</div>

<?php renderFlashes(); ?>

<!-- ══════════════════════════════════════════════════════════
     FILTROS
═══════════════════════════════════════════════════════════ -->
<div class="section-card mb-5">
    <form method="GET" class="section-card-body">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <!-- Busca -->
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Buscar documento ou colaborador..."
                       class="form-input pl-9 w-full"/>
            </div>

            <!-- Colaborador -->
            <select name="employee_id" class="form-select w-full">
                <option value="">Todos os colaboradores</option>
                <?php foreach ($allEmployees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $empFilter === (int)$emp['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($emp['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Tipo -->
            <select name="doc_type" class="form-select w-full">
                <option value="">Todos os tipos</option>
                <?php foreach ($docTypes as $key => $info): ?>
                    <option value="<?= $key ?>" <?= $typeFilter === $key ? 'selected' : '' ?>>
                        <?= $info['label'] ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Status -->
            <div class="flex gap-2">
                <select name="status" class="form-select flex-1">
                    <option value="">Todos os status</option>
                    <?php foreach ($statusLabels as $k => $l): ?>
                        <option value="<?= $k ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary px-4">Filtrar</button>
                <?php if ($search || $typeFilter || $empFilter || $statusFilter): ?>
                <a href="<?= BASE_URL ?>/admin/documents.php" class="btn btn-secondary px-3" title="Limpar">✕</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- ══════════════════════════════════════════════════════════
     TABELA / GRID DE DOCUMENTOS
═══════════════════════════════════════════════════════════ -->
<div class="section-card">
    <div class="section-card-header flex items-center justify-between">
        <h2 class="section-card-title">
            Documentos
            <span class="ml-2 text-sm font-normal text-gray-400">(<?= count($allDocs) ?>)</span>
        </h2>
        <?php if ($empFilter > 0): ?>
            <?php
            $empSt = $db->prepare('SELECT name FROM employees WHERE id = ? AND company_id = ? LIMIT 1');
            $empSt->execute([$empFilter, $companyId]);
            $empRow = $empSt->fetch();
            ?>
            <span class="text-sm font-medium text-industrial-blue bg-blue-50 px-3 py-1 rounded-full border border-blue-200">
                👤 <?= htmlspecialchars($empRow['name'] ?? 'Colaborador') ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (empty($docs)): ?>
        <div class="py-16 text-center">
            <div class="text-5xl mb-4">📂</div>
            <p class="text-gray-500 font-medium">Nenhum documento encontrado.</p>
            <p class="text-sm text-gray-400 mt-1">
                <a href="<?= BASE_URL ?>/admin/document-upload.php" class="text-industrial-blue hover:underline">
                    Adicione o primeiro documento
                </a>
            </p>
        </div>
    <?php else: ?>

    <!-- GRID DE CARDS -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 p-4">
        <?php foreach ($docs as $doc):
            $type  = $docTypes[$doc['doc_type']] ?? $docTypes['outro'];
            $sc    = $statusColors[$doc['status']] ?? 'gray';
            $sl    = $statusLabels[$doc['status']] ?? $doc['status'];
            $ext   = strtolower($doc['file_ext'] ?? '');
            $isImg = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']);
            $isPdf = $ext === 'pdf';
        ?>
        <div class="border border-gray-200 rounded-xl overflow-hidden hover:shadow-md hover:border-industrial-blue/30 transition group">

            <!-- Preview / Ícone -->
            <div class="bg-gray-50 h-36 flex items-center justify-center relative overflow-hidden border-b border-gray-100">
                <?php if ($isImg): ?>
                    <img src="<?= BASE_URL ?>/admin/document-view.php?id=<?= $doc['id'] ?>&thumb=1"
                         alt="<?= htmlspecialchars($doc['title']) ?>"
                         class="h-full w-full object-cover"
                         onerror="this.parentNode.innerHTML='<div class=\'text-4xl\'>🖼️</div>'">
                <?php elseif ($isPdf): ?>
                    <div class="text-center">
                        <div class="text-5xl mb-1">📄</div>
                        <span class="text-xs font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded">PDF</span>
                    </div>
                <?php elseif (in_array($ext, ['doc','docx'])): ?>
                    <div class="text-center">
                        <div class="text-5xl mb-1">📝</div>
                        <span class="text-xs font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">WORD</span>
                    </div>
                <?php else: ?>
                    <div class="text-5xl"><?= $type['icon'] ?></div>
                <?php endif; ?>

                <!-- Badge de tipo -->
                <div class="absolute top-2 left-2">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold
                                 bg-white border border-gray-200 shadow-sm text-gray-700">
                        <?= $type['icon'] ?> <?= $type['label'] ?>
                    </span>
                </div>

                <!-- Badge de status (se tiver validade) -->
                <?php if ($doc['status'] !== 'sem_validade'): ?>
                <div class="absolute top-2 right-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold
                                 bg-<?= $sc ?>-100 text-<?= $sc ?>-800 border border-<?= $sc ?>-200">
                        <?= $sl ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Conteúdo -->
            <div class="p-4">
                <h3 class="font-semibold text-gray-900 truncate" title="<?= htmlspecialchars($doc['title']) ?>">
                    <?= htmlspecialchars($doc['title']) ?>
                </h3>
                <p class="text-xs text-gray-500 mt-0.5 flex items-center gap-1">
                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    <?= htmlspecialchars($doc['employee_name']) ?>
                </p>
                <?php if (!empty($doc['expiry_date'])): ?>
                <p class="text-xs text-gray-400 mt-1">
                    Validade: <span class="font-medium text-<?= $sc ?>-600">
                        <?= date('d/m/Y', strtotime($doc['expiry_date'])) ?>
                    </span>
                </p>
                <?php endif; ?>
                <?php if (!empty($doc['issue_date'])): ?>
                <p class="text-xs text-gray-400">
                    Emitido: <?= date('d/m/Y', strtotime($doc['issue_date'])) ?>
                </p>
                <?php endif; ?>

                <!-- Ações -->
                <div class="flex gap-2 mt-3 pt-3 border-t border-gray-100">
                    <a href="<?= BASE_URL ?>/admin/document-view.php?id=<?= $doc['id'] ?>"
                       target="_blank"
                       class="btn btn-secondary btn-sm flex-1 justify-center">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        Ver
                    </a>
                    <a href="<?= BASE_URL ?>/admin/document-upload.php?id=<?= $doc['id'] ?>"
                       class="btn btn-secondary btn-sm">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                    </a>
                    <form method="POST" action="<?= BASE_URL ?>/admin/document-delete.php"
                          onsubmit="return confirm('Excluir este documento?')" class="inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $doc['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Paginação -->
    <?php if ($pag['pages'] > 1): ?>
    <div class="px-4 pb-4 flex justify-center gap-2">
        <?php for ($p = 1; $p <= $pag['pages']; $p++):
            $qp = http_build_query(array_merge($_GET, ['page' => $p]));
        ?>
            <a href="?<?= $qp ?>"
               class="px-3 py-1.5 rounded-lg text-sm font-medium border transition
                      <?= $p === $pag['page'] ? 'bg-industrial-blue text-white border-industrial-blue' : 'bg-white text-gray-600 border-gray-200 hover:border-industrial-blue/40' ?>">
                <?= $p ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
