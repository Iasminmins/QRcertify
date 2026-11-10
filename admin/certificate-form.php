<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;
$errors = [];

$stmt = $db->prepare('SELECT id, name, role FROM employees WHERE company_id = ? AND status = "active" ORDER BY name');
$stmt->execute([$companyId]);
$employees = $stmt->fetchAll();

$cert = [
    'title'       => '',
    'type'        => '',
    'employee_id' => (int)($_GET['employee_id'] ?? 0),
    'issued_date' => '',
    'expiry_date' => '',
    'hash'        => '',
    'document'    => '',
    'description' => '',
    'institution' => '',
    'workload'    => '',
];

if ($isEdit) {
    $stmt = $db->prepare(
        'SELECT c.* FROM certificates c
         JOIN employees e ON e.id = c.employee_id
         WHERE c.id = ? AND e.company_id = ?'
    );
    $stmt->execute([$id, $companyId]);
    $row = $stmt->fetch();
    if (!$row) {
        setFlash('error', 'Certificado não encontrado.');
        redirect('/admin/certificates.php');
    }
    $cert = array_merge($cert, $row);
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) { $errors[] = 'Token de segurança inválido.'; }
    else {
        $cert['title']       = trim($_POST['title']       ?? '');
        $cert['type']        = trim($_POST['type']        ?? '');
        $cert['employee_id'] = (int)($_POST['employee_id'] ?? 0);
        $cert['issued_date'] = trim($_POST['issued_date'] ?? '');
        $cert['expiry_date'] = trim($_POST['expiry_date'] ?? '');
        $cert['institution'] = trim($_POST['institution'] ?? '');
        $cert['workload']    = trim($_POST['workload']    ?? '');
        $cert['description'] = trim($_POST['description'] ?? '');

        if ($cert['title'] === '')       $errors[] = 'Título é obrigatório.';
        if ($cert['type']  === '')       $errors[] = 'Tipo é obrigatório.';
        if ($cert['employee_id'] <= 0)   $errors[] = 'Selecione um colaborador.';
        if ($cert['issued_date'] === '')  $errors[] = 'Data de emissão é obrigatória.';
        if ($cert['expiry_date'] === '')  $errors[] = 'Data de validade é obrigatória.';
        if ($cert['issued_date'] && $cert['expiry_date'] && $cert['expiry_date'] < $cert['issued_date'])
            $errors[] = 'A validade deve ser posterior à emissão.';

        // Verifica colaborador
        if (empty($errors)) {
            $check = $db->prepare('SELECT id, name FROM employees WHERE id = ? AND company_id = ?');
            $check->execute([$cert['employee_id'], $companyId]);
            $empRow = $check->fetch();
            if (!$empRow) $errors[] = 'Colaborador inválido.';
        }

        // Upload — PDF, JPG, PNG, WEBP
        $newDocPath  = null;
        $newDocName  = null;
        $newDocExt   = null;
        $newDocSize  = 0;
        $newDocSha256 = null;
        $fileUploaded = false;

        if (!empty($_FILES['document_upload']['name'])) {
            if (!companyHasFeature($companyId, 'pdf_upload')) {
                $errors[] = 'Upload de arquivo está disponível nos planos Pro, Business e Enterprise.';
            } elseif (empty($errors)) {
                $up = uploadFile($_FILES['document_upload'], 'certificate', $cert['document'] ?: null);
                if (!$up['ok']) {
                    $errors[] = $up['error'];
                } else {
                    $newDocPath   = $up['path'];
                    $newDocName   = $_FILES['document_upload']['name'];
                    $newDocExt    = strtolower(pathinfo($newDocName, PATHINFO_EXTENSION));
                    $newDocSize   = $_FILES['document_upload']['size'];
                    $newDocSha256 = $up['sha256'];
                    $fileUploaded = true;
                }
            }
        }

        if (empty($errors)) {
            $status  = getCertStatus($cert['expiry_date']);
            $docPath = $newDocPath ?? ($cert['document'] ?: null);

            if ($isEdit) {
                $db->prepare(
                    'UPDATE certificates
                     SET title=?, type=?, employee_id=?, issued_date=?, expiry_date=?,
                         status=?, document=?, file_sha256=?
                     WHERE id=?'
                )->execute([
                    $cert['title'], $cert['type'], $cert['employee_id'],
                    $cert['issued_date'], $cert['expiry_date'], $status,
                    $docPath, $fileUploaded ? $newDocSha256 : ($cert['file_sha256'] ?? null), $id
                ]);
                auditLog('cert.update', "cert #{$id}", json_encode(['title' => $cert['title']]));

                // ── Espelha documento em employee_documents ─────────────────
                if ($fileUploaded && $newDocPath) {
                    $docSt2   = $status === 'expired' ? 'vencido' : ($status === 'expiring' ? 'vencendo' : 'valido');
                    $docTitle = $cert['type'] . ' – ' . $cert['title'];
                    $existDoc = $db->prepare('SELECT id FROM employee_documents WHERE employee_id=? AND company_id=? AND doc_type="certificado_assinado" AND title=? LIMIT 1');
                    $existDoc->execute([$cert['employee_id'], $companyId, $docTitle]);
                    $edRow = $existDoc->fetch();
                    if ($edRow) {
                        $db->prepare('UPDATE employee_documents SET file_path=?,file_sha256=?,file_name=?,file_ext=?,file_size=?,issue_date=?,expiry_date=?,status=?,updated_at=NOW() WHERE id=?')
                           ->execute([$newDocPath,$newDocSha256,$newDocName,$newDocExt,$newDocSize,$cert['issued_date'],$cert['expiry_date'],$docSt2,$edRow['id']]);
                    } else {
                        $db->prepare('INSERT INTO employee_documents (company_id,employee_id,doc_type,title,description,file_path,file_sha256,file_name,file_ext,file_size,issue_date,expiry_date,status,uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                           ->execute([$companyId,$cert['employee_id'],'certificado_assinado',$docTitle,'Documento do certificado ' . $cert['type'] . '.',$newDocPath,$newDocSha256,$newDocName,$newDocExt,$newDocSize,$cert['issued_date'],$cert['expiry_date'],$docSt2,$user['id']]);
                    }
                }
                setFlash('success', 'Certificado atualizado!' . ($fileUploaded ? ' Documento salvo em Documentos.' : ''));
            } else {
                $hash = generateHash();
                $db->prepare(
                    'INSERT INTO certificates
                       (employee_id, title, type, issued_date, expiry_date, status, hash, document, file_sha256, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $cert['employee_id'], $cert['title'], $cert['type'],
                    $cert['issued_date'], $cert['expiry_date'], $status, $hash, $docPath, $newDocSha256, $user['id']
                ]);
                $newId = $db->lastInsertId();
                auditLog('cert.create', "cert #{$newId}", json_encode(['title' => $cert['title']]));

                // ── Espelha documento em employee_documents ─────────────────
                if ($fileUploaded && $newDocPath) {
                    $docSt2   = $status === 'expired' ? 'vencido' : ($status === 'expiring' ? 'vencendo' : 'valido');
                    $docTitle = $cert['type'] . ' – ' . $cert['title'];
                    $db->prepare('INSERT INTO employee_documents (company_id,employee_id,doc_type,title,description,file_path,file_sha256,file_name,file_ext,file_size,issue_date,expiry_date,status,uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                       ->execute([$companyId,$cert['employee_id'],'certificado_assinado',$docTitle,'Documento do certificado ' . $cert['type'] . '.',$newDocPath,$newDocSha256,$newDocName,$newDocExt,$newDocSize,$cert['issued_date'],$cert['expiry_date'],$docSt2,$user['id']]);
                }
                setFlash('success', 'Certificado cadastrado! QR Code gerado.' . ($fileUploaded ? ' Documento salvo em Documentos.' : ''));
            }

            // Redireciona de volta para o colaborador (aba certificados) se veio de lá
            $empId = (int)($cert['employee_id'] ?? 0);
            if ($empId > 0) redirect('/admin/employee-details.php?id=' . $empId . '&tab=certificados');
            redirect('/admin/certificates.php');
        }
    }
}

$nrTypes = [
    'NR-01','NR-05 (CIPA)','NR-06 (EPI)','NR-07 (PCMSO)','NR-09 (PPRA/PGR)',
    'NR-10','NR-11','NR-12','NR-13','NR-15','NR-17','NR-18','NR-20',
    'NR-23','NR-33','NR-34','NR-35','NR-36',
    'ISO 9001','ISO 14001','ISO 45001',
    'CIPA','PPRA','PCMSO','PGR','LTCAT',
    'Primeiros Socorros','Combate a Incêndio','Espaço Confinado',
    'Trabalho em Altura','Operação de Empilhadeira','Operação de Caldeira',
    'Outro',
];

$pageTitle = $isEdit ? 'Editar Certificado' : 'Novo Certificado';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- Voltar -->
<div class="mb-6">
    <?php
    $backUrl = ($cert['employee_id'] > 0)
        ? BASE_URL . '/admin/employee-details.php?id=' . (int)$cert['employee_id'] . '&tab=certificados'
        : BASE_URL . '/admin/certificates.php';
    $backLabel = ($cert['employee_id'] > 0) ? 'Voltar para o Colaborador' : 'Voltar para Certificados';
    ?>
    <a href="<?= $backUrl ?>" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-industrial-blue transition">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        <?= $backLabel ?>
    </a>
    <div class="mt-3 flex items-center gap-4">
        <div class="h-12 w-12 rounded-xl bg-blue-100 flex items-center justify-center flex-shrink-0">
            <svg class="h-6 w-6 text-industrial-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
        </div>
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= $isEdit ? 'Editar Certificado' : 'Novo Certificado' ?></h1>
            <p class="text-sm text-gray-500 mt-0.5">NR, ISO, CIPA, Treinamento e outros documentos de habilitação</p>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 text-sm space-y-1">
    <p class="font-semibold mb-1">⚠️ Corrija os erros abaixo:</p>
    <?php foreach ($errors as $err): ?><div>• <?= e($err) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="space-y-5 max-w-4xl">
    <?= csrfField() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>

    <!-- ══ 1. DADOS DO CERTIFICADO ══════════════════════════════════════ -->
    <div class="section-card">
        <div class="section-card-header">
            <h2 class="section-card-title flex items-center gap-2">
                <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">1</span>
                Dados do Certificado
            </h2>
        </div>
        <div class="section-card-body grid grid-cols-1 sm:grid-cols-2 gap-4">

            <!-- Colaborador com busca -->
            <div class="sm:col-span-2">
                <label class="form-label">Colaborador *</label>
                <div class="relative" id="emp-combo">
                    <!-- Input de busca visível -->
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400 pointer-events-none"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input type="text" id="emp-search"
                               class="form-input w-full pl-9 pr-8"
                               placeholder="Digite para buscar ou selecionar colaborador..."
                               autocomplete="off"
                               value="<?php
                                   if ($cert['employee_id'] > 0) {
                                       foreach ($employees as $e2) {
                                           if ((int)$e2['id'] === (int)$cert['employee_id']) {
                                               echo htmlspecialchars($e2['name'] . ' — ' . $e2['role']);
                                               break;
                                           }
                                       }
                                   }
                               ?>">
                        <!-- Chevron -->
                        <button type="button" onclick="toggleEmpDropdown()"
                                class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600">
                            <svg id="emp-chevron" class="h-4 w-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Hidden real select (enviado no form) -->
                    <select name="employee_id" id="emp-select" required class="hidden">
                        <option value="">-- Selecione --</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= (int)$emp['id'] ?>"
                                <?= (int)$cert['employee_id'] === (int)$emp['id'] ? 'selected' : '' ?>>
                                <?= e($emp['name']) ?> — <?= e($emp['role']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Dropdown de resultados -->
                    <div id="emp-dropdown"
                         class="absolute z-30 w-full bg-white border border-gray-200 rounded-xl shadow-xl hidden overflow-hidden"
                         style="top: calc(100% + 4px);">
                        <ul id="emp-list" class="max-h-56 overflow-y-auto divide-y divide-gray-50 py-1">
                            <?php foreach ($employees as $emp): ?>
                            <li class="emp-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-blue-50 transition"
                                data-id="<?= (int)$emp['id'] ?>"
                                data-label="<?= htmlspecialchars($emp['name'] . ' — ' . $emp['role']) ?>">
                                <div class="h-8 w-8 rounded-full bg-industrial-blue/10 flex items-center justify-center flex-shrink-0
                                            text-industrial-blue text-xs font-bold">
                                    <?= strtoupper(mb_substr($emp['name'], 0, 1)) ?>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900"><?= e($emp['name']) ?></p>
                                    <p class="text-xs text-gray-500 truncate"><?= e($emp['role']) ?></p>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <div id="emp-empty" class="hidden px-4 py-3 text-sm text-gray-400 text-center">
                            Nenhum colaborador encontrado
                        </div>
                    </div>
                </div>
            </div>

            <!-- Título -->
            <div class="sm:col-span-2">
                <label class="form-label">Título do Certificado *</label>
                <input type="text" name="title" value="<?= e($cert['title']) ?>" required
                       placeholder="Ex: NR-10 — Segurança em Instalações Elétricas"
                       class="form-input w-full">
            </div>

            <!-- Tipo / Norma com busca -->
            <div class="sm:col-span-2">
                <label class="form-label">Tipo / Norma *</label>
                <div class="relative" id="type-combo">
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400 pointer-events-none"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input type="text" name="type" id="typeInput"
                               value="<?= e($cert['type']) ?>" required
                               class="form-input w-full pl-9 pr-8"
                               placeholder="Digite ou escolha: NR-10, ISO 9001, CIPA..."
                               autocomplete="off">
                        <button type="button" onclick="toggleTypeDropdown()"
                                class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600">
                            <svg id="type-chevron" class="h-4 w-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Dropdown -->
                    <div id="type-dropdown"
                         class="absolute z-30 w-full bg-white border border-gray-200 rounded-xl shadow-xl hidden overflow-hidden">
                        <ul id="type-list" class="max-h-56 overflow-y-auto py-1">
                            <?php foreach ($nrTypes as $t): ?>
                            <li class="type-option px-4 py-2 cursor-pointer hover:bg-blue-50 text-sm text-gray-800 transition"
                                data-value="<?= htmlspecialchars($t) ?>">
                                <?= htmlspecialchars($t) ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <div id="type-empty" class="hidden px-4 py-3 text-sm text-gray-400 text-center">
                            Nenhum tipo encontrado — será criado como personalizado
                        </div>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-1">Selecione da lista ou digite livremente para criar um tipo personalizado.</p>
            </div>

            <!-- Datas -->
            <div>
                <label class="form-label">Data de Emissão *</label>
                <input type="date" name="issued_date" value="<?= e($cert['issued_date']) ?>"
                       required class="form-input w-full" id="inp-issued">
            </div>
            <div>
                <label class="form-label">Data de Validade *</label>
                <input type="date" name="expiry_date" value="<?= e($cert['expiry_date']) ?>"
                       required class="form-input w-full" id="inp-expiry">
            </div>

        </div>
    </div>

    <!-- ══ 2. DETALHES COMPLEMENTARES ══════════════════════════════════ -->
    <div class="section-card">
        <div class="section-card-header">
            <h2 class="section-card-title flex items-center gap-2">
                <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">2</span>
                Detalhes Complementares
                <span class="text-xs font-normal text-gray-400 ml-1">(opcional)</span>
            </h2>
        </div>
        <div class="section-card-body grid grid-cols-1 sm:grid-cols-2 gap-4">

            <div>
                <label class="form-label">Instituição / Entidade Emissora</label>
                <input type="text" name="institution" maxlength="200" class="form-input w-full"
                       placeholder="Ex: SENAI, SESI, empresa interna..."
                       value="<?= e($cert['institution'] ?? '') ?>">
            </div>

            <div>
                <label class="form-label">Carga Horária</label>
                <input type="text" name="workload" maxlength="50" class="form-input w-full"
                       placeholder="Ex: 8h, 40h, 200h"
                       value="<?= e($cert['workload'] ?? '') ?>">
            </div>

            <div class="sm:col-span-2">
                <label class="form-label">Observações Internas</label>
                <textarea name="description" rows="2" maxlength="500" class="form-input w-full resize-none"
                          placeholder="Observações internas sobre este certificado..."><?= e($cert['description'] ?? '') ?></textarea>
            </div>

        </div>
    </div>

    <!-- ══ 3. ARQUIVO DO CERTIFICADO ════════════════════════════════════ -->
    <div class="section-card">
        <div class="section-card-header">
            <h2 class="section-card-title flex items-center gap-2">
                <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">3</span>
                Arquivo do Certificado
                <span class="text-xs font-normal text-gray-400 ml-1">— salvo automaticamente em Documentos</span>
            </h2>
        </div>
        <div class="section-card-body space-y-3">

            <?php if (!empty($cert['document'])): ?>
            <div class="flex items-center gap-3 p-3 bg-green-50 rounded-lg border border-green-200">
                <svg class="h-8 w-8 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M5 13l4 4L19 7"/>
                </svg>
                <div class="flex-1">
                    <p class="text-sm font-medium text-gray-800">Arquivo anexado</p>
                    <p class="text-xs text-gray-500 truncate"><?= e(basename($cert['document'])) ?></p>
                </div>
                <a href="<?= BASE_URL . '/' . e($cert['document']) ?>" target="_blank"
                   class="btn btn-secondary btn-sm">Ver</a>
            </div>
            <p class="text-xs text-gray-400">Selecione um novo arquivo para substituir.</p>
            <?php endif; ?>

            <!-- Drop zone -->
            <div id="cert-drop-zone"
                 class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center cursor-pointer
                        hover:border-industrial-blue/50 hover:bg-blue-50/30 transition"
                 onclick="document.getElementById('cert-file-input').click()">
                <div id="cert-drop-content">
                    <svg class="h-10 w-10 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <p class="text-gray-500 font-medium">Clique ou arraste o arquivo aqui</p>
                    <p class="text-xs text-gray-400 mt-1">PDF, JPG, PNG, WEBP · máx. 20 MB</p>
                    <div class="flex items-center justify-center gap-2 mt-3">
                        <span class="px-2 py-0.5 bg-red-50 border border-red-200 text-red-700 text-xs font-bold rounded">PDF</span>
                        <span class="px-2 py-0.5 bg-blue-50 border border-blue-200 text-blue-700 text-xs font-bold rounded">JPG</span>
                        <span class="px-2 py-0.5 bg-green-50 border border-green-200 text-green-700 text-xs font-bold rounded">PNG</span>
                        <span class="px-2 py-0.5 bg-purple-50 border border-purple-200 text-purple-700 text-xs font-bold rounded">WEBP</span>
                    </div>
                </div>
                <div id="cert-drop-preview" class="hidden"></div>
            </div>
            <input type="file" id="cert-file-input" name="document_upload" class="hidden"
                   accept=".pdf,.jpg,.jpeg,.png,.webp,.gif">

            <p class="text-xs text-gray-400 flex items-center gap-1">
                <svg class="h-3.5 w-3.5 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                O arquivo será salvo automaticamente na aba <strong class="ml-0.5">Documentos</strong> do colaborador como "Certificado Assinado".
            </p>

        </div>
    </div>

    <!-- Botões -->
    <div class="flex gap-3 pb-6">
        <button type="submit" class="btn btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <?= $isEdit ? 'Salvar Alterações' : 'Cadastrar Certificado' ?>
        </button>
        <a href="<?= $backUrl ?>" class="btn btn-secondary">Cancelar</a>
    </div>
</form>

<script>
// ── Combo de busca de colaborador ─────────────────────────────────────
const empSearch   = document.getElementById('emp-search');
const empDropdown = document.getElementById('emp-dropdown');
const empSelect   = document.getElementById('emp-select');
const empList     = document.getElementById('emp-list');
const empEmpty    = document.getElementById('emp-empty');
const empChevron  = document.getElementById('emp-chevron');
const empOptions  = document.querySelectorAll('.emp-option');

function openEmpDropdown() {
    empDropdown.classList.remove('hidden');
    empChevron.style.transform = 'rotate(180deg)';
    filterEmp(empSearch.value);
}
function closeEmpDropdown() {
    empDropdown.classList.add('hidden');
    empChevron.style.transform = '';
}
function toggleEmpDropdown() {
    empDropdown.classList.contains('hidden') ? openEmpDropdown() : closeEmpDropdown();
}

function filterEmp(query) {
    const q = query.toLowerCase().trim();
    let visible = 0;
    empOptions.forEach(li => {
        const label = li.dataset.label.toLowerCase();
        const show  = !q || label.includes(q);
        li.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    empEmpty.classList.toggle('hidden', visible > 0);
}

// Seleciona colaborador ao clicar
empOptions.forEach(li => {
    li.addEventListener('click', function() {
        empSearch.value = this.dataset.label;
        empSelect.value = this.dataset.id;
        // Destaque visual na opção selecionada
        empOptions.forEach(o => o.classList.remove('bg-blue-50','font-semibold'));
        this.classList.add('bg-blue-50');
        closeEmpDropdown();
    });
});

// Abre ao focar/digitar
empSearch.addEventListener('focus', openEmpDropdown);
empSearch.addEventListener('input', function() {
    openEmpDropdown();
    filterEmp(this.value);
    // Limpa seleção se digitou algo diferente
    if (this.value.trim() === '') empSelect.value = '';
});

// Fecha ao clicar fora
document.addEventListener('click', function(e) {
    if (!document.getElementById('emp-combo').contains(e.target)) {
        closeEmpDropdown();
        // Se o texto não corresponde a nenhuma opção, limpa
        const match = Array.from(empOptions).find(
            li => li.dataset.label.toLowerCase() === empSearch.value.toLowerCase().trim()
        );
        if (!match && empSelect.value) {
            // mantém a seleção anterior
        }
    }
});

// Teclado: setas e enter
empSearch.addEventListener('keydown', function(e) {
    const visible = Array.from(empOptions).filter(li => li.style.display !== 'none');
    if (!visible.length) return;
    const current = empList.querySelector('.emp-focused');
    let idx = current ? visible.indexOf(current) : -1;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (current) current.classList.remove('emp-focused','bg-blue-100');
        idx = Math.min(idx + 1, visible.length - 1);
        visible[idx].classList.add('emp-focused','bg-blue-100');
        visible[idx].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (current) current.classList.remove('emp-focused','bg-blue-100');
        idx = Math.max(idx - 1, 0);
        visible[idx].classList.add('emp-focused','bg-blue-100');
        visible[idx].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (current) current.click();
    } else if (e.key === 'Escape') {
        closeEmpDropdown();
    }
});

// ── Combo de tipo / norma ─────────────────────────────────────────────
const typeInput    = document.getElementById('typeInput');
const typeDropdown = document.getElementById('type-dropdown');
const typeChevron  = document.getElementById('type-chevron');
const typeOptions  = document.querySelectorAll('.type-option');
const typeEmpty    = document.getElementById('type-empty');
const titleInput   = document.querySelector('[name=title]');

function openTypeDropdown() {
    typeDropdown.classList.remove('hidden');
    // Decide direção: se não há espaço abaixo, abre para cima
    const rect    = typeInput.getBoundingClientRect();
    const spaceB  = window.innerHeight - rect.bottom;
    const spaceT  = rect.top;
    const height  = 240; // max-h-56 aprox
    if (spaceB < height && spaceT > spaceB) {
        typeDropdown.style.bottom = 'calc(100% + 4px)';
        typeDropdown.style.top    = 'auto';
    } else {
        typeDropdown.style.top    = 'calc(100% + 4px)';
        typeDropdown.style.bottom = 'auto';
    }
    typeChevron.style.transform = 'rotate(180deg)';
    filterTypes(typeInput.value);
}
function closeTypeDropdown() {
    typeDropdown.classList.add('hidden');
    typeChevron.style.transform = '';
}
function toggleTypeDropdown() {
    typeDropdown.classList.contains('hidden') ? openTypeDropdown() : closeTypeDropdown();
}
function filterTypes(query) {
    const q = query.toLowerCase().trim();
    let visible = 0;
    typeOptions.forEach(li => {
        const show = !q || li.dataset.value.toLowerCase().includes(q);
        li.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    typeEmpty.classList.toggle('hidden', visible > 0);
}

typeOptions.forEach(li => {
    li.addEventListener('click', function() {
        const val = this.dataset.value;
        typeInput.value = val;
        typeOptions.forEach(o => o.classList.remove('bg-blue-50','font-semibold'));
        this.classList.add('bg-blue-50','font-semibold');
        closeTypeDropdown();
        // Auto-preenche título se vazio
        if (!titleInput.value) titleInput.value = val;
        // Auto-calcula validade
        triggerValidityCalc();
    });
});

typeInput.addEventListener('focus', openTypeDropdown);
typeInput.addEventListener('input', function() {
    openTypeDropdown();
    filterTypes(this.value);
});
document.addEventListener('click', function(e) {
    if (!document.getElementById('type-combo').contains(e.target)) closeTypeDropdown();
});
typeInput.addEventListener('keydown', function(e) {
    const visible = Array.from(typeOptions).filter(li => li.style.display !== 'none');
    if (!visible.length) return;
    const current = document.querySelector('.type-focused');
    let idx = current ? visible.indexOf(current) : -1;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (current) current.classList.remove('type-focused','bg-blue-100');
        idx = Math.min(idx + 1, visible.length - 1);
        visible[idx].classList.add('type-focused','bg-blue-100');
        visible[idx].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (current) current.classList.remove('type-focused','bg-blue-100');
        idx = Math.max(idx - 1, 0);
        visible[idx].classList.add('type-focused','bg-blue-100');
        visible[idx].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (current) current.click();
    } else if (e.key === 'Escape') {
        closeTypeDropdown();
    }
});

// ── Auto-calcula validade por tipo de NR ──────────────────────────────
const nrValidity = {
    'NR-10': 24, 'NR-33': 12, 'NR-35': 24, 'NR-20': 12,
    'NR-18': 12, 'NR-34': 12, 'NR-13': 12, 'CIPA': 12,
    'Primeiros Socorros': 12, 'Combate a Incêndio': 12,
    'Espaço Confinado': 12, 'Trabalho em Altura': 24,
    'Operação de Empilhadeira': 12,
};

function triggerValidityCalc() {
    const issued = document.getElementById('inp-issued').value;
    const expiry = document.getElementById('inp-expiry');
    if (!issued || expiry.value) return;
    const months = nrValidity[typeInput.value] ?? 12;
    const d = new Date(issued);
    if (!d.getTime()) return;
    d.setMonth(d.getMonth() + months);
    expiry.value = d.toISOString().split('T')[0];
}

document.getElementById('inp-issued').addEventListener('change', triggerValidityCalc);

// ── Drop Zone ─────────────────────────────────────────────────────────
const certDrop  = document.getElementById('cert-drop-zone');
const certInput = document.getElementById('cert-file-input');

certInput.addEventListener('change', function() {
    if (this.files[0]) handleCertFile(this.files[0]);
});
certDrop.addEventListener('dragover', e => {
    e.preventDefault(); certDrop.classList.add('border-industrial-blue','bg-blue-50');
});
certDrop.addEventListener('dragleave', () => {
    certDrop.classList.remove('border-industrial-blue','bg-blue-50');
});
certDrop.addEventListener('drop', e => {
    e.preventDefault();
    certDrop.classList.remove('border-industrial-blue','bg-blue-50');
    const f = e.dataTransfer.files[0];
    if (f) { certInput.files = e.dataTransfer.files; handleCertFile(f); }
});

function handleCertFile(file) {
    const ext   = file.name.split('.').pop().toLowerCase();
    const isImg = ['jpg','jpeg','png','gif','webp'].includes(ext);
    const size  = file.size < 1024*1024
        ? (file.size/1024).toFixed(1) + ' KB'
        : (file.size/1024/1024).toFixed(1) + ' MB';

    document.getElementById('cert-drop-content').classList.add('hidden');
    const preview = document.getElementById('cert-drop-preview');
    preview.classList.remove('hidden');

    if (isImg) {
        const reader = new FileReader();
        reader.onload = e => {
            preview.innerHTML = `
                <img src="${e.target.result}"
                     class="max-h-52 mx-auto rounded-xl shadow-md mb-3 object-contain border border-gray-200">
                <p class="text-sm font-semibold text-gray-800">${file.name}</p>
                <p class="text-xs text-gray-400">${size}</p>
                <p class="text-xs text-green-600 font-medium mt-1">✓ Imagem pronta para envio</p>`;
        };
        reader.readAsDataURL(file);
    } else {
        preview.innerHTML = `
            <div class="text-5xl mb-3">📄</div>
            <p class="text-sm font-semibold text-gray-800">${file.name}</p>
            <p class="text-xs text-gray-400">${size}</p>
            <p class="text-xs text-green-600 font-medium mt-1">✓ PDF pronto para envio</p>`;
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
