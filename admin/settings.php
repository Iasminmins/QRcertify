<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user = currentUser();
$db   = getDB();

$stmt = $db->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$user['company_id']]);
$company = $stmt->fetch();

$errCompany  = [];
$errPassword = [];
$activeTab   = $_GET['tab'] ?? 'company';

// ── Salvar dados da empresa ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_company'])) {
    $activeTab = 'company';
    if (!csrfCheck()) { $errCompany[] = 'Token inválido.'; }
    else {
        $name   = trim($_POST['name']  ?? '');
        $email  = trim($_POST['email'] ?? '');
        $phone  = preg_replace('/\D/', '', $_POST['phone'] ?? '');
        $cep    = preg_replace('/\D/', '', $_POST['cep']   ?? '');
        $addr   = trim($_POST['address']      ?? '');
        $num    = trim($_POST['number']       ?? '');
        $comp   = trim($_POST['complement']   ?? '');
        $neigh  = trim($_POST['neighborhood'] ?? '');
        $city   = trim($_POST['city']         ?? '');
        $state  = strtoupper(trim($_POST['state'] ?? ''));
        $emSesmt    = preg_replace('/\D/', '', $_POST['emergency_sesmt']    ?? '');
        $emBombeiro = trim($_POST['emergency_bombeiro'] ?? '193');
        $emSamu     = trim($_POST['emergency_samu']     ?? '192');
        $emPolicia  = trim($_POST['emergency_policia']  ?? '190');

        if ($name === '') $errCompany[] = 'Nome da empresa é obrigatório.';
        if ($cep !== '' && strlen($cep) !== 8) $errCompany[] = 'CEP inválido.';

        if (empty($errCompany)) {
            // Upload de logo
            $logoPath = $company['logo'] ?? null;
            if (!empty($_FILES['logo_upload']['name'])) {
                $up = uploadFile($_FILES['logo_upload'], 'photo', $logoPath);
                if (!$up['ok']) {
                    $errCompany[] = $up['error'];
                } elseif ($up['path']) {
                    $logoPath = $up['path'];
                }
            }
        }
        if (empty($errCompany)) {
            $stmt = $db->prepare(
                'UPDATE companies
                 SET name=?,email=?,phone=?,cep=?,address=?,number=?,complement=?,
                     neighborhood=?,city=?,state=?,logo=?,
                     emergency_sesmt=?,emergency_bombeiro=?,emergency_samu=?,emergency_policia=?
                 WHERE id=?'
            );
            $stmt->execute([
                $name, $email ?: null, $phone ?: null,
                $cep ?: null, $addr ?: null, $num ?: null,
                $comp ?: null, $neigh ?: null, $city ?: null, $state ?: null,
                $logoPath,
                $emSesmt ?: null, $emBombeiro ?: '193', $emSamu ?: '192', $emPolicia ?: '190',
                $user['company_id']
            ]);
            auditLog('company.update', "company #{$user['company_id']}");
            setFlash('success', 'Dados da empresa atualizados!');
            redirect('/admin/settings.php?tab=company');
        }
    }
}

// ── Trocar senha ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_password'])) {
    $activeTab = 'password';
    if (!csrfCheck()) { $errPassword[] = 'Token inválido.'; }
    else {
        $current = $_POST['current_password'] ?? '';
        $new1    = $_POST['new_password']     ?? '';
        $new2    = $_POST['confirm_password'] ?? '';

        $stmtU = $db->prepare('SELECT password FROM users WHERE id = ?');
        $stmtU->execute([$user['id']]);
        $dbHash = $stmtU->fetchColumn();

        if (!password_verify($current, $dbHash))  $errPassword[] = 'Senha atual incorreta.';
        if (strlen($new1) < 8)                     $errPassword[] = 'A nova senha deve ter pelo menos 8 caracteres.';
        if ($new1 !== $new2)                       $errPassword[] = 'As senhas não coincidem.';

        if (empty($errPassword)) {
            $hash = password_hash($new1, PASSWORD_DEFAULT);
            $db->prepare('UPDATE users SET password=? WHERE id=?')->execute([$hash, $user['id']]);
            auditLog('user.password_change', "user #{$user['id']}");
            setFlash('success', 'Senha alterada com sucesso!');
            redirect('/admin/settings.php?tab=password');
        }
    }
}

