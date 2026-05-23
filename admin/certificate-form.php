<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user = currentUser();
$companyId = $user['company_id'];
$db = getDB();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;
$errors = [];

$stmt = $db->prepare('SELECT id, name FROM employees WHERE company_id = ? AND status = "active" ORDER BY name');
$stmt->execute([$companyId]);
$employees = $stmt->fetchAll();

$cert = [
    'title' => '', 'type' => '',
    'employee_id' => (int) ($_GET['employee_id'] ?? 0),
    'issued_date' => '', 'expiry_date' => '',
    'hash' => '', 'document' => '',
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $errors[] = 'Token de segurança inválido.';
    } else {
        $cert['title']       = trim($_POST['title'] ?? '');
        $cert['type']        = trim($_POST['type'] ?? '');
        $cert['employee_id'] = (int) ($_POST['employee_id'] ?? 0);
        $cert['issued_date'] = $_POST['issued_date'] ?? '';
        $cert['expiry_date'] = $_POST['expiry_date'] ?? '';

        if ($cert['title'] === '')      $errors[] = 'Título é obrigatório.';
        if ($cert['type'] === '')       $errors[] = 'Tipo é obrigatório.';
        if ($cert['employee_id'] <= 0)  $errors[] = 'Selecione um funcionário.';
        if ($cert['issued_date'] === '') $errors[] = 'Data de emissão é obrigatória.';
        if ($cert['expiry_date'] === '') $errors[] = 'Data de validade é obrigatória.';
        if ($cert['issued_date'] && $cert['expiry_date'] && $cert['expiry_date'] < $cert['issued_date']) {
            $errors[] = 'A validade deve ser posterior à emissão.';
        }

        // Upload de PDF (Pro+)
        $newDocPath = null;
        if (!empty($_FILES['document_upload']['name'])) {
            if (!companyHasFeature($companyId, 'pdf_upload')) {
                $errors[] = 'Upload de PDF está disponível nos planos Pro, Business e Enterprise.';
            }
        }
        if (empty($errors) && !empty($_FILES['document_upload']['name'])) {
            $up = uploadFile($_FILES['document_upload'], 'certificate', $cert['document'] ?: null);
            if (!$up['ok']) {
                $errors[] = $up['error'];
            } else {
                $newDocPath = $up['path'];
            }
        }

        // Verifica se funcionário pertence à empresa
        if (empty($errors)) {
            $check = $db->prepare('SELECT id FROM employees WHERE id = ? AND company_id = ?');
            $check->execute([$cert['employee_id'], $companyId]);
            if (!$check->fetch()) $errors[] = 'Funcionário inválido.';
        }

        if (empty($errors)) {
            $status  = getCertStatus($cert['expiry_date']);
            $docPath = $newDocPath ?? ($cert['document'] ?: null);

            if ($isEdit) {
                $stmt = $db->prepare(
                    'UPDATE certificates
                     SET title=?, type=?, employee_id=?, issued_date=?, expiry_date=?, status=?, document=?
                     WHERE id=?'
                );
                $stmt->execute([
                    $cert['title'], $cert['type'], $cert['employee_id'],
                    $cert['issued_date'], $cert['expiry_date'], $status,
                    $docPath, $id
                ]);
                auditLog('cert.update', "cert #{$id}", json_encode(['title' => $cert['title']]));
                setFlash('success', 'Certificado atualizado com sucesso!');
            } else {
                $hash = generateHash();
                $stmt = $db->prepare(
                    'INSERT INTO certificates
                       (employee_id, title, type, issued_date, expiry_date, status, hash, document)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $cert['employee_id'], $cert['title'], $cert['type'],
                    $cert['issued_date'], $cert['expiry_date'], $status, $hash, $docPath
                ]);
                $newId = $db->lastInsertId();
                auditLog('cert.create', "cert #{$newId}", json_encode(['title' => $cert['title']]));
                setFlash('success', 'Certificado cadastrado com sucesso! QR Code gerado.');
            }
            redirect('/admin/certificates.php');
        }
    }
}

$nrTypes = ['NR-10','NR-11','NR-12','NR-13','NR-18','NR-20','NR-33','NR-34','NR-35',
            'ISO 9001','ISO 14001','ISO 45001','CIPA','PPRA','PCMSO','Outro'];

$pageTitle = $isEdit ? 'Editar Certificado' : 'Novo Certificado';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="mb-6">
    <a href="<?= BASE_URL ?>/admin/certificates.php"
       class="text-sm text-gray-600 hover:text-industrial-blue inline-flex items-center gap-1">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Voltar
    </a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">
        <?= $isEdit ? 'Editar Certificado' : 'Novo Certificado' ?>
    </h1>
</div>

