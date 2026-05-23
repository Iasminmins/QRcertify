<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mailer.php';

// Se já logado, vai pro dashboard
if (isLoggedIn()) {
    redirectToDashboard(currentUser()['role']);
}

$errors  = [];
$success = false;
$activeStepOnError = 1;

$plans = [];
foreach (['starter', 'pro', 'business'] as $planKey) {
    $def = getPlansCatalog()[$planKey];
    $plans[$planKey] = [
        'name'      => $def['name'],
        'price'     => $def['price'],
        'label'     => 'R$ ' . number_format($def['price'], 0, ',', '.') . '/mês',
        'employees' => $def['employees'],
    ];
}

$selectedPlan = $_GET['plan'] ?? $_POST['plan'] ?? 'pro';
if (!isset($plans[$selectedPlan])) $selectedPlan = 'pro';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $errors[] = 'Token de segurança inválido.';
    } else {
        $activeStepOnError = 2;
        $plan       = $_POST['plan'] ?? 'pro';
        $coName     = trim($_POST['company_name'] ?? '');
        $cnpj       = preg_replace('/\D/', '', $_POST['cnpj'] ?? '');
        $coPhone    = preg_replace('/\D/', '', $_POST['company_phone'] ?? '');
        $cep        = preg_replace('/\D/', '', $_POST['cep'] ?? '');
        $addrNumber = trim($_POST['address_number'] ?? '');
        $userName   = trim($_POST['user_name'] ?? '');
        $email      = strtolower(trim($_POST['email'] ?? ''));
        $password   = $_POST['password'] ?? '';
        $password2  = $_POST['password2'] ?? '';
        $terms      = $_POST['terms'] ?? '';

        if (!isset($plans[$plan])) $errors[] = 'Plano invalido.';

        // Validações
        if ($coName === '')   $errors[] = 'Nome da empresa é obrigatório.';
        if (!$coPhone)        $errors[] = 'Telefone é obrigatório.';
        if (strlen($cep) < 8) $errors[] = 'CEP inválido.';
        if ($addrNumber === '') $errors[] = 'Número do endereço é obrigatório.';
        if ($userName === '') $errors[] = 'Seu nome é obrigatório.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail inválido.';
        if (strlen($password) < MIN_PASSWORD_LENGTH) {
            $errors[] = 'Senha deve ter pelo menos ' . MIN_PASSWORD_LENGTH . ' caracteres.';
        }
        if ($password !== $password2) $errors[] = 'As senhas não coincidem.';
        if (!$terms) $errors[] = 'Você precisa aceitar os termos de uso.';

        if (empty($errors)) {
            $db = getDB();

            // Verifica e-mail duplicado
            $chk = $db->prepare('SELECT id FROM users WHERE email = ?');
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $errors[] = 'Este e-mail já possui uma conta. <a href="login.php" class="underline">Faça login</a>.';
            }
        }

        if (empty($errors)) {
            $db = getDB();
            $db->beginTransaction();
            try {
                // Cria empresa
                $stmtCo = $db->prepare(
                    'INSERT INTO companies (name, cnpj, phone, email) VALUES (?, ?, ?, ?)'
                );
                $stmtCo->execute([$coName, $cnpj ?: null, $coPhone ?: null, $email]);
                $companyId = (int) $db->lastInsertId();

                // Cria usuário admin
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmtU = $db->prepare(
                    'INSERT INTO users (company_id, name, email, password, role) VALUES (?, ?, ?, ?, "admin")'
                );
                $stmtU->execute([$companyId, $userName, $email, $hash]);
                $userId = (int) $db->lastInsertId();

                // ── Integração Asaas Checkout recorrente (sem cartão no servidor) ──
                $asaasCustomerId     = null;
                $asaasSubscriptionId = null;
                $checkoutId          = null;
                $checkoutUrl         = null;

                $planPrices = array_map(
                    fn(array $p) => $p['price'],
                    getPlansCatalog()
                );

                if (in_array($plan, ['starter', 'pro', 'business', 'enterprise'], true)) {

                    // 1) Checkout recorrente: o Asaas coleta o cartão e cria a assinatura.
                    $trialEnd = (new \DateTime('+30 days'))->format('Y-m-d');
                    $successQuery = http_build_query([
                        'nome'  => $userName,
                        'plano' => $plan,
                        'email' => $email,
                    ]);
                    $externalReference = "company_{$companyId}_plan_{$plan}";
                    $customerData = array_filter([
                        'name'          => $userName,
                        'email'         => $email,
                        'cpfCnpj'       => $cnpj ?: null,
                        'phone'         => $coPhone ?: null,
                        'postalCode'    => $cep ?: null,
                        'addressNumber' => $addrNumber ?: null,
                    ], static fn($value) => $value !== null && $value !== '');
                    $checkoutPayload = [
                        'billingTypes'      => ['CREDIT_CARD'],
                        'chargeTypes'       => ['RECURRENT'],
                        'minutesToExpire'   => 1440,
                        'externalReference' => $externalReference,
                        'callback'          => [
                            'successUrl' => BASE_URL . '/sucesso.php?' . $successQuery,
                            'cancelUrl'  => BASE_URL . '/checkout.php?status=cancelado',
                            'expiredUrl' => BASE_URL . '/checkout.php?status=expirado',
                        ],
                        'items' => [[
                            'name'        => 'Plano ' . ucfirst($plan) . ' - QRCertify',
                            'description' => 'Assinatura mensal QRCertify com 30 dias grátis',
                            'quantity'    => 1,
                            'value'       => $planPrices[$plan],
                        ]],
                        'customerData' => $customerData,
                        'subscription' => [
                            'cycle'       => 'MONTHLY',
                            'nextDueDate' => $trialEnd,
                        ],
                    ];

                    $checkoutResp = asaasRequest('POST', '/checkouts', $checkoutPayload);
                    if (empty($checkoutResp['id'])) {
                        $asaasErr = $checkoutResp['errors'][0]['description'] ?? 'Erro desconhecido';
                        throw new \Exception('Não foi possível gerar o checkout seguro: ' . $asaasErr);
                    }

                    $checkoutId = $checkoutResp['id'];
                    $checkoutUrl = $checkoutResp['link']
                        ?? $checkoutResp['url']
                        ?? buildAsaasCheckoutUrl($checkoutId);

                    // 2) Salva o plano localmente; liberacao e IDs chegam via webhook.
                    $db->prepare(
                        'UPDATE companies SET plan = ?, subscription_status = "pending_checkout" WHERE id = ?'
                    )->execute([$plan, $companyId]);
                }

                $db->commit();

                auditLog('register', "company#{$companyId}", json_encode([
                    'plan'           => $plan,
                    'email'          => $email,
                    'asaas_customer' => $asaasCustomerId,
                    'asaas_sub'      => $asaasSubscriptionId,
                    'asaas_checkout' => $checkoutId,
                ]));

                if ($checkoutUrl) {
                    $welcomeHtml = '<h2>Bem-vindo, ' . htmlspecialchars($userName) . '!</h2>'
                        . '<p>Sua conta do <strong>QRCertify</strong> foi criada. Complete o checkout seguro do Asaas para ativar a assinatura.</p>'
                        . '<p>Seu período de <strong>30 dias grátis</strong> começa agora. A primeira cobrança será após esse período.</p>'
                        . '<a class="btn" href="' . BASE_URL . '/login.php">Acessar o sistema</a>';
                    @sendMail($email, 'Bem-vindo ao QRCertify — 30 dias grátis', $welcomeHtml);

                    header('Location: ' . $checkoutUrl);
                    exit;
                }

                // Fallback se plano não exigir Asaas (não deve ocorrer nos planos atuais)
                $welcomeHtml = '<h2>Bem-vindo, ' . htmlspecialchars($userName) . '!</h2>'
                    . '<p>Sua conta do <strong>QRCertify</strong> foi criada com sucesso. Seu período de 30 dias grátis começa agora.</p>'
                    . '<p>Acesse o sistema e comece cadastrando seus funcionários e certificados.</p>'
                    . '<a class="btn" href="' . BASE_URL . '/login.php">Acessar o sistema</a>';
                @sendMail($email, 'Bem-vindo ao QRCertify — 30 dias grátis', $welcomeHtml);

                setFlash('success', 'Conta criada! Seu período de 30 dias grátis começou.');
                header('Location: ' . BASE_URL . '/sucesso.php?' . http_build_query([
                    'nome'  => $userName,
                    'plano' => $plan,
                    'email' => $email,
                ]));
                exit;

            } catch (\Exception $e) {
                $db->rollBack();
                $activeStepOnError = 3;
                $errors[] = 'Erro ao criar conta: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Criar conta — QRCertify</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: { extend: { colors: { 'industrial-blue': '#1e3a8a', 'industrial-yellow': '#facc15' } } }
}
</script>
<style>
  body { font-family: 'IBM Plex Sans', sans-serif; }
  h1,h2,h3 { font-family: 'Bricolage Grotesque', sans-serif; }
  .step-dot { transition: background .2s, border-color .2s; }
  .input-field {
    width: 100%;
    padding: .6rem .85rem;
    border: 1px solid #d1d5db;
    border-radius: .5rem;
    font-size: .9rem;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
  }
  .input-field:focus { border-color: #1e3a8a; box-shadow: 0 0 0 3px rgba(30,58,138,.1); }
  .card-plan { transition: border-color .2s, background .2s; cursor: pointer; }
  .card-plan.selected { border-color: #1e3a8a; background: #eff6ff; }
</style>
</head>
<body class="bg-gray-50 min-h-screen">

<!-- Header mínimo -->
<header class="bg-white border-b border-gray-100 py-4 px-5">
  <div class="max-w-5xl mx-auto flex items-center justify-between">
    <a href="<?= BASE_URL ?>/" class="flex items-center gap-2.5">
      <div class="w-7 h-7 rounded-full bg-industrial-blue flex items-center justify-center">
        <div class="w-2.5 h-2.5 rounded-full bg-industrial-yellow"></div>
      </div>
      <span style="font-family:'Bricolage Grotesque',sans-serif;font-weight:700;font-size:.95rem" class="text-gray-900">QRCertify</span>
    </a>
    <a href="login.php" class="text-sm text-gray-500 hover:text-gray-900 transition-colors">Já tenho conta → Entrar</a>
  </div>
</header>

<!-- Erros globais -->
<?php if (!empty($errors)): ?>
<div class="max-w-xl mx-auto mt-6 px-5">
  <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-800 space-y-1">
    <?php foreach ($errors as $e): ?><div>• <?= $e ?></div><?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Progress steps -->
<div class="max-w-xl mx-auto px-5 pt-8 pb-4">
  <div class="flex items-center gap-0">
    <?php
    $steps = ['Plano', 'Sua conta', 'Pagamento'];
    foreach ($steps as $i => $label):
        $n = $i + 1;
    ?>
    <div class="flex items-center <?= $i < count($steps)-1 ? 'flex-1' : '' ?>">
      <div class="flex flex-col items-center">
        <div id="dot-<?= $n ?>" class="step-dot w-8 h-8 rounded-full border-2 flex items-center justify-center text-xs font-bold
          <?= $n === 1 ? 'bg-industrial-blue border-industrial-blue text-white' : 'border-gray-300 text-gray-400' ?>">
          <?= $n ?>
        </div>
        <span class="text-xs mt-1 <?= $n === 1 ? 'text-industrial-blue font-semibold' : 'text-gray-400' ?>" id="lbl-<?= $n ?>"><?= $label ?></span>
      </div>
      <?php if ($i < count($steps)-1): ?>
        <div class="flex-1 h-px bg-gray-200 mx-2 mb-4" id="line-<?= $n ?>"></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Form -->
<form method="POST" id="checkoutForm" class="max-w-xl mx-auto px-5 pb-16">
  <?= csrfField() ?>
  <input type="hidden" name="plan" id="planInput" value="<?= htmlspecialchars($selectedPlan) ?>">

  <!-- ─── STEP 1: Plano ─────────────────────────────────── -->
  <div id="step1" class="step-panel">
    <h1 class="text-2xl font-bold mb-1" style="font-family:'Bricolage Grotesque',sans-serif">Escolha seu plano</h1>
    <p class="text-gray-500 text-sm mb-6">30 dias grátis em qualquer plano. Cancele quando quiser.</p>

    <div class="space-y-3">
      <?php foreach ($plans as $key => $p): ?>
      <label class="card-plan block border-2 border-gray-200 rounded-xl p-4 <?= $selectedPlan === $key ? 'selected' : '' ?>"
             onclick="selectPlan('<?= $key ?>')">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div id="radio-<?= $key ?>" class="w-4 h-4 rounded-full border-2 <?= $selectedPlan === $key ? 'border-industrial-blue bg-industrial-blue' : 'border-gray-300' ?>"></div>
            <div>
              <div class="font-semibold text-gray-900 text-sm flex items-center gap-2">
                <?= htmlspecialchars($p['name']) ?>
                <?php if ($key === 'pro'): ?><span class="text-xs bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded-full font-medium">Recomendado</span><?php endif; ?>
                <?php if ($key === 'business'): ?><span class="text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded-full font-medium">Popular</span><?php endif; ?>
              </div>
              <div class="text-xs text-gray-500 mt-0.5">
                <?= $p['employees'] > 0 ? 'Até ' . $p['employees'] . ' funcionários' : 'Multiempresa, suporte e personalização' ?>
              </div>
            </div>
          </div>
          <div class="text-right">
            <div class="font-bold text-gray-900 text-sm"><?= htmlspecialchars($p['label']) ?></div>
            <?php if ($key !== 'starter'): ?>
              <div class="text-xs text-gray-400">após 30 dias</div>
            <?php else: ?>
              <div class="text-xs text-gray-400">após 30 dias</div>
            <?php endif; ?>
          </div>
        </div>
      </label>
      <?php endforeach; ?>
    </div>

    <button type="button" onclick="goStep(2)" class="mt-6 w-full bg-industrial-blue text-white font-semibold py-3 rounded-xl hover:bg-blue-900 transition-colors">
      Continuar →
    </button>
  </div>

  <!-- ─── STEP 2: Conta ─────────────────────────────────── -->
  <div id="step2" class="step-panel hidden">
    <h1 class="text-2xl font-bold mb-1" style="font-family:'Bricolage Grotesque',sans-serif">Dados da sua conta</h1>
    <p class="text-gray-500 text-sm mb-6">Você será o administrador da conta.</p>

    <div class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nome da empresa *</label>
        <input type="text" name="company_name" class="input-field" placeholder="Indústria Exemplo Ltda"
               value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>" required>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">CNPJ</label>
          <input type="text" name="cnpj" id="cnpj" class="input-field" placeholder="00.000.000/0001-00" maxlength="18"
                 value="<?= htmlspecialchars($_POST['cnpj'] ?? '') ?>">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Telefone *</label>
          <input type="text" name="company_phone" id="co_phone" class="input-field" placeholder="(11) 3333-4444" maxlength="15"
                 value="<?= htmlspecialchars($_POST['company_phone'] ?? '') ?>" required>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">CEP *</label>
          <input type="text" name="cep" id="cep" class="input-field" placeholder="00000-000" maxlength="9"
                 value="<?= htmlspecialchars($_POST['cep'] ?? '') ?>" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Número *</label>
          <input type="text" name="address_number" id="address_number" class="input-field" placeholder="123"
                 value="<?= htmlspecialchars($_POST['address_number'] ?? '') ?>" required>
        </div>
      </div>
      <hr class="border-gray-100">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Seu nome completo *</label>
        <input type="text" name="user_name" class="input-field" placeholder="Amanda Silva"
               value="<?= htmlspecialchars($_POST['user_name'] ?? '') ?>" required>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">E-mail *</label>
        <input type="email" name="email" class="input-field" placeholder="amanda@empresa.com.br"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Senha * <span class="text-gray-400 font-normal">(mín. 8 chars)</span></label>
          <input type="password" name="password" class="input-field" placeholder="••••••••" minlength="<?= MIN_PASSWORD_LENGTH ?>" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar senha *</label>
          <input type="password" name="password2" class="input-field" placeholder="••••••••" required>
        </div>
      </div>
    </div>

    <div class="flex gap-3 mt-6">
      <button type="button" onclick="goStep(1)" class="px-5 py-3 border border-gray-200 rounded-xl text-sm font-medium text-gray-600 hover:bg-gray-50 transition-colors">← Voltar</button>
      <button type="button" onclick="validateStep2()" class="flex-1 bg-industrial-blue text-white font-semibold py-3 rounded-xl hover:bg-blue-900 transition-colors">Continuar →</button>
    </div>
  </div>

  <!-- ─── STEP 3: Pagamento (checkout hospedado no Asaas) ─── -->
  <div id="step3" class="step-panel hidden">
    <h1 class="text-2xl font-bold mb-1" style="font-family:'Bricolage Grotesque',sans-serif">Forma de pagamento</h1>
    <p class="text-gray-500 text-sm mb-6" id="paySubtitle">
      Na próxima etapa você define como deseja pagar. Hoje não há cobrança — os 30 dias grátis começam agora.
    </p>

    <!-- Resumo do plano -->
    <div class="bg-gray-50 rounded-xl border border-gray-200 p-4 mb-5" id="orderSummary">
      <div class="flex items-center justify-between text-sm">
        <span class="font-medium text-gray-700">Plano <span id="summaryPlanName">Pro</span></span>
        <span class="font-bold text-gray-900" id="summaryPrice">R$ 99/mês</span>
      </div>
      <div class="text-xs text-gray-500 mt-1">30 dias grátis · após isso, cobrança automática mensal</div>
    </div>

    <div class="space-y-2 text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-xl p-4 mb-2">
      <p class="font-medium text-gray-800">Próximos passos</p>
      <ol class="list-decimal list-inside space-y-1.5 text-gray-600">
        <li>Criamos sua conta no QRCertify.</li>
        <li>Você escolhe a forma de pagamento na página segura do <strong>Asaas</strong>.</li>
        <li>Em seguida, voltamos você para confirmar que está tudo certo.</li>
      </ol>
      <div class="flex items-center gap-2 text-xs text-gray-500 pt-2 border-t border-gray-200 mt-2">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Cobrança processada com segurança pelo Asaas, nosso parceiro de pagamentos.
      </div>
    </div>

    <label class="flex items-start gap-3 mt-5 text-sm text-gray-600 cursor-pointer">
      <input type="checkbox" name="terms" value="1" class="mt-0.5 rounded" <?= !empty($_POST['terms']) ? 'checked' : '' ?>>
      <span>Li e aceito os <a href="admin/suporte/termos.php" target="_blank" class="text-industrial-blue underline">Termos de Uso</a> e a <a href="admin/suporte/privacidade.php" target="_blank" class="text-industrial-blue underline">Política de Privacidade</a>.</span>
    </label>

    <div class="flex gap-3 mt-6">
      <button type="button" onclick="goStep(2)" class="px-5 py-3 border border-gray-200 rounded-xl text-sm font-medium text-gray-600 hover:bg-gray-50 transition-colors">← Voltar</button>
      <button type="submit" class="flex-1 bg-industrial-blue text-white font-bold py-3.5 rounded-xl hover:bg-blue-900 transition-colors text-sm">
        Criar conta e ir para pagamento →
      </button>
    </div>

    <p class="text-xs text-gray-400 text-center mt-4">Sem cobrança hoje · Cancele quando quiser</p>
  </div>

</form>

<script>
let currentStep = 1;

// Vai para step inicial correto se houve erro no POST
<?php if (!empty($errors) && !empty($_POST)): ?>
currentStep = <?= (int) $activeStepOnError ?>;
<?php endif; ?>

function goStep(n) {
  document.querySelectorAll('.step-panel').forEach(p => p.classList.add('hidden'));
  document.getElementById('step' + n).classList.remove('hidden');
  currentStep = n;
  updateDots(n);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateDots(active) {
  [1,2,3].forEach(i => {
    const dot = document.getElementById('dot-' + i);
    const lbl = document.getElementById('lbl-' + i);
    const line = document.getElementById('line-' + i);
    if (i < active) {
      dot.className = 'step-dot w-8 h-8 rounded-full border-2 flex items-center justify-center text-xs font-bold bg-green-500 border-green-500 text-white';
      dot.innerHTML = '✓';
      if (lbl) { lbl.className = 'text-xs mt-1 text-green-600 font-semibold'; }
      if (line) line.style.background = '#22c55e';
    } else if (i === active) {
      dot.className = 'step-dot w-8 h-8 rounded-full border-2 flex items-center justify-center text-xs font-bold bg-industrial-blue border-industrial-blue text-white';
      dot.innerHTML = i;
      if (lbl) { lbl.className = 'text-xs mt-1 text-industrial-blue font-semibold'; }
    } else {
      dot.className = 'step-dot w-8 h-8 rounded-full border-2 flex items-center justify-center text-xs font-bold border-gray-300 text-gray-400';
      dot.innerHTML = i;
      if (lbl) { lbl.className = 'text-xs mt-1 text-gray-400'; }
    }
  });
}

function selectPlan(key) {
  document.querySelectorAll('.card-plan').forEach(c => c.classList.remove('selected'));
  document.querySelectorAll('[id^="radio-"]').forEach(r => {
    r.className = 'w-4 h-4 rounded-full border-2 border-gray-300';
  });
  const card = document.querySelector(`[onclick="selectPlan('${key}')"]`);
  if (card) card.classList.add('selected');
  const radio = document.getElementById('radio-' + key);
  if (radio) radio.className = 'w-4 h-4 rounded-full border-2 border-industrial-blue bg-industrial-blue';
  document.getElementById('planInput').value = key;

  const prices = { starter: 'R$ 49/mês', pro: 'R$ 179/mês', business: 'R$ 349/mês' };
  const names  = { starter: 'Starter', pro: 'Pro', business: 'Business' };
  const el = document.getElementById('summaryPlanName');
  const pr = document.getElementById('summaryPrice');
  if (el) el.textContent = names[key] || key;
  if (pr) pr.textContent = prices[key] || '';
}

function validateStep2() {
  const fields = ['company_name','user_name','email','password','password2'];
  let ok = true;
  fields.forEach(f => {
    const el = document.querySelector('[name="' + f + '"]');
    if (el && el.value.trim() === '') { el.style.borderColor = '#ef4444'; ok = false; }
    else if (el) el.style.borderColor = '';
  });
  const pw  = document.querySelector('[name="password"]').value;
  const pw2 = document.querySelector('[name="password2"]').value;
  if (pw.length < 8) { alert('Senha deve ter pelo menos 8 caracteres.'); return; }
  if (pw !== pw2)    { alert('As senhas não coincidem.'); return; }
  if (!ok) { alert('Preencha todos os campos obrigatórios.'); return; }
  goStep(3);
  selectPlan(document.getElementById('planInput').value);
}

// Máscaras
function maskCnpj(v){
  v=v.replace(/\D/g,'').slice(0,14);
  if(v.length>12) return v.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/,'$1.$2.$3/$4-$5');
  if(v.length>8)  return v.replace(/(\d{2})(\d{3})(\d{3})(\d+)/,'$1.$2.$3/$4');
  if(v.length>5)  return v.replace(/(\d{2})(\d{3})(\d+)/,'$1.$2.$3');
  if(v.length>2)  return v.replace(/(\d{2})(\d+)/,'$1.$2');
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
document.getElementById('cnpj')?.addEventListener('input', function(){ this.value=maskCnpj(this.value); });
document.getElementById('co_phone')?.addEventListener('input', function(){ this.value=maskPhone(this.value); });
document.getElementById('cep')?.addEventListener('input', function(){
  let v = this.value.replace(/\D/g,'').slice(0,8);
  if(v.length > 5) v = v.slice(0,5) + '-' + v.slice(5);
  this.value = v;
});

// Inicia no step correto
goStep(currentStep);
</script>
</body>
</html>