// Helpers de exibição
function fmtDisplayPhone(?string $v): string {
    $d = preg_replace('/\D/', '', $v ?? '');
    if (strlen($d) === 11) return '('.substr($d,0,2).') '.substr($d,2,5).'-'.substr($d,7,4);
    if (strlen($d) === 10) return '('.substr($d,0,2).') '.substr($d,2,4).'-'.substr($d,6,4);
    return $v ?? '';
}
function fmtDisplayCep(?string $v): string {
    $d = preg_replace('/\D/', '', $v ?? '');
    return strlen($d) === 8 ? substr($d,0,5).'-'.substr($d,5,3) : ($v ?? '');
}

$states = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG',
           'PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

$pageTitle = 'Configurações';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<h1 class="text-2xl font-bold text-gray-900 mb-1">Configurações</h1>
<p class="text-gray-600 mb-5">Empresa e conta de usuário</p>

<?php renderFlashes(); ?>

<!-- Abas -->
<div class="flex border-b border-gray-200 mb-6 gap-1">
    <a href="?tab=company"
       class="px-4 py-2 text-sm font-medium rounded-t-md border border-b-0
              <?= $activeTab === 'company'
                  ? 'border-gray-200 bg-white text-industrial-blue -mb-px'
                  : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
        Dados da Empresa
    </a>
    <a href="?tab=password"
       class="px-4 py-2 text-sm font-medium rounded-t-md border border-b-0
              <?= $activeTab === 'password'
                  ? 'border-gray-200 bg-white text-industrial-blue -mb-px'
                  : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
        Trocar Senha
    </a>
</div>

<?php
// Card do plano atual (aparece em todas as abas)
$subColors  = ['trialing'=>'blue','active'=>'green','overdue'=>'red','canceled'=>'gray'];
$subLabels  = ['trialing'=>'Trial gratuito','active'=>'Ativo','overdue'=>'Inadimplente','canceled'=>'Cancelado'];
$coPlan     = normalizePlanKey($company['plan'] ?? 'starter');
$coPlanDef  = getPlanDefinition($coPlan);
$coSub      = $company['subscription_status'] ?? 'trialing';
$sc         = $subColors[$coSub] ?? 'gray';
?>
<div class="mb-6 bg-white rounded-xl border border-gray-200 p-5 flex items-center justify-between gap-4">
    <div class="flex items-center gap-4">
        <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
            <svg width="18" height="18" fill="none" stroke="#1e3a8a" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="1"/></svg>
        </div>
        <div>
            <p class="text-xs text-gray-500">Plano atual</p>
            <p class="font-bold text-gray-900"><?= e($coPlanDef['name']) ?> — R$ <?= number_format($coPlanDef['price'], 0, ',', '.') ?>/mês</p>
        </div>
        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold
            <?= $sc==='green'?'bg-green-100 text-green-700':($sc==='blue'?'bg-blue-100 text-blue-700':($sc==='red'?'bg-red-100 text-red-700':'bg-gray-100 text-gray-600')) ?>">
            <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
            <?= $subLabels[$coSub] ?? $coSub ?>
        </span>
    </div>
    <a href="<?= BASE_URL ?>/admin/plano.php" class="text-sm font-semibold text-industrial-blue border border-industrial-blue px-4 py-1.5 rounded-lg hover:bg-blue-50 transition whitespace-nowrap">
        Gerenciar plano →
    </a>
</div>

<?php if ($activeTab === 'company'): ?>

