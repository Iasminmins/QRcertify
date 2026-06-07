<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user = currentUser();
$companyId = $user['company_id'];
$db = getDB();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;
$errors = [];

$employee = [
    'name' => '', 'email' => '', 'cpf' => '', 'role' => '', 'department' => '',
    'manager_name' => '', 'department_id' => null, 'phone' => '', 'photo' => '', 'status' => 'active',
    'authorized_ops' => null,
    'cep' => '', 'address' => '', 'number' => '',
    'complement' => '', 'neighborhood' => '', 'city' => '', 'state' => '',
];

// Carrega áreas da empresa para o select
$deptStmt = $db->prepare('SELECT id, name, manager_name FROM departments WHERE company_id = ? ORDER BY name ASC');
$deptStmt->execute([$companyId]);
$deptOptions = $deptStmt->fetchAll();

if ($isEdit) {
    $stmt = $db->prepare('SELECT * FROM employees WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    $row = $stmt->fetch();
    if (!$row) {
        setFlash('error', 'Funcionário não encontrado.');
        redirect('/admin/employees.php');
    }
    $employee = array_merge($employee, $row);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $errors[] = 'Token de segurança inválido.';
    } else {
        if (!$isEdit) {
            $capacity = checkEmployeeCapacity($companyId, 1);
            if (!$capacity['ok']) {
                setFlash('error', $capacity['message']);
                redirect('/admin/employees.php');
            }
        }
        // Operações autorizadas (checkboxes)
        $allOps = [
            'Trabalho em Altura (NR-35)', 'Espaço Confinado (NR-33)', 'Trabalho em Telhado',
            'Uso de Andaime', 'Uso de Plataforma Elevatória (PLEM)',
            'Eletricidade — Baixa Tensão (NR-10)', 'Eletricidade — Alta Tensão (NR-10 SEP)',
            'Bloqueio e Etiquetagem (Lockout/Tagout)',
            'Operação de Empilhadeira', 'Veículo Grande Porte', 'Direção Defensiva',
            'Movimentação de Cargas (NR-11)', 'Operação de Guincho / Guindaste', 'Operação de Caldeira (NR-13)',
            'Trabalho a Quente (Solda / Corte)', 'Trabalho com Produtos Químicos (NR-26)',
            'Trabalho em Instalações Frigoríficas', 'Trabalho com Radiação (NR-15)',
            'Trabalho Noturno', 'Trabalho em Área Classificada',
            'Uso de Respirador / Máscara PFF2', 'Uso de Equipamento Autônomo (SCBA)',
            'Primeiros Socorros', 'Combate a Incêndio (Brigadista)', 'Resgate em Altura',
        ];
        $checkedOps = array_filter($_POST['authorized_ops'] ?? [], fn($v) => in_array($v, $allOps));
        $employee['authorized_ops'] = json_encode(array_values($checkedOps));

        $fields = ['name','email','cpf','role','phone','photo','status',
                   'cep','address','number','complement','neighborhood','city','state'];
        foreach ($fields as $f) {
            $employee[$f] = trim($_POST[$f] ?? '');
        }
        $employee['department']   = trim($_POST['department_id'] ?? ''); // área
        $employee['manager_name'] = trim($_POST['gestor']        ?? ''); // gestor direto
        $employee['department_id'] = null;
        // Remove máscaras — salva só dígitos
        $employee['phone'] = preg_replace('/\D/', '', $employee['phone']);
        $employee['cep']   = preg_replace('/\D/', '', $employee['cep']);
        $employee['cpf']   = preg_replace('/\D/', '', $employee['cpf']);

        // Upload de foto (substitui campo URL antigo)
        if (!empty($_FILES['photo_upload']['name'])) {
            $up = uploadFile($_FILES['photo_upload'], 'photo', $employee['photo'] ?: null);
            if (!$up['ok']) {
                $errors[] = $up['error'];
            } elseif ($up['path']) {
                $employee['photo'] = $up['path'];
            }
        }

        if ($employee['name'] === '')  $errors[] = 'Nome é obrigatório.';
        if (!filter_var($employee['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail inválido.';
        if (!validarCpf($employee['cpf'])) $errors[] = 'CPF inválido (dígito verificador incorreto).';
        if ($employee['role'] === '')  $errors[] = 'Cargo é obrigatório.';
        if (!in_array($employee['status'], ['active','inactive'], true)) $errors[] = 'Status inválido.';
        if ($employee['cep'] !== '' && strlen($employee['cep']) !== 8) $errors[] = 'CEP inválido.';

        if (empty($errors)) {
            $check = $db->prepare(
                'SELECT id FROM employees
                 WHERE company_id = ? AND (email = ? OR cpf = ?) AND id <> ?'
            );
            $check->execute([$companyId, $employee['email'], $employee['cpf'], $id]);
            if ($check->fetch()) $errors[] = 'Já existe um funcionário com este e-mail ou CPF.';
        }

        if (empty($errors)) {
            if ($isEdit) {
                $stmt = $db->prepare(
                    'UPDATE employees
                     SET name=?,email=?,cpf=?,role=?,department=?,department_id=?,manager_name=?,
                         authorized_ops=?,
                         phone=?,photo=?,status=?,
                         cep=?,address=?,number=?,complement=?,neighborhood=?,city=?,state=?
                     WHERE id=? AND company_id=?'
                );
                $stmt->execute([
                    $employee['name'],$employee['email'],$employee['cpf'],
                    $employee['role'],$employee['department']?:null,
                    $employee['department_id'],
                    $employee['manager_name']?:null,
                    $employee['authorized_ops']?:null,
                    $employee['phone']?:null,$employee['photo']?:null,$employee['status'],
                    $employee['cep']?:null,$employee['address']?:null,$employee['number']?:null,
                    $employee['complement']?:null,$employee['neighborhood']?:null,
                    $employee['city']?:null,$employee['state']?:null,
                    $id,$companyId
                ]);
                auditLog('employee.update',"employee #{$id}",json_encode(['name'=>$employee['name']]));

                // Sincroniza nome e e-mail na conta de login do colaborador
                $empRow = $db->prepare('SELECT user_id FROM employees WHERE id = ? AND company_id = ?');
                $empRow->execute([$id, $companyId]);
                $empData = $empRow->fetch();
                if (!empty($empData['user_id'])) {
                    $db->prepare('UPDATE users SET name=?,email=?,status=? WHERE id=? AND role="employee"')
                       ->execute([$employee['name'],$employee['email'],$employee['status'],$empData['user_id']]);
                }

                setFlash('success','Funcionário atualizado com sucesso!');
            } else {
                $publicHash = generateHash();
                $stmt = $db->prepare(
                    'INSERT INTO employees
                       (company_id,name,email,cpf,role,department,department_id,manager_name,
                        authorized_ops,
                        phone,photo,public_hash,status,
                        cep,address,number,complement,neighborhood,city,state)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $companyId,$employee['name'],$employee['email'],$employee['cpf'],
                    $employee['role'],$employee['department']?:null,
                    $employee['department_id'],
                    $employee['manager_name']?:null,
                    $employee['authorized_ops']?:null,
                    $employee['phone']?:null,$employee['photo']?:null,$publicHash,$employee['status'],
                    $employee['cep']?:null,$employee['address']?:null,$employee['number']?:null,
                    $employee['complement']?:null,$employee['neighborhood']?:null,
                    $employee['city']?:null,$employee['state']?:null,
                ]);
                $newEmployeeId = (int)$db->lastInsertId();
                auditLog('employee.create',"employee #{$newEmployeeId}",json_encode(['name'=>$employee['name']]));

                // Cria conta de login: senha padrão = CPF (só dígitos)
                $cpfDigits = preg_replace('/\D/', '', $employee['cpf']);
                $hashedPwd = password_hash($cpfDigits, PASSWORD_DEFAULT);
                $chkUser = $db->prepare('SELECT id FROM users WHERE email = ? AND role = "employee"');
                $chkUser->execute([$employee['email']]);
                if (!$chkUser->fetch()) {
                    $uStmt = $db->prepare(
                        'INSERT INTO users (company_id,name,email,password,role,status)
                         VALUES (?,?,?,?,"employee","active")'
                    );
                    $uStmt->execute([$companyId,$employee['name'],$employee['email'],$hashedPwd]);
                    $newUserId = (int)$db->lastInsertId();
                    $db->prepare('UPDATE employees SET user_id = ? WHERE id = ?')
                       ->execute([$newUserId, $newEmployeeId]);

                    // Envia e-mail de boas-vindas com as credenciais
                    require_once __DIR__ . '/../includes/mailer.php';
                    $loginUrl = BASE_URL . '/login.php';
                    $emailHtml =
                        '<h2>Bem-vindo(a), ' . htmlspecialchars($employee['name']) . '!</h2>'
                      . '<p>Sua conta de acesso ao <strong>' . SITE_NAME . '</strong> foi criada.</p>'
                      . '<p><strong>E-mail:</strong> ' . htmlspecialchars($employee['email']) . '<br>'
                      . '<strong>Senha:</strong> ' . htmlspecialchars($cpfDigits) . ' (seu CPF sem pontuação)</p>'
                      . '<p style="color:#6b7280;font-size:13px">Após o primeiro acesso, você pode alterar sua senha pelo painel.</p>'
                      . '<a class="btn" href="' . $loginUrl . '">Acessar o sistema</a>';
                    sendMail(
                        $employee['email'],
                        'Seus dados de acesso — ' . SITE_NAME,
                        $emailHtml
                    );
                }

                setFlash('success','Funcionário cadastrado com sucesso! Senha de acesso: CPF (apenas números).');
            }
            redirect('/admin/employees.php');
        }
    }
}

// Formata para exibição nos inputs mascarados
function fmtCpf(?string $v): string {
    $d = preg_replace('/\D/','',$v??'');
    return strlen($d)===11 ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/',"$1.$2.$3-$4",$d) : $v??'';
}
function fmtPhone(?string $v): string {
    $d = preg_replace('/\D/','',$v??'');
    if(strlen($d)===11) return '('.substr($d,0,2).') '.substr($d,2,5).'-'.substr($d,7,4);
    if(strlen($d)===10) return '('.substr($d,0,2).') '.substr($d,2,4).'-'.substr($d,6,4);
    return $v??'';
}
function fmtCep(?string $v): string {
    $d = preg_replace('/\D/','',$v??'');
    return strlen($d)===8 ? substr($d,0,5).'-'.substr($d,5,3) : $v??'';
}

$states = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG',
           'PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

$pageTitle = $isEdit ? 'Editar Funcionário' : 'Novo Funcionário';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="mb-6">
    <a href="<?= BASE_URL ?>/admin/employees.php"
       class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-industrial-blue transition">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Voltar para Colaboradores
    </a>
    <div class="mt-3 flex items-center gap-4">
        <div class="h-12 w-12 rounded-xl bg-blue-100 flex items-center justify-center flex-shrink-0">
            <svg class="h-6 w-6 text-industrial-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
        </div>
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= $isEdit ? 'Editar Colaborador' : 'Novo Colaborador' ?></h1>
            <p class="text-sm text-gray-500 mt-0.5">Dados pessoais, área, autorização e endereço</p>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 text-sm space-y-1">
    <p class="font-semibold mb-1">⚠️ Corrija os erros abaixo:</p>
    <?php foreach ($errors as $e): ?><div>• <?= e($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="space-y-5 max-w-4xl" id="empForm">
    <?= csrfField() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
    <?php endif; ?>

    <!-- ══ 1. DADOS PESSOAIS ════════════════════════════════════════ -->
    <div class="section-card">
        <div class="section-card-header">
            <h2 class="section-card-title flex items-center gap-2">
                <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">1</span>
                Dados Pessoais
            </h2>
        </div>
        <div class="section-card-body grid grid-cols-1 sm:grid-cols-2 gap-4">

            <!-- Nome -->
            <div class="sm:col-span-2">
                <label class="form-label">Nome completo *</label>
                <input type="text" name="name" value="<?= e($employee['name']) ?>" required
                       class="form-input w-full" placeholder="Nome completo do colaborador">
            </div>

            <!-- E-mail + Telefone -->
            <div>
                <label class="form-label">E-mail *</label>
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400 pointer-events-none"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    <input type="email" name="email" value="<?= e($employee['email']) ?>" required
                           class="form-input w-full pl-9" placeholder="colaborador@empresa.com">
                </div>
            </div>
            <div>
                <label class="form-label">Telefone / WhatsApp</label>
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400 pointer-events-none"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                    <input type="text" name="phone" id="inp_phone" value="<?= e(fmtPhone($employee['phone'])) ?>"
                           placeholder="(11) 91234-5678" maxlength="15"
                           class="form-input w-full pl-9">
                </div>
            </div>

            <!-- CPF + Cargo -->
            <div>
                <label class="form-label">CPF *</label>
                <input type="text" name="cpf" id="inp_cpf" value="<?= e(fmtCpf($employee['cpf'])) ?>" required
                       placeholder="000.000.000-00" maxlength="14" class="form-input w-full">
            </div>
            <div>
                <label class="form-label">Cargo / Função *</label>
                <input type="text" name="role" value="<?= e($employee['role']) ?>" required
                       placeholder="Ex: Eletricista" class="form-input w-full">
            </div>

            <!-- Status -->
            <div>
                <label class="form-label">Status *</label>
                <select name="status" class="form-select w-full">
                    <option value="active"   <?= $employee['status']==='active'   ? 'selected':'' ?>>Ativo</option>
                    <option value="inactive" <?= $employee['status']==='inactive' ? 'selected':'' ?>>Inativo</option>
                </select>
            </div>

            <!-- Foto -->
            <div>
                <label class="form-label">Foto do Colaborador</label>
                <?php if (!empty($employee['photo'])): ?>
                <div class="flex items-center gap-3 mb-2">
                    <img id="photoPreview" src="<?= BASE_URL . '/' . e($employee['photo']) ?>"
                         alt="Foto" class="h-12 w-12 rounded-full object-cover border border-gray-200 shadow-sm">
                    <span class="text-xs text-gray-400">Foto atual — envie outra para substituir</span>
                </div>
                <?php else: ?>
                <div class="mb-2">
                    <img id="photoPreview" src="" alt="" class="h-12 w-12 rounded-full object-cover border border-gray-200 shadow-sm hidden">
                </div>
                <?php endif; ?>
                <input type="file" name="photo_upload" id="photo_upload"
                       accept="image/jpeg,image/png,image/webp,image/gif"
                       class="w-full text-sm text-gray-600 border border-gray-300 rounded-lg px-3 py-2
                              file:mr-3 file:py-1.5 file:px-4 file:rounded-lg file:border-0
                              file:text-sm file:font-medium file:bg-blue-50 file:text-industrial-blue hover:file:bg-blue-100 transition"
                       onchange="previewPhoto(this)">
                <p class="text-xs text-gray-400 mt-1">JPG, PNG ou WebP · máx. 3 MB</p>
            </div>

        </div>
    </div>

    <!-- ══ 2. ÁREA E GESTOR ════════════════════════════════════════ -->
    <div class="section-card">
        <div class="section-card-header">
            <h2 class="section-card-title flex items-center gap-2">
                <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">2</span>
                Área e Gestor
            </h2>
        </div>
        <div class="section-card-body grid grid-cols-1 sm:grid-cols-2 gap-4">

            <div>
                <label class="form-label">Área / Setor</label>
                <input type="text" name="department_id" id="inp_department"
                       value="<?= e($employee['department'] ?? '') ?>"
                       placeholder="Ex: Manutenção, Logística, RH..."
                       list="dept_list" autocomplete="off"
                       class="form-input w-full">
                <datalist id="dept_list">
                    <?php foreach ($deptOptions as $d): ?>
                        <option value="<?= e($d['name']) ?>">
                    <?php endforeach; ?>
                </datalist>
                <?php if (empty($deptOptions)): ?>
                <p class="text-xs text-gray-400 mt-1">
                    <a href="<?= BASE_URL ?>/admin/department-form.php" class="text-industrial-blue hover:underline">Cadastrar áreas →</a>
                </p>
                <?php endif; ?>
            </div>

            <div>
                <label class="form-label">Gestor Direto</label>
                <input type="text" name="gestor" id="inp_gestor"
                       value="<?= e($employee['manager_name'] ?? '') ?>"
                       placeholder="Preenchido automaticamente pela área"
                       class="form-input w-full">
                <p class="text-xs text-gray-400 mt-1">Preenchido ao selecionar a área, ou edite manualmente.</p>
            </div>

        </div>
    </div>

    <!-- Mapa de áreas → gestores para auto-preenchimento -->
    <script>
    const deptManagerMap = {
        <?php foreach ($deptOptions as $d): ?>
        <?= json_encode($d['name']) ?>: <?= json_encode($d['manager_name'] ?? '') ?>,
        <?php endforeach; ?>
    };
    const inpDept   = document.getElementById('inp_department');
    const inpGestor = document.getElementById('inp_gestor');
    function syncGestor() {
        const val = inpDept.value.trim();
        if (deptManagerMap.hasOwnProperty(val) && deptManagerMap[val]) {
            inpGestor.value = deptManagerMap[val];
            inpGestor.style.background = '#f0fdf4';
        }
    }
    inpDept.addEventListener('change', syncGestor);
    inpDept.addEventListener('input',  syncGestor);
    </script>

    <!-- ══ 3. OPERAÇÕES AUTORIZADAS ═══════════════════════════════ -->
    <?php
    $allOpsGroups = [
        'Trabalho em Altura e Espaço' => ['Trabalho em Altura (NR-35)','Espaço Confinado (NR-33)','Trabalho em Telhado','Uso de Andaime','Uso de Plataforma Elevatória (PLEM)'],
        'Eletricidade e Energia'      => ['Eletricidade — Baixa Tensão (NR-10)','Eletricidade — Alta Tensão (NR-10 SEP)','Bloqueio e Etiquetagem (Lockout/Tagout)'],
        'Equipamentos e Veículos'     => ['Operação de Empilhadeira','Veículo Grande Porte','Direção Defensiva','Movimentação de Cargas (NR-11)','Operação de Guincho / Guindaste','Operação de Caldeira (NR-13)'],
        'Trabalhos Especiais'         => ['Trabalho a Quente (Solda / Corte)','Trabalho com Produtos Químicos (NR-26)','Trabalho em Instalações Frigoríficas','Trabalho com Radiação (NR-15)','Trabalho Noturno','Trabalho em Área Classificada'],
        'Saúde e Segurança'           => ['Uso de Respirador / Máscara PFF2','Uso de Equipamento Autônomo (SCBA)','Primeiros Socorros','Combate a Incêndio (Brigadista)','Resgate em Altura'],
    ];
    $savedOps = json_decode($employee['authorized_ops'] ?? '[]', true) ?: [];
    ?>
    <div class="section-card">
        <div class="section-card-header">
            <h2 class="section-card-title flex items-center gap-2">
                <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">3</span>
                Operações Autorizadas
            </h2>
            <p class="text-xs text-gray-400">Aparecem na Carteira de Autorização do colaborador</p>
        </div>
        <div class="section-card-body space-y-5">
            <?php foreach ($allOpsGroups as $groupLabel => $ops): ?>
            <div>
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2"><?= e($groupLabel) ?></p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-1.5 gap-x-6">
                    <?php foreach ($ops as $op): ?>
                    <label class="flex items-center gap-3 cursor-pointer group">
                        <input type="checkbox" name="authorized_ops[]" value="<?= e($op) ?>"
                               <?= in_array($op, $savedOps, true) ? 'checked' : '' ?>
                               class="w-4 h-4 rounded border-gray-300 text-industrial-blue focus:ring-industrial-blue cursor-pointer">
                        <span class="text-sm text-gray-700 group-hover:text-industrial-blue transition"><?= e($op) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══ 4. ENDEREÇO ════════════════════════════════════════════ -->
    <div class="section-card">
        <div class="section-card-header">
            <h2 class="section-card-title flex items-center gap-2">
                <span class="flex items-center justify-center h-6 w-6 rounded-full bg-industrial-blue text-white text-xs font-bold">4</span>
                Endereço
                <span class="text-xs font-normal text-gray-400 ml-1">(opcional)</span>
            </h2>
        </div>
        <div class="section-card-body grid grid-cols-1 sm:grid-cols-3 gap-4">

            <div>
                <label class="form-label">CEP</label>
                <input type="text" name="cep" id="inp_cep" value="<?= e(fmtCep($employee['cep'])) ?>"
                       placeholder="00000-000" maxlength="9" class="form-input w-full">
            </div>
            <div class="sm:col-span-2">
                <label class="form-label">Logradouro</label>
                <input type="text" name="address" value="<?= e($employee['address']) ?>"
                       placeholder="Rua, Avenida, Travessa..." class="form-input w-full">
            </div>

            <div>
                <label class="form-label">Número</label>
                <input type="text" name="number" value="<?= e($employee['number']) ?>"
                       placeholder="123" class="form-input w-full">
            </div>
            <div class="sm:col-span-2">
                <label class="form-label">Complemento</label>
                <input type="text" name="complement" value="<?= e($employee['complement']) ?>"
                       placeholder="Apto 12, Bloco B..." class="form-input w-full">
            </div>

            <div>
                <label class="form-label">Bairro</label>
                <input type="text" name="neighborhood" value="<?= e($employee['neighborhood']) ?>"
                       placeholder="Centro" class="form-input w-full">
            </div>
            <div>
                <label class="form-label">Cidade</label>
                <input type="text" name="city" value="<?= e($employee['city']) ?>"
                       placeholder="São Paulo" class="form-input w-full">
            </div>
            <div>
                <label class="form-label">UF</label>
                <select name="state" class="form-select w-full">
                    <option value="">—</option>
                    <?php foreach ($states as $uf): ?>
                        <option value="<?= $uf ?>" <?= $employee['state']===$uf ? 'selected':'' ?>><?= $uf ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div>
    </div>

    <!-- Botões -->
    <div class="flex gap-3 pb-6">
        <button type="submit" class="btn btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <?= $isEdit ? 'Salvar Alterações' : 'Cadastrar Colaborador' ?>
        </button>
        <a href="<?= BASE_URL ?>/admin/employees.php" class="btn btn-secondary">Cancelar</a>
    </div>
</form>

<!-- Máscaras de campo via JS puro (sem biblioteca) -->
<script>
function maskCpf(v){
    v=v.replace(/\D/g,'').slice(0,11);
    if(v.length>9) return v.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/,'$1.$2.$3-$4');
    if(v.length>6) return v.replace(/(\d{3})(\d{3})(\d+)/,'$1.$2.$3');
    if(v.length>3) return v.replace(/(\d{3})(\d+)/,'$1.$2');
    return v;
}
function maskPhone(v){
    v=v.replace(/\D/g,'').slice(0,11);
    if(v.length===11) return v.replace(/(\d{2})(\d{5})(\d{4})/,'($1) $2-$3');
    if(v.length>=10)  return v.replace(/(\d{2})(\d{4})(\d{4})/,'($1) $2-$3');
    if(v.length>6)    return v.replace(/(\d{2})(\d{4})(\d+)/,'($1) $2-$3');
    if(v.length>2)    return v.replace(/(\d{2})(\d+)/,'($1) $2');
    return v;
}
function maskCep(v){
    v=v.replace(/\D/g,'').slice(0,8);
    if(v.length>5) return v.replace(/(\d{5})(\d+)/,'$1-$2');
    return v;
}
function applyMask(id,fn){
    const el=document.getElementById(id);
    if(!el) return;
    el.addEventListener('input',function(){
        const pos=this.selectionStart;
        this.value=fn(this.value);
    });
}
applyMask('inp_cpf',   maskCpf);
applyMask('inp_phone', maskPhone);
applyMask('inp_cep',   maskCep);

function previewPhoto(input) {
    const img = document.getElementById('photoPreview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            img.src = e.target.result;
            img.classList.remove('hidden');
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
