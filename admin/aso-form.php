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

$asoTypes = [
    'admissional'      => 'Admissional',
    'periodico'        => 'Periódico',
    'retorno_trabalho' => 'Retorno ao Trabalho',
    'mudanca_risco'    => 'Mudança de Risco / Função',
    'demissional'      => 'Demissional',
];
$resultLabels = [
    'apto'     => 'Apto',
    'inapto'   => 'Inapto',
    'pendente' => 'Pendente',
];

// Riscos por categoria (conforme NR-7)
$risksPhysical   = ['Ruídos','Calor','Vibrações','Umidade','Radiações não-ionizantes','Radiações ionizantes','Frio','Pressões anormais'];
$risksChemical   = ['Poeiras','Fumos','Névoas','Neblinas','Gases','Vapores','Outros Químicos'];
$risksBiological = ['Vírus','Bactérias','Protozoários','Fungos','Parasitas'];
$risksErgonomic  = ['Esforço físico intenso','Levantamento/Transporte manual de peso','Postura inadequada','Trabalho em turnos e noturno','Monotonia e repetitividade','Ritmos excessivos','Controle rígido de produtividade'];
$risksAccident   = ['Arranjo físico inadequado','Eletricidade','Animais Peçonhentos','Máquinas e equipamentos sem proteção','Probabilidade de incêndio/explosão','Outras situações causadoras de acidentes','Ferramentas inadequadas','Iluminação inadequada','Outras situações não mencionadas'];

// Exames complementares comuns
$examsList = ['Audiometria','Acuidade visual','Hemograma completo','Glicemia de jejum','Colesterol e triglicerídeos','Raio-X de tórax','Eletrocardiograma','Espirometria','EEG','Urina rotina (EAS)','Toxicológico','PSA','Hepatite B (HbsAg)','Hepatite C','HIV','VDRL','PPD (Mantoux)'];

if ($isEdit) {
    $s = $db->prepare('SELECT a.* FROM employee_asos a JOIN employees e ON e.id = a.employee_id WHERE a.id = ? AND a.company_id = ? LIMIT 1');
    $s->execute([$id, $companyId]);
    $aso = $s->fetch();
    if (!$aso) { setFlash('error', 'ASO não encontrado.'); redirect('/admin/employees.php'); }
    $employeeId = (int)$aso['employee_id'];
}

$stmtEmp = $db->prepare('SELECT * FROM employees WHERE id = ? AND company_id = ? LIMIT 1');
$stmtEmp->execute([$employeeId, $companyId]);
$employee = $stmtEmp->fetch();
if (!$employee) { setFlash('error', 'Colaborador não encontrado.'); redirect('/admin/employees.php'); }

