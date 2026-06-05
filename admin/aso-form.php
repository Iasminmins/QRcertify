<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$id         = (int)($_GET['id']          ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? 0);
$isEdit     = $id > 0;
$errors     = [];
$aso        = null;

// Tipos e labels
$asoTypes = [
    'admissional'      => 'Admissional',
    'periodico'        => 'Periódico',
    'retorno_trabalho' => 'Retorno ao Trabalho',
    'mudanca_risco'    => 'Mudança de Risco',
    'demissional'      => 'Demissional',
];
$resultLabels = [
    'apto'     => 'Apto',
    'inapto'   => 'Inapto',
    'pendente' => 'Pendente',
];

// Carrega ASO em edição
if ($isEdit) {
    $s = $db->prepare(
        'SELECT a.* FROM employee_asos a
         JOIN employees e ON e.id = a.employee_id
         WHERE a.id = ? AND a.company_id = ? LIMIT 1'
    );
    $s->execute([$id, $companyId]);
    $aso = $s->fetch();
    if (!$aso) {
        setFlash('error', 'ASO não encontrado.');
        redirect('/admin/employees.php');
    }
    $employeeId = (int)$aso['employee_id'];
}

// Carrega colaborador
$stmtEmp = $db->prepare('SELECT * FROM employees WHERE id = ? AND company_id = ? LIMIT 1');
$stmtEmp->execute([$employeeId, $companyId]);
$employee = $stmtEmp->fetch();
if (!$employee) {
    setFlash('error', 'Colaborador não encontrado.');
    redirect('/admin/employees.php');
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) { die('CSRF inválido.'); }

    $asoType       = trim($_POST['aso_type']       ?? '');
    $examDate      = trim($_POST['exam_date']       ?? '');
    $expirationDate= trim($_POST['expiration_date'] ?? '');
    $resultStatus  = trim($_POST['result_status']   ?? '');
    $doctorName    = trim($_POST['doctor_name']     ?? '') ?: null;
    $doctorCrm     = trim($_POST['doctor_crm']      ?? '') ?: null;
    $notes         = trim($_POST['notes']           ?? '') ?: null;
    $filePath      = $aso['file_path'] ?? null;

    // Validações
    if (!array_key_exists($asoType, $asoTypes))       $errors[] = 'Tipo de ASO inválido.';
    if (!$examDate)                                    $errors[] = 'Data do exame é obrigatória.';
    if (!$expirationDate)                              $errors[] = 'Data de validade é obrigatória.';
    if (!array_key_exists($resultStatus, $resultLabels)) $errors[] = 'Resultado inválido.';
    if ($examDate && $expirationDate && $expirationDate < $examDate)
        $errors[] = 'A validade não pode ser anterior à data do exame.';

    // Upload PDF
    if (!empty($_FILES['aso_file']['name'])) {
        $up = $_FILES['aso_file'];
        if ($up['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Erro no upload do arquivo (código ' . $up['error'] . ').';
        } else {
            $maxBytes = 10 * 1024 * 1024; // 10 MB
            if ($up['size'] > $maxBytes) {
                $errors[] = 'Arquivo muito grande (máximo 10 MB).';
            } else {
                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $realMime = $finfo->file($up['tmp_name']);
                if ($realMime !== 'application/pdf') {
                    $errors[] = 'Apenas arquivos PDF são aceitos.';
                } else {
                    $asoDir = __DIR__ . '/../uploads/asos/';
                    if (!is_dir($asoDir)) mkdir($asoDir, 0755, true);
                    $filename = bin2hex(random_bytes(16)) . '.pdf';
                    if (move_uploaded_file($up['tmp_name'], $asoDir . $filename)) {
                        // Remove arquivo antigo
                        if ($filePath) {
                            $old = __DIR__ . '/../' . ltrim($filePath, '/');
                            if (file_exists($old)) @unlink($old);
                        }
                        $filePath = 'uploads/asos/' . $filename;
                    } else {
                        $errors[] = 'Falha ao salvar o arquivo. Verifique as permissões da pasta.';
                    }
                }
            }
        }
    }

    // Calcula document_status
    if (empty($errors)) {
        $today = date('Y-m-d');
        $in30  = date('Y-m-d', strtotime('+30 days'));
        if ($expirationDate < $today) {
            $docStatus = 'vencido';
        } elseif ($expirationDate <= $in30) {
            $docStatus = 'vencendo';
        } else {
            $docStatus = 'valido';
        }

        if ($isEdit) {
            $s = $db->prepare(
                'UPDATE employee_asos
                 SET aso_type=?, exam_date=?, expiration_date=?, result_status=?,
                     document_status=?, doctor_name=?, doctor_crm=?, file_path=?, notes=?,
                     updated_at=NOW()
                 WHERE id=? AND company_id=?'
            );
            $s->execute([
                $asoType, $examDate, $expirationDate, $resultStatus,
                $docStatus, $doctorName, $doctorCrm, $filePath, $notes,
                $id, $companyId
            ]);
            auditLog('aso.update', "aso #{$id}", json_encode(['employee_id' => $employeeId]));
            setFlash('success', 'ASO atualizado com sucesso.');
        } else {
            $s = $db->prepare(
                'INSERT INTO employee_asos
                 (company_id, employee_id, aso_type, exam_date, expiration_date,
                  result_status, document_status, doctor_name, doctor_crm,
                  file_path, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $s->execute([
                $companyId, $employeeId, $asoType, $examDate, $expirationDate,
                $resultStatus, $docStatus, $doctorName, $doctorCrm,
                $filePath, $notes, $user['id']
            ]);
            $newId = $db->lastInsertId();
            auditLog('aso.create', "aso #{$newId}", json_encode(['employee_id' => $employeeId]));
            setFlash('success', 'ASO cadastrado com sucesso.');
        }
        redirect('/admin/employee-details.php?id=' . $employeeId);
    }
}

$pageTitle = $isEdit ? 'Editar ASO' : 'Novo ASO';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="mb-5">
    <a href="<?= BASE_URL ?>/admin/employee-details.php?id=<?= $employeeId ?>"
       class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-industrial-blue transition">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Voltar para <?= e($employee['name']) ?>
    </a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2"><?= $isEdit ? 'Editar ASO' : 'Novo ASO' ?></h1>
    <p class="text-sm text-gray-500 mt-0.5">Colaborador: <strong><?= e($employee['name']) ?></strong> · <?= e($employee['role']) ?></p>
</div>

<?php if (!empty($errors)): ?>
<div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm space-y-1">
    <?php foreach ($errors as $err): ?><div>• <?= e($err) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="space-y-5 max-w-2xl">
    <?= csrfField() ?>

    <!-- Identificação do ASO -->
    <div class="bg-white rounded-lg shadow-sm border p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide pb-2 border-b">Identificação do ASO</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de ASO *</label>
                <select name="aso_type" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
                    <?php foreach ($asoTypes as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($aso['aso_type'] ?? '') === $val ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Resultado *</label>
                <select name="result_status" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
                    <?php foreach ($resultLabels as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($aso['result_status'] ?? '') === $val ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Data do Exame *</label>
                <input type="date" name="exam_date" required
                       value="<?= e($aso['exam_date'] ?? '') ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Data de Validade *</label>
                <input type="date" name="expiration_date" required
                       value="<?= e($aso['expiration_date'] ?? '') ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
        </div>
    </div>

    <!-- Médico (opcional) -->
    <div class="bg-white rounded-lg shadow-sm border p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide pb-2 border-b">Médico Responsável <span class="text-gray-400 font-normal normal-case">(opcional)</span></h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Nome do Médico</label>
                <input type="text" name="doctor_name" maxlength="150"
                       placeholder="Dr. João Silva"
                       value="<?= e($aso['doctor_name'] ?? '') ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">CRM</label>
                <input type="text" name="doctor_crm" maxlength="30"
                       placeholder="CRM/SP 123456"
                       value="<?= e($aso['doctor_crm'] ?? '') ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
        </div>
    </div>

    <!-- Documento e Observação -->
    <div class="bg-white rounded-lg shadow-sm border p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide pb-2 border-b">Documento e Observação</h2>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">PDF do ASO <span class="text-gray-400 font-normal">(opcional)</span></label>
            <?php if (!empty($aso['file_path'])): ?>
                <p class="text-xs text-green-700 bg-green-50 border border-green-200 rounded px-2 py-1 mb-2 inline-block">
                    ✓ Documento anexado. Envie outro para substituir.
                </p>
            <?php endif; ?>
            <input type="file" name="aso_file" accept="application/pdf"
                   class="w-full text-sm text-gray-600 border border-gray-300 rounded-md px-3 py-1.5
                          file:mr-3 file:py-1 file:px-3 file:rounded file:border-0
                          file:text-sm file:bg-blue-50 file:text-industrial-blue hover:file:bg-blue-100">
            <p class="text-xs text-gray-400 mt-1">Apenas PDF · máx. 10 MB · acesso restrito a usuários com login</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Observação Interna <span class="text-gray-400 font-normal">(opcional · não exibida publicamente)</span></label>
            <textarea name="notes" rows="3" maxlength="1000"
                      placeholder="Observações internas sobre este ASO..."
                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none resize-none"><?= e($aso['notes'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="btn btn-primary">
            <?= $isEdit ? 'Salvar Alterações' : 'Cadastrar ASO' ?>
        </button>
        <a href="<?= BASE_URL ?>/admin/employee-details.php?id=<?= $employeeId ?>" class="btn btn-secondary">
            Cancelar
        </a>
    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
