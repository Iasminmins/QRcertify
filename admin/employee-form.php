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
       class="text-sm text-gray-600 hover:text-industrial-blue inline-flex items-center gap-1">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Voltar
    </a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">
        <?= $isEdit ? 'Editar Funcionário' : 'Novo Funcionário' ?>
    </h1>
</div>

<?php if (!empty($errors)): ?>
    <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm space-y-1">
        <?php foreach ($errors as $e): ?><div>• <?= e($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="space-y-5 max-w-2xl" id="empForm">
    <?= csrfField() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
    <?php endif; ?>

    <!-- DADOS PESSOAIS -->
    <div class="bg-white rounded-lg shadow-sm border p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide pb-2 border-b">Dados Pessoais</h2>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nome completo *</label>
            <input type="text" name="name" value="<?= e($employee['name']) ?>" required
                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">E-mail *</label>
                <input type="email" name="email" value="<?= e($employee['email']) ?>" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Telefone</label>
                <input type="text" name="phone" id="inp_phone" value="<?= e(fmtPhone($employee['phone'])) ?>"
                       placeholder="(11) 91234-5678" maxlength="15"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">CPF *</label>
                <input type="text" name="cpf" id="inp_cpf" value="<?= e(fmtCpf($employee['cpf'])) ?>" required
                       placeholder="000.000.000-00" maxlength="14"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Cargo / Função *</label>
                <input type="text" name="role" value="<?= e($employee['role']) ?>" required placeholder="Ex: Eletricista"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Área / Setor</label>
                <input type="text" name="department_id" id="inp_department"
                       value="<?= e($employee['department'] ?? '') ?>"
                       placeholder="Ex: Manutenção, Logística, RH..."
                       list="dept_list" autocomplete="off"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
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
                <label class="block text-sm font-medium text-gray-700 mb-1">Gestor Direto</label>
                <input type="text" name="gestor" id="inp_gestor"
                       value="<?= e($employee['manager_name'] ?? '') ?>"
                       placeholder="Preenchido automaticamente pela área"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
                <p class="text-xs text-gray-400 mt-1">Preenchido automaticamente ao selecionar a área, ou edite manualmente.</p>
            </div>
        </div>

        <!-- Mapa de áreas → gestores para JS -->
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
                inpGestor.style.background = '#f0fdf4'; // verde claro indicando preenchimento automático
            }
        }

        inpDept.addEventListener('change', syncGestor);
        inpDept.addEventListener('input',  syncGestor);
        </script>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Foto do Funcionário</label>
                <?php if (!empty($employee['photo'])): ?>
                    <div class="mb-2 flex items-center gap-3">
                        <img id="photoPreview" src="<?= BASE_URL . '/' . e($employee['photo']) ?>"
                             alt="Foto atual" class="h-14 w-14 rounded-full object-cover border">
                        <span class="text-xs text-gray-400">Foto atual — envie outra para substituir</span>
                    </div>
                <?php else: ?>
                    <div class="mb-2">
                        <img id="photoPreview" src="" alt="" class="h-14 w-14 rounded-full object-cover border hidden">
                    </div>
                <?php endif; ?>
                <input type="file" name="photo_upload" id="photo_upload"
                       accept="image/jpeg,image/png,image/webp,image/gif"
                       class="w-full text-sm text-gray-600 border border-gray-300 rounded-md px-3 py-1.5 file:mr-3 file:py-1 file:px-3 file:rounded file:border-0 file:text-sm file:bg-blue-50 file:text-industrial-blue hover:file:bg-blue-100"
                       onchange="previewPhoto(this)">
                <p class="text-xs text-gray-400 mt-1">JPG, PNG ou WebP • máx. 3 MB</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status *</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
                    <option value="active"   <?= $employee['status']==='active'   ? 'selected':'' ?>>Ativo</option>
                    <option value="inactive" <?= $employee['status']==='inactive' ? 'selected':'' ?>>Inativo</option>
                </select>
            </div>
        </div>
    </div>

    <!-- OPERAÇÕES AUTORIZADAS -->
    <?php
    $allOpsGroups = [
        'Trabalho em Altura e Espaço' => [
            'Trabalho em Altura (NR-35)',
            'Espaço Confinado (NR-33)',
            'Trabalho em Telhado',
            'Uso de Andaime',
            'Uso de Plataforma Elevatória (PLEM)',
        ],
        'Eletricidade e Energia' => [
            'Eletricidade — Baixa Tensão (NR-10)',
            'Eletricidade — Alta Tensão (NR-10 SEP)',
            'Bloqueio e Etiquetagem (Lockout/Tagout)',
        ],
        'Equipamentos e Veículos' => [
            'Operação de Empilhadeira',
            'Veículo Grande Porte',
            'Direção Defensiva',
            'Movimentação de Cargas (NR-11)',
            'Operação de Guincho / Guindaste',
            'Operação de Caldeira (NR-13)',
        ],
        'Trabalhos Especiais' => [
            'Trabalho a Quente (Solda / Corte)',
            'Trabalho com Produtos Químicos (NR-26)',
            'Trabalho em Instalações Frigoríficas',
            'Trabalho com Radiação (NR-15)',
            'Trabalho Noturno',
            'Trabalho em Área Classificada',
        ],
        'Saúde e Segurança' => [
            'Uso de Respirador / Máscara PFF2',
            'Uso de Equipamento Autônomo (SCBA)',
            'Primeiros Socorros',
            'Combate a Incêndio (Brigadista)',
            'Resgate em Altura',
        ],
    ];

    // Lista plana para validação no POST
    $allOps = array_merge(...array_values($allOpsGroups));
    $savedOps = json_decode($employee['authorized_ops'] ?? '[]', true) ?: [];
    ?>
    <div class="bg-white rounded-lg shadow-sm border p-6 space-y-5">
        <div class="pb-2 border-b">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Operação / Execução</h2>
            <p class="text-xs text-gray-400 mt-0.5">Marque as operações que este colaborador está autorizado a executar. Aparecem na Carteira de Autorização.</p>
        </div>

        <?php foreach ($allOpsGroups as $groupLabel => $ops): ?>
        <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2"><?= e($groupLabel) ?></p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-1.5 gap-x-6">
                <?php foreach ($ops as $op): ?>
                <label class="flex items-center gap-3 cursor-pointer group">
                    <input type="checkbox"
                           name="authorized_ops[]"
                           value="<?= e($op) ?>"
                           <?= in_array($op, $savedOps, true) ? 'checked' : '' ?>
                           class="w-4 h-4 rounded border-gray-300 text-industrial-blue focus:ring-industrial-blue cursor-pointer">
                    <span class="text-sm text-gray-700 group-hover:text-industrial-blue transition"><?= e($op) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ENDEREÇO -->
    <div class="bg-white rounded-lg shadow-sm border p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide pb-2 border-b">Endereço (opcional)</h2>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">CEP</label>
                <input type="text" name="cep" id="inp_cep" value="<?= e(fmtCep($employee['cep'])) ?>"
                       placeholder="00000-000" maxlength="9"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Logradouro</label>
                <input type="text" name="address" value="<?= e($employee['address']) ?>"
                       placeholder="Rua, Avenida, Travessa..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Número</label>
                <input type="text" name="number" value="<?= e($employee['number']) ?>" placeholder="123"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Complemento</label>
                <input type="text" name="complement" value="<?= e($employee['complement']) ?>"
                       placeholder="Apto 12, Bloco B..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Bairro</label>
                <input type="text" name="neighborhood" value="<?= e($employee['neighborhood']) ?>"
                       placeholder="Centro"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Cidade</label>
                <input type="text" name="city" value="<?= e($employee['city']) ?>" placeholder="São Paulo"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">UF</label>
                <select name="state" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
                    <option value="">—</option>
                    <?php foreach ($states as $uf): ?>
                        <option value="<?= $uf ?>" <?= $employee['state']===$uf ? 'selected':'' ?>><?= $uf ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="flex gap-3">
        <button type="submit"
                class="bg-industrial-blue hover:bg-blue-800 text-white px-5 py-2 rounded-md font-medium transition">
            <?= $isEdit ? 'Salvar Alterações' : 'Cadastrar Funcionário' ?>
        </button>
        <a href="<?= BASE_URL ?>/admin/employees.php"
           class="px-5 py-2 border border-gray-300 rounded-md font-medium hover:bg-gray-50 transition">
            Cancelar
        </a>
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
