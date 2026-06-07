<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$id     = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$doc    = null;
$errors = [];

$docTypes = [
    'aso'                  => 'ASO – Atestado de Saúde Ocupacional',
    'nr_certificado'       => 'NR / Certificado',
    'certificado_assinado' => 'Certificado Assinado',
    'foto'                 => 'Foto',
    'pdf_geral'            => 'PDF Geral',
    'doc_word'             => 'Documento Word',
    'outro'                => 'Outro',
];

// Carrega em edição
if ($isEdit) {
    $s = $db->prepare(
        'SELECT * FROM employee_documents WHERE id = ? AND company_id = ? LIMIT 1'
    );
    $s->execute([$id, $companyId]);
    $doc = $s->fetch();
    if (!$doc) {
        setFlash('error', 'Documento não encontrado.');
        redirect('/admin/documents.php');
    }
}

// Colaboradores
$empStmt = $db->prepare(
    'SELECT id, name, role FROM employees WHERE company_id = ? AND status = "active" ORDER BY name ASC'
);
$empStmt->execute([$companyId]);
$employees = $empStmt->fetchAll();

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) { die('CSRF inválido.'); }

    $employeeId  = (int)($_POST['employee_id'] ?? 0);
    $docType     = trim($_POST['doc_type']    ?? 'outro');
    $title       = trim($_POST['title']       ?? '');
    $description = trim($_POST['description'] ?? '');
    $issueDate   = trim($_POST['issue_date']  ?? '') ?: null;
    $expiryDate  = trim($_POST['expiry_date'] ?? '') ?: null;

    if (!$employeeId) $errors[] = 'Selecione um colaborador.';
    if (!$title)      $errors[] = 'O título é obrigatório.';
    if (!isset($docTypes[$docType])) $docType = 'outro';

    $filePath = $doc['file_path'] ?? null;
    $fileName = $doc['file_name'] ?? null;
    $fileExt  = $doc['file_ext']  ?? null;
    $fileSize = $doc['file_size'] ?? 0;

    // Upload de arquivo novo
    if (!empty($_FILES['file']['name'])) {
        $file       = $_FILES['file'];
        $origName   = $file['name'];
        $tmpPath    = $file['tmp_name'];
        $fileSize   = $file['size'];
        $ext        = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowed    = ['pdf','jpg','jpeg','png','gif','webp','bmp','doc','docx'];

        if (!in_array($ext, $allowed)) {
            $errors[] = 'Tipo de arquivo não permitido. Use: PDF, JPG, PNG, DOC, DOCX.';
        } elseif ($fileSize > 20 * 1024 * 1024) {
            $errors[] = 'Arquivo muito grande. Máximo: 20 MB.';
        } else {
            $destDir  = __DIR__ . '/../uploads/documents/';
            $newName  = uniqid('doc_', true) . '.' . $ext;
            $destPath = $destDir . $newName;
            if (move_uploaded_file($tmpPath, $destPath)) {
                // Remove arquivo antigo se existir
                if ($filePath && file_exists(__DIR__ . '/../' . $filePath)) {
                    @unlink(__DIR__ . '/../' . $filePath);
                }
                $filePath = 'uploads/documents/' . $newName;
                $fileName = $origName;
                $fileExt  = $ext;
            } else {
                $errors[] = 'Erro ao salvar o arquivo. Verifique as permissões.';
            }
        }
    }

    if (empty($errors) && !$filePath && !$isEdit) {
        $errors[] = 'Selecione um arquivo para enviar.';
    }

    if (empty($errors)) {
        // Calcula status
        $status = 'sem_validade';
        if ($expiryDate) {
            $today = new DateTime();
            $exp   = new DateTime($expiryDate);
            $diff  = $today->diff($exp)->days;
            if ($exp < $today) {
                $status = 'vencido';
            } elseif ($diff <= 30) {
                $status = 'vencendo';
            } else {
                $status = 'valido';
            }
        }

        if ($isEdit) {
            $db->prepare(
                'UPDATE employee_documents
                 SET employee_id=?, doc_type=?, title=?, description=?,
                     issue_date=?, expiry_date=?, status=?,
                     file_path=?, file_name=?, file_ext=?, file_size=?,
                     updated_at=NOW()
                 WHERE id=? AND company_id=?'
            )->execute([
                $employeeId, $docType, $title, $description,
                $issueDate, $expiryDate, $status,
                $filePath, $fileName, $fileExt, $fileSize,
                $id, $companyId
            ]);
            setFlash('success', 'Documento atualizado com sucesso!');
        } else {
            $db->prepare(
                'INSERT INTO employee_documents
                 (company_id, employee_id, doc_type, title, description,
                  file_path, file_name, file_ext, file_size,
                  issue_date, expiry_date, status, uploaded_by)
                 VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?,?)'
            )->execute([
                $companyId, $employeeId, $docType, $title, $description,
                $filePath, $fileName, $fileExt, $fileSize,
                $issueDate, $expiryDate, $status, $user['id']
            ]);
            setFlash('success', 'Documento adicionado com sucesso!');
        }
        redirect('/admin/documents.php');
    }
}