<?php if (!empty($errors)): ?>
    <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm space-y-1">
        <?php foreach ($errors as $err): ?><div>• <?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data"
      class="space-y-5 max-w-2xl">
    <?= csrfField() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
    <?php endif; ?>

    <!-- DADOS DO CERTIFICADO -->
    <div class="bg-white rounded-lg shadow-sm border p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide pb-2 border-b">Dados do Certificado</h2>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Funcionário *</label>
            <select name="employee_id" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
                <option value="">-- Selecione --</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= (int)$emp['id'] ?>"
                        <?= (int)$cert['employee_id'] === (int)$emp['id'] ? 'selected' : '' ?>>
                        <?= e($emp['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Título do Certificado *</label>
            <input type="text" name="title" value="<?= e($cert['title']) ?>" required
                   placeholder="Ex: NR-10 — Segurança em Instalações Elétricas"
                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo *</label>
            <div class="flex gap-2">
                <select id="typeSelect" onchange="syncType(this)"
                        class="px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
                    <option value="">-- Selecione ou digite --</option>
                    <?php foreach ($nrTypes as $t): ?>
                        <option value="<?= $t ?>" <?= $cert['type']===$t ? 'selected':'' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="type" id="typeInput" value="<?= e($cert['type']) ?>" required
                       placeholder="Ex: NR-10, ISO 9001..."
                       class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <p class="text-xs text-gray-400 mt-1">Selecione da lista ou escreva um tipo personalizado no campo ao lado.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Data de Emissão *</label>
                <input type="date" name="issued_date" value="<?= e($cert['issued_date']) ?>" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Data de Validade *</label>
                <input type="date" name="expiry_date" value="<?= e($cert['expiry_date']) ?>" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
        </div>
    </div>

    <!-- DOCUMENTO PDF -->
    <div class="bg-white rounded-lg shadow-sm border p-6 space-y-3">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide pb-2 border-b">Documento PDF (opcional)</h2>

        <?php if (!empty($cert['document'])): ?>
            <div class="flex items-center gap-3 p-3 bg-blue-50 rounded-lg border border-blue-200">
                <svg class="h-8 w-8 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-800">PDF anexado</p>
                    <p class="text-xs text-gray-500 truncate"><?= e(basename($cert['document'])) ?></p>
                </div>
                <a href="<?= BASE_URL . '/' . e($cert['document']) ?>" target="_blank"
                   class="text-xs text-industrial-blue hover:underline flex-shrink-0">Ver PDF</a>
            </div>
            <p class="text-xs text-gray-400">Envie um novo arquivo para substituir o atual.</p>
        <?php endif; ?>

        <div id="pdfDrop"
             class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center cursor-pointer hover:border-industrial-blue transition"
             onclick="document.getElementById('document_upload').click()">
            <svg class="h-8 w-8 text-gray-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="text-sm text-gray-600" id="pdfLabel">
                Clique para selecionar ou arraste o PDF aqui
            </p>
            <p class="text-xs text-gray-400 mt-1">Somente PDF • máx. 10 MB</p>
        </div>
        <input type="file" name="document_upload" id="document_upload" accept="application/pdf,.pdf"
               class="hidden" onchange="updatePdfLabel(this)">
    </div>

    <div class="flex gap-3">
        <button type="submit"
                class="bg-industrial-blue hover:bg-blue-800 text-white px-5 py-2 rounded-md font-medium transition">
            <?= $isEdit ? 'Salvar Alterações' : 'Cadastrar Certificado' ?>
        </button>
        <a href="<?= BASE_URL ?>/admin/certificates.php"
           class="px-5 py-2 border border-gray-300 rounded-md font-medium hover:bg-gray-50 transition">
            Cancelar
        </a>
    </div>
</form>

<script>
function syncType(sel) {
    if (sel.value) document.getElementById('typeInput').value = sel.value;
}
function updatePdfLabel(input) {
    const lbl = document.getElementById('pdfLabel');
    if (input.files && input.files[0]) {
        const kb = (input.files[0].size / 1024).toFixed(0);
        lbl.textContent = input.files[0].name + ' (' + kb + ' KB)';
        lbl.className = 'text-sm font-medium text-industrial-blue';
    }
}
const pdfDrop = document.getElementById('pdfDrop');
pdfDrop.addEventListener('dragover', e => { e.preventDefault(); pdfDrop.classList.add('border-industrial-blue','bg-blue-50'); });
pdfDrop.addEventListener('dragleave', () => pdfDrop.classList.remove('border-industrial-blue','bg-blue-50'));
pdfDrop.addEventListener('drop', e => {
    e.preventDefault();
    pdfDrop.classList.remove('border-industrial-blue','bg-blue-50');
    const file = e.dataTransfer.files[0];
    if (file) {
        const inp = document.getElementById('document_upload');
        const dt = new DataTransfer(); dt.items.add(file); inp.files = dt.files;
        updatePdfLabel(inp);
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