// Decode JSON fields do ASO existente
$asoRisksP  = json_decode($aso['risks_physical']   ?? '[]', true) ?: [];
$asoRisksQ  = json_decode($aso['risks_chemical']   ?? '[]', true) ?: [];
$asoRisksB  = json_decode($aso['risks_biological'] ?? '[]', true) ?: [];
$asoRisksE  = json_decode($aso['risks_ergonomic']  ?? '[]', true) ?: [];
$asoRisksA  = json_decode($aso['risks_accident']   ?? '[]', true) ?: [];
$asoExams   = json_decode($aso['exams_done']       ?? '[]', true) ?: [];

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) { die('CSRF inválido.'); }

    $asoType        = trim($_POST['aso_type']        ?? '');
    $examDate       = trim($_POST['exam_date']       ?? '');
    $expirationDate = trim($_POST['expiration_date'] ?? '');
    $resultStatus   = trim($_POST['result_status']   ?? '');
    $nextExamDate   = trim($_POST['next_exam_date']  ?? '') ?: null;

    // Médico
    $doctorName  = trim($_POST['doctor_name'] ?? '') ?: null;
    $doctorCrm   = trim($_POST['doctor_crm']  ?? '') ?: null;

    // Clínica
    $clinicName    = trim($_POST['clinic_name']    ?? '') ?: null;
    $clinicCnpj    = trim($_POST['clinic_cnpj']    ?? '') ?: null;
    $clinicAddress = trim($_POST['clinic_address'] ?? '') ?: null;

    // Dados trabalho
    $workLocation  = trim($_POST['work_location']  ?? '') ?: null;
    $workSector    = trim($_POST['work_sector']    ?? '') ?: null;
    $workFunction  = trim($_POST['work_function']  ?? '') ?: null;
    $admissionDate = trim($_POST['admission_date'] ?? '') ?: null;

    // SESMT
    $sesmtName     = trim($_POST['sesmt_name']     ?? '') ?: null;
    $sesmtRegister = trim($_POST['sesmt_register'] ?? '') ?: null;

    // Riscos (checkboxes → JSON)
    $risksP  = json_encode(array_values((array)($_POST['risks_physical']   ?? [])));
    $risksQ  = json_encode(array_values((array)($_POST['risks_chemical']   ?? [])));
    $risksBI = json_encode(array_values((array)($_POST['risks_biological'] ?? [])));
    $risksE  = json_encode(array_values((array)($_POST['risks_ergonomic']  ?? [])));
    $risksA  = json_encode(array_values((array)($_POST['risks_accident']   ?? [])));
    $risksOther = trim($_POST['risks_other'] ?? '') ?: null;

    // Exames
    $examsDone  = json_encode(array_values((array)($_POST['exams_done'] ?? [])));
    $examsOther = trim($_POST['exams_other'] ?? '') ?: null;

    // Observações
    $notes = trim($_POST['notes'] ?? '') ?: null;

    $filePath    = $aso['file_path']    ?? null;
    $fileOrigName= $aso['file_name']    ?? null; // guarda nome original para employee_documents
    $fileExt     = $aso['file_ext']     ?? null;
    $fileSize    = $aso['file_size']    ?? 0;
    $fileSha256  = $aso['file_sha256']  ?? null;
    $newFileUploaded = false;

    // Validações
    if (!array_key_exists($asoType, $asoTypes))         $errors[] = 'Tipo de ASO inválido.';
    if (!$examDate)                                      $errors[] = 'Data do exame é obrigatória.';
    if (!$expirationDate)                                $errors[] = 'Data de validade é obrigatória.';
    if (!array_key_exists($resultStatus, $resultLabels)) $errors[] = 'Resultado inválido.';
    if ($examDate && $expirationDate && $expirationDate < $examDate)
        $errors[] = 'A validade não pode ser anterior à data do exame.';

    // Upload — aceita PDF, JPG, PNG, WEBP
    if (!empty($_FILES['aso_file']['name'])) {
        $up = $_FILES['aso_file'];
        if ($up['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Erro no upload (código ' . $up['error'] . ').';
        } elseif ($up['size'] > 20 * 1024 * 1024) {
            $errors[] = 'Arquivo muito grande (máximo 20 MB).';
        } else {
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $realMime = $finfo->file($up['tmp_name']);
            $allowedMimes = [
                'application/pdf' => 'pdf',
                'image/jpeg'      => 'jpg',
                'image/png'       => 'png',
                'image/webp'      => 'webp',
                'image/gif'       => 'gif',
            ];
            if (!isset($allowedMimes[$realMime])) {
                $errors[] = 'Formato não permitido. Use PDF, JPG, PNG ou WEBP.';
            } else {
                $asoDir  = __DIR__ . '/../uploads/asos/';
                if (!is_dir($asoDir)) mkdir($asoDir, 0755, true);
                $ext      = $allowedMimes[$realMime];
                $filename = bin2hex(random_bytes(16)) . '.' . $ext;
                if (move_uploaded_file($up['tmp_name'], $asoDir . $filename)) {
                    // Remove arquivo antigo
                    if ($filePath) {
                        $old = __DIR__ . '/../' . ltrim($filePath, '/');
                        if (file_exists($old)) @unlink($old);
                    }
                    $filePath        = 'uploads/asos/' . $filename;
                    $fileOrigName    = $up['name'];
                    $fileExt         = $ext;
                    $fileSize        = $up['size'];
                    $fileSha256      = hash_file('sha256', $asoDir . $filename) ?: null;
                    $newFileUploaded = true;
                } else {
                    $errors[] = 'Falha ao salvar o arquivo.';
                }
            }
        }
    }

    if (empty($errors)) {
        $today = date('Y-m-d');
        $in30  = date('Y-m-d', strtotime('+30 days'));
        $docStatus = $expirationDate < $today ? 'vencido' : ($expirationDate <= $in30 ? 'vencendo' : 'valido');

        $fields = [
            $asoType, $examDate, $expirationDate, $resultStatus, $docStatus,
            $doctorName, $doctorCrm,
            $clinicName, $clinicCnpj, $clinicAddress,
            $workLocation, $workSector, $workFunction, $admissionDate,
            $risksP, $risksQ, $risksBI, $risksE, $risksA, $risksOther,
            $examsDone, $examsOther,
            $nextExamDate, $sesmtName, $sesmtRegister,
            $filePath, $notes,
        ];

        if ($isEdit) {
            $db->prepare(
                'UPDATE employee_asos SET
                 aso_type=?, exam_date=?, expiration_date=?, result_status=?, document_status=?,
                 doctor_name=?, doctor_crm=?,
                 clinic_name=?, clinic_cnpj=?, clinic_address=?,
                 work_location=?, work_sector=?, work_function=?, admission_date=?,
                 risks_physical=?, risks_chemical=?, risks_biological=?, risks_ergonomic=?, risks_accident=?, risks_other=?,
                 exams_done=?, exams_other=?,
                 next_exam_date=?, sesmt_name=?, sesmt_register=?,
                 file_path=?, file_sha256=?, notes=?, updated_at=NOW()
                 WHERE id=? AND company_id=?'
            )->execute(array_merge(array_slice($fields, 0, -2), [$filePath, $fileSha256, $notes, $id, $companyId]));
            auditLog('aso.update', "aso #{$id}", json_encode(['employee_id' => $employeeId]));

            // ── Espelha arquivo em employee_documents (se novo upload) ──────
            if ($newFileUploaded && $filePath) {
                $asoTypeLabel = $asoTypes[$asoType] ?? 'ASO';
                $docTitle     = 'ASO ' . $asoTypeLabel . ' – ' . $employee['name'] . ' (' . date('d/m/Y', strtotime($examDate)) . ')';
                $docSt2       = match($docStatus) { 'vencido' => 'vencido', 'vencendo' => 'vencendo', default => 'valido' };
                // Atualiza se já existe, senão insere
                $existDoc = $db->prepare('SELECT id FROM employee_documents WHERE employee_id=? AND company_id=? AND doc_type="aso" LIMIT 1');
                $existDoc->execute([$employeeId, $companyId]);
                $edRow = $existDoc->fetch();
                if ($edRow) {
                    $db->prepare('UPDATE employee_documents SET title=?, file_path=?, file_sha256=?, file_name=?, file_ext=?, file_size=?, issue_date=?, expiry_date=?, status=?, updated_at=NOW() WHERE id=?')
                       ->execute([$docTitle, $filePath, $fileSha256, $fileOrigName, $fileExt, $fileSize, $examDate, $expirationDate, $docSt2, $edRow['id']]);
                } else {
                    $db->prepare('INSERT INTO employee_documents (company_id,employee_id,doc_type,title,description,file_path,file_sha256,file_name,file_ext,file_size,issue_date,expiry_date,status,uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                       ->execute([$companyId,$employeeId,'aso',$docTitle,'Documento ASO gerado pelo formulário de Saúde Ocupacional.',$filePath,$fileSha256,$fileOrigName,$fileExt,$fileSize,$examDate,$expirationDate,$docSt2,$user['id']]);
                }
            }

            setFlash('success', 'ASO atualizado com sucesso.' . ($newFileUploaded ? ' Documento também salvo em Documentos.' : ''));
        } else {
            $db->prepare(
                'INSERT INTO employee_asos
                 (company_id, employee_id,
                  aso_type, exam_date, expiration_date, result_status, document_status,
                  doctor_name, doctor_crm,
                  clinic_name, clinic_cnpj, clinic_address,
                  work_location, work_sector, work_function, admission_date,
                  risks_physical, risks_chemical, risks_biological, risks_ergonomic, risks_accident, risks_other,
                  exams_done, exams_other,
                  next_exam_date, sesmt_name, sesmt_register,
                 file_path, file_sha256, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute(array_merge([$companyId, $employeeId], array_slice($fields, 0, -2), [$filePath, $fileSha256, $notes, $user['id']]));
            $newId = $db->lastInsertId();
            auditLog('aso.create', "aso #{$newId}", json_encode(['employee_id' => $employeeId]));

            // ── Espelha arquivo em employee_documents (novo ASO com arquivo) ─
            if ($newFileUploaded && $filePath) {
                $asoTypeLabel = $asoTypes[$asoType] ?? 'ASO';
                $docTitle     = 'ASO ' . $asoTypeLabel . ' – ' . $employee['name'] . ' (' . date('d/m/Y', strtotime($examDate)) . ')';
                $docSt2       = match($docStatus) { 'vencido' => 'vencido', 'vencendo' => 'vencendo', default => 'valido' };
                $db->prepare('INSERT INTO employee_documents (company_id,employee_id,doc_type,title,description,file_path,file_sha256,file_name,file_ext,file_size,issue_date,expiry_date,status,uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$companyId,$employeeId,'aso',$docTitle,'Documento ASO gerado pelo formulário de Saúde Ocupacional.',$filePath,$fileSha256,$fileOrigName,$fileExt,$fileSize,$examDate,$expirationDate,$docSt2,$user['id']]);
            }

            setFlash('success', 'ASO cadastrado com sucesso.' . ($newFileUploaded ? ' Documento também salvo em Documentos.' : ''));
        }
        redirect('/admin/employee-details.php?id=' . $employeeId . '&tab=aso');
    }
}

$pageTitle = $isEdit ? 'Editar ASO' : 'Novo ASO';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- ══ VOLTAR + TÍTULO ═══════════════════════════════════════════════════ -->
<div class="mb-6">
    <a href="<?= BASE_URL ?>/admin/employee-details.php?id=<?= $employeeId ?>&tab=aso"
       class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-industrial-blue transition">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Voltar para <?= e($employee['name']) ?>
    </a>

    <div class="mt-3 flex items-center gap-4">
        <div class="h-12 w-12 rounded-xl bg-blue-100 flex items-center justify-center flex-shrink-0">
            <svg class="h-6 w-6 text-industrial-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
        </div>
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= $isEdit ? 'Editar ASO' : 'Novo ASO' ?></h1>
            <p class="text-sm text-gray-500">
                Colaborador: <strong><?= e($employee['name']) ?></strong>
                · <?= e($employee['role']) ?>
                <?php if (!empty($employee['cpf'])): ?> · CPF: <?= e($employee['cpf']) ?><?php endif; ?>
            </p>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 text-sm space-y-1">
    <p class="font-semibold mb-1">⚠️ Corrija os erros abaixo:</p>
    <?php foreach ($errors as $err): ?><div class="flex items-center gap-2">• <?= e($err) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="space-y-5 max-w-4xl">
<?= csrfField() ?>

<!-- ══ 1. IDENTIFICAÇÃO DO ASO ══════════════════════════════════════════ -->
<div class="section-card">
    <div class="section-card-header">
        <h2 class="section-card-title flex items-center gap-2">
            <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">1</span>
            Identificação do ASO
        </h2>
    </div>
    <div class="section-card-body grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

        <div>
            <label class="form-label">Tipo de Exame *</label>
            <select name="aso_type" required class="form-select w-full">
                <?php foreach ($asoTypes as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= ($aso['aso_type'] ?? 'admissional') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="form-label">Resultado *</label>
            <select name="result_status" required class="form-select w-full">
                <?php foreach ($resultLabels as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= ($aso['result_status'] ?? 'pendente') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="form-label">Data do Exame *</label>
            <input type="date" name="exam_date" required class="form-input w-full"
                   value="<?= e($aso['exam_date'] ?? '') ?>">
        </div>

        <div>
            <label class="form-label">Data de Validade *</label>
            <input type="date" name="expiration_date" required class="form-input w-full"
                   id="inp-expiration"
                   value="<?= e($aso['expiration_date'] ?? '') ?>">
        </div>

        <div>
            <label class="form-label">Próximo Exame Previsto</label>
            <input type="date" name="next_exam_date" class="form-input w-full"
                   value="<?= e($aso['next_exam_date'] ?? '') ?>">
        </div>

        <div>
            <label class="form-label">Data de Admissão</label>
            <input type="date" name="admission_date" class="form-input w-full"
                   value="<?= e($aso['admission_date'] ?? $employee['created_at'] ?? '') ?>">
        </div>

    </div>
</div>

<!-- ══ 2. EMPRESA / LOCAL DE TRABALHO ══════════════════════════════════ -->
<div class="section-card">
    <div class="section-card-header">
        <h2 class="section-card-title flex items-center gap-2">
            <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">2</span>
            Local de Trabalho
        </h2>
    </div>
    <div class="section-card-body grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

        <div class="sm:col-span-2">
            <label class="form-label">Local de Trabalho / Estabelecimento</label>
            <input type="text" name="work_location" maxlength="255" class="form-input w-full"
                   placeholder="Ex: Filial Centro – Rua das Indústrias, 100"
                   value="<?= e($aso['work_location'] ?? '') ?>">
        </div>

        <div>
            <label class="form-label">Setor</label>
            <input type="text" name="work_sector" maxlength="150" class="form-input w-full"
                   placeholder="Ex: Manutenção Elétrica"
                   value="<?= e($aso['work_sector'] ?? $employee['department'] ?? '') ?>">
        </div>

        <div class="sm:col-span-2">
            <label class="form-label">Função / Cargo no momento do exame</label>
            <input type="text" name="work_function" maxlength="150" class="form-input w-full"
                   placeholder="Ex: Eletricista de Manutenção"
                   value="<?= e($aso['work_function'] ?? $employee['role'] ?? '') ?>">
        </div>

    </div>
</div>

<!-- ══ 3. RISCOS OCUPACIONAIS ═══════════════════════════════════════════ -->
<div class="section-card">
    <div class="section-card-header">
        <h2 class="section-card-title flex items-center gap-2">
            <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">3</span>
            Riscos Ocupacionais
            <span class="text-xs font-normal text-gray-400 ml-1">(conforme NR-7)</span>
        </h2>
    </div>
    <div class="section-card-body space-y-5">

        <?php
        $riskGroups = [
            ['label'=>'Físicos',      'key'=>'risks_physical',   'color'=>'blue',   'items'=>$risksPhysical,   'checked'=>$asoRisksP],
            ['label'=>'Químicos',     'key'=>'risks_chemical',   'color'=>'orange', 'items'=>$risksChemical,   'checked'=>$asoRisksQ],
            ['label'=>'Biológicos',   'key'=>'risks_biological', 'color'=>'green',  'items'=>$risksBiological, 'checked'=>$asoRisksB],
            ['label'=>'Ergonômicos',  'key'=>'risks_ergonomic',  'color'=>'purple', 'items'=>$risksErgonomic,  'checked'=>$asoRisksE],
            ['label'=>'Acidentes',    'key'=>'risks_accident',   'color'=>'red',    'items'=>$risksAccident,   'checked'=>$asoRisksA],
        ];
        foreach ($riskGroups as $grp):
            $c = $grp['color'];
        ?>
        <div>
            <div class="flex items-center gap-2 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-<?= $c ?>-700 bg-<?= $c ?>-50 border border-<?= $c ?>-200 px-2 py-0.5 rounded-full">
                    <?= $grp['label'] ?>
                </span>
                <button type="button" class="text-xs text-gray-400 hover:text-industrial-blue"
                        onclick="toggleAll('<?= $grp['key'] ?>')">Marcar todos</button>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                <?php foreach ($grp['items'] as $item): ?>
                <label class="flex items-start gap-2 p-2 rounded-lg border border-gray-100 hover:bg-gray-50 cursor-pointer
                              has-[:checked]:bg-<?= $c ?>-50 has-[:checked]:border-<?= $c ?>-300 transition">
                    <input type="checkbox" name="<?= $grp['key'] ?>[]" value="<?= htmlspecialchars($item) ?>"
                           class="mt-0.5 flex-shrink-0 accent-industrial-blue"
                           <?= in_array($item, $grp['checked']) ? 'checked' : '' ?>>
                    <span class="text-xs text-gray-700 leading-tight"><?= htmlspecialchars($item) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div>
            <label class="form-label">Outros Riscos</label>
            <input type="text" name="risks_other" maxlength="500" class="form-input w-full"
                   placeholder="Descreva outros riscos não listados acima..."
                   value="<?= e($aso['risks_other'] ?? '') ?>">
        </div>

    </div>
</div>

<!-- ══ 4. EXAMES REALIZADOS ═════════════════════════════════════════════ -->
<div class="section-card">
    <div class="section-card-header">
        <h2 class="section-card-title flex items-center gap-2">
            <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">4</span>
            Exames Complementares Realizados
        </h2>
    </div>
    <div class="section-card-body space-y-4">

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
            <?php foreach ($examsList as $exam): ?>
            <label class="flex items-start gap-2 p-2 rounded-lg border border-gray-100 hover:bg-gray-50 cursor-pointer
                          has-[:checked]:bg-emerald-50 has-[:checked]:border-emerald-300 transition">
                <input type="checkbox" name="exams_done[]" value="<?= htmlspecialchars($exam) ?>"
                       class="mt-0.5 flex-shrink-0 accent-industrial-blue"
                       <?= in_array($exam, $asoExams) ? 'checked' : '' ?>>
                <span class="text-xs text-gray-700 leading-tight"><?= htmlspecialchars($exam) ?></span>
            </label>
            <?php endforeach; ?>
        </div>

        <div>
            <label class="form-label">Outros Exames</label>
            <input type="text" name="exams_other" maxlength="500" class="form-input w-full"
                   placeholder="Ex: Dermatológico, Ortopédico..."
                   value="<?= e($aso['exams_other'] ?? '') ?>">
        </div>

    </div>
</div>

<!-- ══ 5. MÉDICO RESPONSÁVEL ════════════════════════════════════════════ -->
<div class="section-card">
    <div class="section-card-header">
        <h2 class="section-card-title flex items-center gap-2">
            <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">5</span>
            Médico Responsável pelo Exame
        </h2>
    </div>
    <div class="section-card-body grid grid-cols-1 sm:grid-cols-2 gap-4">

        <div class="sm:col-span-2 sm:grid sm:grid-cols-2 sm:gap-4">
            <div>
                <label class="form-label">Nome do Médico</label>
                <input type="text" name="doctor_name" maxlength="150" class="form-input w-full"
                       placeholder="Dr. João Silva"
                       value="<?= e($aso['doctor_name'] ?? '') ?>">
            </div>
            <div>
                <label class="form-label">CRM</label>
                <input type="text" name="doctor_crm" maxlength="30" class="form-input w-full"
                       placeholder="CRM/SP 123456"
                       value="<?= e($aso['doctor_crm'] ?? '') ?>">
            </div>
        </div>

    </div>
</div>

<!-- ══ 6. EMPRESA EXAMINADORA ══════════════════════════════════════════ -->
<div class="section-card">
    <div class="section-card-header">
        <h2 class="section-card-title flex items-center gap-2">
            <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">6</span>
            Empresa / Clínica Examinadora
        </h2>
    </div>
    <div class="section-card-body grid grid-cols-1 sm:grid-cols-2 gap-4">

        <div>
            <label class="form-label">Nome da Clínica / Empresa</label>
            <input type="text" name="clinic_name" maxlength="255" class="form-input w-full"
                   placeholder="Ex: Med Fácil Medicina do Trabalho"
                   value="<?= e($aso['clinic_name'] ?? '') ?>">
        </div>

        <div>
            <label class="form-label">CNPJ</label>
            <input type="text" name="clinic_cnpj" maxlength="20" class="form-input w-full"
                   placeholder="00.000.000/0001-00"
                   value="<?= e($aso['clinic_cnpj'] ?? '') ?>">
        </div>

        <div class="sm:col-span-2">
            <label class="form-label">Endereço da Clínica</label>
            <input type="text" name="clinic_address" maxlength="500" class="form-input w-full"
                   placeholder="Ex: Av. Contorno, 556 – Centro – Anápolis/GO"
                   value="<?= e($aso['clinic_address'] ?? '') ?>">
        </div>

    </div>
</div>

<!-- ══ 7. RESPONSÁVEL SESMT ════════════════════════════════════════════ -->
<div class="section-card">
    <div class="section-card-header">
        <h2 class="section-card-title flex items-center gap-2">
            <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">7</span>
            Responsável Técnico / SESMT
            <span class="text-xs font-normal text-gray-400 ml-1">(opcional)</span>
        </h2>
    </div>
    <div class="section-card-body grid grid-cols-1 sm:grid-cols-2 gap-4">

        <div>
            <label class="form-label">Nome do Responsável</label>
            <input type="text" name="sesmt_name" maxlength="150" class="form-input w-full"
                   placeholder="Ex: Eng. Carlos Souza"
                   value="<?= e($aso['sesmt_name'] ?? '') ?>">
        </div>

        <div>
            <label class="form-label">Registro Profissional (CREA / CRM / COREN...)</label>
            <input type="text" name="sesmt_register" maxlength="50" class="form-input w-full"
                   placeholder="Ex: CREA-GO 123456"
                   value="<?= e($aso['sesmt_register'] ?? '') ?>">
        </div>

    </div>
</div>

<!-- ══ 8. DOCUMENTO PDF + OBSERVAÇÕES ══════════════════════════════════ -->
<div class="section-card">
    <div class="section-card-header">
        <h2 class="section-card-title flex items-center gap-2">
            <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">8</span>
            Documento e Observações
        </h2>
    </div>
    <div class="section-card-body space-y-4">

        <div>
            <label class="form-label">
                Arquivo do ASO
                <span class="text-xs font-normal text-gray-400 ml-1">— acesso restrito, não público · salvo automaticamente em Documentos</span>
            </label>
            <?php if (!empty($aso['file_path'])): ?>
            <div class="flex items-center gap-3 mb-3 p-3 bg-green-50 border border-green-200 rounded-lg">
                <svg class="h-5 w-5 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <div class="flex-1">
                    <p class="text-sm text-green-800 font-medium">Documento anexado.</p>
                    <p class="text-xs text-green-600">Selecione outro arquivo para substituir.</p>
                </div>
                <a href="<?= BASE_URL ?>/admin/aso-download.php?id=<?= $id ?>" target="_blank"
                   class="btn btn-secondary btn-sm">Ver atual</a>
            </div>
            <?php endif; ?>

            <!-- Drop zone -->
            <div id="aso-drop-zone"
                 class="border-2 border-dashed border-gray-300 rounded-xl p-6 text-center cursor-pointer
                        hover:border-industrial-blue/50 hover:bg-blue-50/30 transition"
                 onclick="document.getElementById('aso-file-input').click()">
                <div id="aso-drop-content">
                    <svg class="h-9 w-9 mx-auto text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <p class="text-gray-500 font-medium text-sm">Clique ou arraste o arquivo aqui</p>
                    <p class="text-xs text-gray-400 mt-1">PDF, JPG, PNG, WEBP · máx. 20 MB</p>
                    <div class="flex items-center justify-center gap-3 mt-3">
                        <span class="px-2 py-0.5 bg-red-50 border border-red-200 text-red-700 text-xs font-bold rounded">PDF</span>
                        <span class="px-2 py-0.5 bg-blue-50 border border-blue-200 text-blue-700 text-xs font-bold rounded">JPG</span>
                        <span class="px-2 py-0.5 bg-green-50 border border-green-200 text-green-700 text-xs font-bold rounded">PNG</span>
                        <span class="px-2 py-0.5 bg-purple-50 border border-purple-200 text-purple-700 text-xs font-bold rounded">WEBP</span>
                    </div>
                </div>
                <div id="aso-drop-preview" class="hidden"></div>
            </div>
            <input type="file" id="aso-file-input" name="aso_file" class="hidden"
                   accept=".pdf,.jpg,.jpeg,.png,.webp,.gif">
            <p class="text-xs text-gray-400 mt-2 flex items-center gap-1">
                <svg class="h-3.5 w-3.5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                O arquivo será salvo automaticamente na aba
                <strong>Documentos</strong> do colaborador com o tipo ASO.
            </p>
        </div>

        <div>
            <label class="form-label">
                Observação Interna
                <span class="text-xs font-normal text-gray-400 ml-1">— não exibida publicamente</span>
            </label>
            <textarea name="notes" rows="3" maxlength="1000" class="form-input w-full resize-none"
                      placeholder="Ex: colaborador relatou histórico de hipertensão; médico orientou monitoramento..."><?= e($aso['notes'] ?? '') ?></textarea>
        </div>

    </div>
</div>

<!-- ══ BOTÕES ═══════════════════════════════════════════════════════════ -->
<div class="flex items-center gap-3 pb-6">
    <button type="submit" class="btn btn-primary">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
        <?= $isEdit ? 'Salvar Alterações' : 'Cadastrar ASO' ?>
    </button>
    <a href="<?= BASE_URL ?>/admin/employee-details.php?id=<?= $employeeId ?>&tab=aso"
       class="btn btn-secondary">Cancelar</a>
</div>

</form>

<script>
// ── Drop Zone do ASO ──────────────────────────────────────────────────
const asoDropZone  = document.getElementById('aso-drop-zone');
const asoFileInput = document.getElementById('aso-file-input');

asoFileInput.addEventListener('change', function() {
    if (this.files[0]) handleAsoFile(this.files[0]);
});
asoDropZone.addEventListener('dragover', e => {
    e.preventDefault();
    asoDropZone.classList.add('border-industrial-blue','bg-blue-50');
});
asoDropZone.addEventListener('dragleave', () => {
    asoDropZone.classList.remove('border-industrial-blue','bg-blue-50');
});
asoDropZone.addEventListener('drop', e => {
    e.preventDefault();
    asoDropZone.classList.remove('border-industrial-blue','bg-blue-50');
    const f = e.dataTransfer.files[0];
    if (f) { asoFileInput.files = e.dataTransfer.files; handleAsoFile(f); }
});

function handleAsoFile(file) {
    const ext    = file.name.split('.').pop().toLowerCase();
    const isImg  = ['jpg','jpeg','png','gif','webp'].includes(ext);
    const size   = file.size < 1024*1024
        ? (file.size/1024).toFixed(1)+' KB'
        : (file.size/1024/1024).toFixed(1)+' MB';

    document.getElementById('aso-drop-content').classList.add('hidden');
    const preview = document.getElementById('aso-drop-preview');
    preview.classList.remove('hidden');

    if (isImg) {
        const reader = new FileReader();
        reader.onload = e => {
            preview.innerHTML = `
                <img src="${e.target.result}"
                     class="max-h-48 mx-auto rounded-lg shadow mb-2 object-contain">
                <p class="text-sm font-medium text-gray-700">${file.name}</p>
                <p class="text-xs text-gray-400">${size}</p>
                <p class="text-xs text-green-600 mt-1 font-medium">✓ Imagem pronta para envio</p>`;
        };
        reader.readAsDataURL(file);
    } else {
        preview.innerHTML = `
            <div class="text-4xl mb-2">📄</div>
            <p class="text-sm font-semibold text-gray-700">${file.name}</p>
            <p class="text-xs text-gray-400">${size}</p>
            <p class="text-xs text-green-600 mt-1 font-medium">✓ PDF pronto para envio</p>`;
    }
}

// Auto-calcula validade com base no tipo e data do exame
document.querySelector('[name=exam_date]').addEventListener('change', function() {
    const type = document.querySelector('[name=aso_type]').value;
    const d    = new Date(this.value);
    if (!d) return;
    const validity = document.getElementById('inp-expiration');
    if (validity.value) return; // não sobrescreve se já preenchido

    // Validades típicas por tipo
    const months = { admissional: 12, periodico: 12, retorno_trabalho: 6, mudanca_risco: 12, demissional: 0 };
    const m = months[type] ?? 12;
    if (m === 0) return;
    d.setMonth(d.getMonth() + m);
    validity.value = d.toISOString().split('T')[0];
});

// Marca/desmarca todos de um grupo
function toggleAll(groupKey) {
    const boxes = document.querySelectorAll('[name="' + groupKey + '[]"]');
    const allChecked = Array.from(boxes).every(b => b.checked);
    boxes.forEach(b => b.checked = !allChecked);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
