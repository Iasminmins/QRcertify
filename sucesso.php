<?php
require_once __DIR__ . '/config/config.php';

// Só acessa quem veio do cadastro
$nome  = $_GET['nome']  ?? 'usuário';
$plano = $_GET['plano'] ?? 'starter';
$email = $_GET['email'] ?? '';

$planoLabel = [
    'starter'  => 'Starter',
    'pro'      => 'Pro',
    'business' => 'Business',
];
$label = $planoLabel[$plano] ?? ucfirst($plano);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<?php renderGoogleTagManagerHead(); ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Conta criada! — QRCertify</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/public/assets/logo-qrcertify.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{'industrial-blue':'#1e3a8a','industrial-yellow':'#facc15'}}}}</script>
<style>
  body { font-family: 'IBM Plex Sans', sans-serif; }
  h1,h2,h3 { font-family: 'Bricolage Grotesque', sans-serif; }
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(24px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  @keyframes pop {
    0%   { transform: scale(0.6); opacity: 0; }
    70%  { transform: scale(1.1); }
    100% { transform: scale(1);   opacity: 1; }
  }
  @keyframes float {
    0%, 100% { transform: translateY(0px); }
    50%       { transform: translateY(-8px); }
  }
  .anim-fade  { animation: fadeUp .6s ease forwards; }
  .anim-pop   { animation: pop .5s ease forwards; }
  .anim-float { animation: float 3s ease-in-out infinite; }
  .delay-1 { animation-delay: .1s; opacity: 0; }
  .delay-2 { animation-delay: .25s; opacity: 0; }
  .delay-3 { animation-delay: .4s; opacity: 0; }
  .delay-4 { animation-delay: .55s; opacity: 0; }
  .icon-card {
    width: 72px; height: 72px; border-radius: 18px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 8px 24px rgba(0,0,0,.12);
  }
  .step-item { display: flex; align-items: flex-start; gap: 16px; }
  .step-dot {
    width: 36px; height: 36px; border-radius: 50%;
    background: #eff6ff; color: #1e3a8a;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: .85rem; flex-shrink: 0;
  }
</style>
</head>
<body class="bg-gray-50 min-h-screen">
<?php renderGoogleTagManagerBody(); ?>

<!-- Header -->
<header class="bg-white border-b border-gray-100 py-4 px-5">
  <div class="max-w-5xl mx-auto flex items-center justify-between">
    <a href="<?= BASE_URL ?>/" class="flex items-center gap-2.5">
      <div class="w-7 h-7 rounded-full bg-industrial-blue flex items-center justify-center">
        <div class="w-2.5 h-2.5 rounded-full bg-industrial-yellow"></div>
      </div>
      <span style="font-family:'Bricolage Grotesque',sans-serif;font-weight:700;font-size:.95rem" class="text-gray-900">QRCertify</span>
    </a>
    <a href="<?= BASE_URL ?>/login.php" class="text-sm text-industrial-blue font-semibold hover:underline">Acessar o sistema →</a>
  </div>
</header>

<main class="max-w-2xl mx-auto px-5 py-16 text-center">

  <!-- Ícones flutuantes -->
  <div class="anim-pop flex justify-center mb-10">
    <div class="relative w-56 h-40">
      <!-- Ícone central: QR Code -->
      <div class="icon-card absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-industrial-blue anim-float z-10" style="width:80px;height:80px;border-radius:20px;">
        <svg width="36" height="36" fill="none" stroke="white" stroke-width="1.8" viewBox="0 0 24 24">
          <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
          <rect x="3" y="14" width="7" height="7"/>
          <path d="M14 14h1v1h-1zM17 14h1v1h-1zM14 17h1v1h-1zM17 17h1v1h-1zM20 14v3M20 20h-3v-3"/>
        </svg>
      </div>
      <!-- Certificado -->
      <div class="icon-card absolute top-0 left-6 bg-green-500" style="width:60px;height:60px;border-radius:15px;animation:float 3.2s ease-in-out infinite;">
        <svg width="28" height="28" fill="none" stroke="white" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><polyline points="9 15 11 17 15 13"/></svg>
      </div>
      <!-- Check -->
      <div class="icon-card absolute top-0 right-6 bg-industrial-yellow" style="width:60px;height:60px;border-radius:15px;animation:float 2.8s ease-in-out infinite .4s;">
        <svg width="28" height="28" fill="none" stroke="#1e3a8a" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <!-- Usuário -->
      <div class="icon-card absolute bottom-0 left-2 bg-blue-100" style="width:56px;height:56px;border-radius:14px;animation:float 3.5s ease-in-out infinite .2s;">
        <svg width="26" height="26" fill="none" stroke="#1e3a8a" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </div>
      <!-- E-mail -->
      <div class="icon-card absolute bottom-0 right-2 bg-purple-100" style="width:56px;height:56px;border-radius:14px;animation:float 2.6s ease-in-out infinite .6s;">
        <svg width="26" height="26" fill="none" stroke="#7c3aed" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      </div>
    </div>
  </div>

  <!-- Título -->
  <div class="anim-fade delay-1">
    <div class="inline-flex items-center gap-2 bg-green-50 text-green-700 text-xs font-semibold px-3 py-1.5 rounded-full mb-4">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
      Conta criada · Plano <?= htmlspecialchars($label) ?>
    </div>
    <h1 class="text-4xl md:text-5xl font-extrabold text-gray-900 tracking-tight leading-tight mb-4">
      Parabéns, <?= htmlspecialchars(explode(' ', $nome)[0]) ?>!<br>
      <span class="text-industrial-blue">Sua jornada começa agora.</span>
    </h1>
    <p class="text-gray-500 text-lg mb-10">
      Sua conta QRCertify foi criada. Se você concluiu o checkout seguro do Asaas, seus <strong class="text-gray-700">30 dias grátis</strong> já estão em ativação.
    </p>
  </div>

  <!-- Próximos passos -->
  <div class="anim-fade delay-2 bg-white rounded-2xl border border-gray-200 p-8 text-left mb-8">
    <h2 class="font-bold text-gray-900 mb-6 text-lg">O que fazer agora</h2>
    <div class="space-y-5">
      <div class="step-item">
        <div class="step-dot">1</div>
        <div>
          <p class="font-semibold text-gray-800 text-sm">Acesse o sistema</p>
          <p class="text-gray-500 text-sm mt-0.5">Entre com o e-mail e senha que você cadastrou.</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-dot">2</div>
        <div>
          <p class="font-semibold text-gray-800 text-sm">Cadastre seus funcionários</p>
          <p class="text-gray-500 text-sm mt-0.5">Adicione manualmente ou importe via planilha CSV.</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-dot">3</div>
        <div>
          <p class="font-semibold text-gray-800 text-sm">Adicione os certificados de NR</p>
          <p class="text-gray-500 text-sm mt-0.5">Cadastre NR, validade e faça upload do PDF — o QR Code é gerado automaticamente.</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-dot">4</div>
        <div>
          <p class="font-semibold text-gray-800 text-sm">Compartilhe o QR Code</p>
          <p class="text-gray-500 text-sm mt-0.5">Fiscalização escaneia e vê os dados em segundos, sem precisar de app.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- CTA -->
  <div class="anim-fade delay-3 flex flex-col sm:flex-row gap-3 justify-center">
    <a href="<?= BASE_URL ?>/login.php"
       class="bg-industrial-blue text-white font-bold py-3.5 px-8 rounded-xl hover:bg-blue-900 transition-colors text-sm">
      Acessar o sistema agora →
    </a>
    <a href="<?= BASE_URL ?>/#faq"
       class="border border-gray-200 text-gray-600 font-medium py-3.5 px-8 rounded-xl hover:bg-gray-50 transition-colors text-sm">
      Ver perguntas frequentes
    </a>
  </div>

  <?php if ($email): ?>
  <p class="anim-fade delay-4 text-xs text-gray-400 mt-6">
    Um e-mail de boas-vindas foi enviado para <strong><?= htmlspecialchars($email) ?></strong>
  </p>
  <?php endif; ?>

</main>

</body>
</html>