$pageTitle = $isEdit ? 'Editar Documento' : 'Adicionar Documento';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- Voltar -->
<div class="mb-5">
    <a href="<?= BASE_URL ?>/admin/documents.php"
       class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-industrial-blue transition">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Voltar para Documentos
    </a>
</div>

<?php renderFlashes(); ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-error mb-4">
    <ul class="list-disc list-inside space-y-1">
        <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="section-card max-w-2xl">
    <div class="section-card-header">
        <h1 class="section-card-title flex items-center gap-2">
            <svg class="h-5 w-5 text-industrial-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <?= $isEdit ? 'Editar Documento' : 'Adicionar Documento' ?>
        </h1>
    </div>
    <div class="section-card-body">
        <form method="POST" enctype="multipart/form-data" id="doc-form">
                <form method="POST" enctype="multipart/form-data" id="doc-form">
                    <?= csrfField() ?>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                        <!-- Colaborador -->
                        <div class="sm:col-span-2">
                            <label class="form-label">Colaborador <span class="text-red-500">*</span></label>
                            <select name="employee_id" id="sel-employee" class="form-select w-full" required>
                                <option value="">Selecione o colaborador...</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>"
                                        <?= ($doc['employee_id'] ?? ($_GET['employee_id'] ?? 0)) == $emp['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['name']) ?> — <?= htmlspecialchars($emp['role']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Tipo do documento -->
                        <div>
                            <label class="form-label">Tipo de Documento <span class="text-red-500">*</span></label>
                            <select name="doc_type" id="sel-type" class="form-select w-full" required>
                                <?php foreach ($docTypes as $k => $l): ?>
                                    <option value="<?= $k ?>" <?= ($doc['doc_type'] ?? 'outro') === $k ? 'selected' : '' ?>>
                                        <?= $l ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Título -->
                        <div>
                            <label class="form-label">Título <span class="text-red-500">*</span></label>
                            <input type="text" name="title" id="inp-title" class="form-input w-full" required
                                   placeholder="Ex: ASO Admissional 2025"
                                   value="<?= htmlspecialchars($doc['title'] ?? '') ?>">
                        </div>

                        <!-- Data de emissão -->
                        <div>
                            <label class="form-label">Data de Emissão</label>
                            <input type="date" name="issue_date" class="form-input w-full"
                                   value="<?= $doc['issue_date'] ?? '' ?>">
                        </div>

                        <!-- Data de validade -->
                        <div>
                            <label class="form-label">Data de Validade</label>
                            <input type="date" name="expiry_date" class="form-input w-full"
                                   value="<?= $doc['expiry_date'] ?? '' ?>">
                        </div>

                        <!-- Descrição -->
                        <div class="sm:col-span-2">
                            <label class="form-label">Descrição / Observações</label>
                            <textarea name="description" class="form-input w-full" rows="2"
                                      placeholder="Informações adicionais sobre o documento..."><?= htmlspecialchars($doc['description'] ?? '') ?></textarea>
                        </div>

                        <!-- Upload de arquivo -->
                        <div class="sm:col-span-2">
                            <label class="form-label">
                                Arquivo
                                <?php if (!$isEdit): ?><span class="text-red-500">*</span><?php endif; ?>
                                <span class="text-xs font-normal text-gray-400 ml-1">PDF, JPG, PNG, DOC, DOCX — máx. 20 MB</span>
                            </label>

                            <!-- Drop zone -->
                            <div id="drop-zone"
                                 class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center cursor-pointer
                                        hover:border-industrial-blue/50 hover:bg-blue-50/30 transition"
                                 onclick="document.getElementById('file-input').click()">
                                <div id="drop-content">
                                    <svg class="h-10 w-10 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                              d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                    </svg>
                                    <p class="text-gray-500 font-medium">Clique ou arraste o arquivo aqui</p>
                                    <p class="text-xs text-gray-400 mt-1">PDF, JPG, PNG, DOC, DOCX</p>
                                </div>
                                <div id="drop-preview" class="hidden"></div>
                            </div>
                            <input type="file" id="file-input" name="file" class="hidden"
                                   accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx">

                            <?php if ($isEdit && !empty($doc['file_name'])): ?>
                            <p class="text-xs text-gray-500 mt-2 flex items-center gap-1">
                                <svg class="h-4 w-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                Arquivo atual: <strong><?= htmlspecialchars($doc['file_name']) ?></strong>
                                · Selecione outro para substituir.
                            </p>
                            <?php endif; ?>
                        </div>

                    </div><!-- /grid -->

                    <div class="flex gap-3 mt-6 pt-4 border-t border-gray-100">
                        <button type="submit" class="btn btn-primary">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <?= $isEdit ? 'Salvar Alterações' : 'Salvar Documento' ?>
                        </button>
                        <a href="<?= BASE_URL ?>/admin/documents.php" class="btn btn-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div><!-- fecha section-card max-w-2xl -->

<script>
// ── Drop Zone ──────────────────────────────────────────────────────────
const dropZone  = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');

fileInput.addEventListener('change', function() {
    if (this.files[0]) handleFile(this.files[0]);
});

dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('border-industrial-blue','bg-blue-50'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('border-industrial-blue','bg-blue-50'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('border-industrial-blue','bg-blue-50');
    const f = e.dataTransfer.files[0];
    if (f) { fileInput.files = e.dataTransfer.files; handleFile(f); }
});

function handleFile(file) {
    selectedFile = file;
    const ext    = file.name.split('.').pop().toLowerCase();
    const isImg  = ['jpg','jpeg','png','gif','webp'].includes(ext);
    const content = document.getElementById('drop-content');
    const preview = document.getElementById('drop-preview');
    content.classList.add('hidden');
    preview.classList.remove('hidden');

    const size = file.size < 1024*1024
        ? (file.size/1024).toFixed(1)+' KB'
        : (file.size/1024/1024).toFixed(1)+' MB';

    if (isImg) {
        const reader = new FileReader();
        reader.onload = e => {
            preview.innerHTML = `
                <img src="${e.target.result}" class="max-h-32 mx-auto rounded-lg shadow mb-2 object-contain">
                <p class="text-sm text-gray-600 font-medium">${file.name}</p>
                <p class="text-xs text-gray-400">${size}</p>`;
        };
        reader.readAsDataURL(file);
    } else {
        const icons = {pdf:'📄', doc:'📝', docx:'📝'};
        preview.innerHTML = `
            <div class="text-4xl mb-2">${icons[ext]||'📎'}</div>
            <p class="text-sm text-gray-700 font-semibold">${file.name}</p>
            <p class="text-xs text-gray-400">${size}</p>`;
    }

    // Auto-preenche título se vazio
    const inp = document.getElementById('inp-title');
    if (!inp.value) {
        inp.value = file.name.replace(/\.[^.]+$/, '').replace(/[_-]/g,' ');
    }
}


</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