<!-- ── ABA: EMPRESA ───────────────────────────────────────────── -->
<?php if (!empty($errCompany)): ?>
    <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm space-y-1">
        <?php foreach ($errCompany as $e): ?><div>• <?= e($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="space-y-5 max-w-2xl">
    <?= csrfField() ?>
    <input type="hidden" name="save_company" value="1">

    <div class="bg-white rounded-lg shadow-sm border p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide pb-2 border-b">Identificação</h2>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nome da Empresa *</label>
            <input type="text" name="name" value="<?= e($company['name'] ?? '') ?>" required
                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
        </div>

        <!-- Logo da Empresa -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Logo da Empresa</label>
            <div class="flex items-center gap-4">
                <div style="width:80px;height:80px;border:2px dashed #cbd5e1;border-radius:.5rem;
                            display:flex;align-items:center;justify-content:center;overflow:hidden;
                            background:#f8fafc;flex-shrink:0">
                    <?php if (!empty($company['logo'])): ?>
                        <img src="<?= BASE_URL . '/' . ltrim(e($company['logo']), '/') ?>"
                             alt="Logo" style="max-width:100%;max-height:100%;object-fit:contain"
                             id="logo-preview">
                    <?php else: ?>
                        <img id="logo-preview" src="" alt="" style="max-width:100%;max-height:100%;object-fit:contain;display:none">
                        <span id="logo-placeholder" style="font-size:.55rem;color:#94a3b8;text-align:center;line-height:1.4">
                            LOGO<br>EMPRESA
                        </span>
                    <?php endif; ?>
                </div>
                <div>
                    <input type="file" name="logo_upload" id="logo_upload"
                           accept="image/jpeg,image/png,image/webp,image/gif"
                           class="block text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-industrial-blue hover:file:bg-blue-100 cursor-pointer"
                           onchange="previewLogo(this)">
                    <p class="text-xs text-gray-400 mt-1">JPG, PNG ou WebP · máx. 3 MB · aparece na carteirinha</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">CNPJ</label>
                <input type="text" value="<?= e($company['cnpj'] ?? '') ?>" disabled
                       class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100 text-gray-500">
                <p class="text-xs text-gray-400 mt-1">O CNPJ não pode ser alterado.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">E-mail</label>
                <input type="email" name="email" value="<?= e($company['email'] ?? '') ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Telefone</label>
            <input type="text" name="phone" id="co_phone"
                   value="<?= e(fmtDisplayPhone($company['phone'] ?? '')) ?>"
                   placeholder="(11) 3333-4444" maxlength="15"
                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm border p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide pb-2 border-b flex items-center gap-2">
            Ramais de Emergência
            <span class="text-xs font-normal text-gray-400 normal-case">(aparece na carteirinha dos colaboradores)</span>
        </h2>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Telefone do SESMT</label>
                <input type="text" name="emergency_sesmt" id="em_sesmt"
                       value="<?= e(fmtDisplayPhone($company['emergency_sesmt'] ?? $company['phone'] ?? '')) ?>"
                       placeholder="(24) 3322-1100" maxlength="15"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
                <p class="text-xs text-gray-400 mt-1">Se vazio, usa o telefone principal da empresa</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Bombeiro</label>
                <input type="text" name="emergency_bombeiro"
                       value="<?= e($company['emergency_bombeiro'] ?? '193') ?>"
                       placeholder="193" maxlength="6"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">SAMU</label>
                <input type="text" name="emergency_samu"
                       value="<?= e($company['emergency_samu'] ?? '192') ?>"
                       placeholder="192" maxlength="6"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Polícia</label>
                <input type="text" name="emergency_policia"
                       value="<?= e($company['emergency_policia'] ?? '190') ?>"
                       placeholder="190" maxlength="6"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm border p-6 space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide pb-2 border-b">Endereço (opcional)</h2>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">CEP</label>
                <input type="text" name="cep" id="co_cep"
                       value="<?= e(fmtDisplayCep($company['cep'] ?? '')) ?>"
                       placeholder="00000-000" maxlength="9"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Logradouro</label>
                <input type="text" name="address" value="<?= e($company['address'] ?? '') ?>"
                       placeholder="Rua, Avenida..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Número</label>
                <input type="text" name="number" value="<?= e($company['number'] ?? '') ?>"
                       placeholder="100"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Complemento</label>
                <input type="text" name="complement" value="<?= e($company['complement'] ?? '') ?>"
                       placeholder="Sala, Andar..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Bairro</label>
                <input type="text" name="neighborhood" value="<?= e($company['neighborhood'] ?? '') ?>"
                       placeholder="Centro"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Cidade</label>
                <input type="text" name="city" value="<?= e($company['city'] ?? '') ?>"
                       placeholder="São Paulo"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">UF</label>
                <select name="state" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
                    <option value="">—</option>
                    <?php foreach ($states as $uf): ?>
                        <option value="<?= $uf ?>" <?= ($company['state'] ?? '') === $uf ? 'selected':'' ?>><?= $uf ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <button type="submit"
            class="bg-industrial-blue hover:bg-blue-800 text-white px-5 py-2 rounded-md font-medium transition">
        Salvar Alterações
    </button>
</form>

<?php elseif ($activeTab === 'password'): ?>

<!-- ── ABA: TROCAR SENHA ──────────────────────────────────────── -->
<?php if (!empty($errPassword)): ?>
    <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm space-y-1">
        <?php foreach ($errPassword as $e): ?><div>• <?= e($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="POST" class="bg-white rounded-lg shadow-sm border p-6 space-y-4 max-w-md">
    <?= csrfField() ?>
    <input type="hidden" name="save_password" value="1">

    <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide pb-2 border-b">Alterar Senha</h2>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Senha atual *</label>
        <input type="password" name="current_password" required autocomplete="current-password"
               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nova senha *</label>
        <input type="password" name="new_password" id="newPass" required
               minlength="8" autocomplete="new-password"
               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none"
               oninput="checkStrength(this.value)">
        <div class="mt-1.5 h-1.5 rounded-full bg-gray-200 overflow-hidden">
            <div id="strengthBar" class="h-full rounded-full transition-all duration-300" style="width:0%"></div>
        </div>
        <p id="strengthLabel" class="text-xs text-gray-400 mt-1">Mínimo 8 caracteres</p>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar nova senha *</label>
        <input type="password" name="confirm_password" required autocomplete="new-password"
               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
    </div>

    <div class="pt-3 border-t">
        <button type="submit"
                class="bg-industrial-blue hover:bg-blue-800 text-white px-5 py-2 rounded-md font-medium transition">
            Alterar Senha
        </button>
    </div>
</form>

<?php endif; ?>

<script>
// Preview de logo antes do upload
function previewLogo(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = document.getElementById('logo-preview');
        const ph  = document.getElementById('logo-placeholder');
        img.src = e.target.result;
        img.style.display = 'block';
        if (ph) ph.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
}

// Máscaras — empresa
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
    if(el) el.addEventListener('input',function(){ this.value=fn(this.value); });
}
applyMask('co_phone', maskPhone);
applyMask('co_cep',   maskCep);
applyMask('em_sesmt', maskPhone);

// Indicador de força de senha
function checkStrength(v) {
    let score = 0;
    if (v.length >= 8)  score++;
    if (v.length >= 12) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    const bar   = document.getElementById('strengthBar');
    const label = document.getElementById('strengthLabel');
    const map   = [
        [0,  '0%',   '#e5e7eb', 'Muito fraca'],
        [1,  '25%',  '#ef4444', 'Fraca'],
        [2,  '50%',  '#f59e0b', 'Média'],
        [3,  '75%',  '#3b82f6', 'Boa'],
        [4,  '90%',  '#10b981', 'Forte'],
        [5, '100%',  '#059669', 'Muito forte'],
    ];
    const [,w,c,l] = map[Math.min(score, 5)];
    bar.style.width = w;
    bar.style.background = c;
    label.textContent = l;
    label.style.color = c;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
