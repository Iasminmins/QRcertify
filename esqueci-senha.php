<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mailer.php';

if (isLoggedIn()) redirectToDashboard(currentUser()['role']);

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $errors[] = 'Token inválido.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'E-mail inválido.';
        } else {
            $db   = getDB();
            $stmt = $db->prepare('SELECT id, name FROM users WHERE email = ? AND status = "active"');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Sempre mostra sucesso (não revela se e-mail existe)
            if ($user) {
                $token     = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1h

                // Remove tokens antigos do mesmo e-mail
                $db->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);

                $db->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)')
                   ->execute([$email, $token, $expiresAt]);

                $link = BASE_URL . '/redefinir-senha.php?token=' . $token;
                $html = '<h2>Redefinição de senha — QRCertify</h2>'
                      . '<p>Olá, <strong>' . htmlspecialchars($user['name']) . '</strong>!</p>'
                      . '<p>Clique no botão abaixo para redefinir sua senha. O link expira em <strong>1 hora</strong>.</p>'
                      . '<p style="margin:24px 0"><a href="' . $link . '" style="background:#1e3a8a;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold">Redefinir minha senha</a></p>'
                      . '<p style="font-size:12px;color:#6b7280">Se você não solicitou a redefinição, ignore este e-mail.</p>';

                @sendMail($email, 'Redefinição de senha — QRCertify', $html);
            }
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <?php renderGoogleTagManagerHead(); ?>
    <meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Esqueci minha senha — QRCertify</title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/public/assets/logo-qrcertify.svg">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{'industrial-blue':'#1e3a8a','industrial-yellow':'#facc15'}}}}</script>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>body{font-family:'IBM Plex Sans',sans-serif;}</style>
</head>
<body class="min-h-screen flex items-center justify-center bg-gradient-to-br from-industrial-blue to-blue-900 p-4">
<?php renderGoogleTagManagerBody(); ?>
<div class="w-full max-w-md">
  <div class="flex justify-center mb-8">
    <a href="<?= BASE_URL ?>/" class="flex items-center gap-2.5">
      <div class="h-12 w-12 rounded-full bg-white flex items-center justify-center shadow-lg">
        <div class="h-7 w-7 rounded-full bg-industrial-yellow"></div>
      </div>
    </a>
  </div>

  <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-2xl p-8">
    <?php if ($success): ?>
      <div class="text-center">
        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <svg width="32" height="32" fill="none" stroke="#16a34a" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h2 class="text-xl font-bold text-gray-900 mb-2" style="font-family:'Bricolage Grotesque',sans-serif">E-mail enviado!</h2>
        <p class="text-gray-500 text-sm mb-6">Se esse e-mail estiver cadastrado, você receberá as instruções em instantes. Verifique também a caixa de spam.</p>
        <a href="<?= BASE_URL ?>/login.php" class="block text-center bg-industrial-blue text-white font-semibold py-2.5 rounded-xl hover:bg-blue-900 transition-colors text-sm">Voltar ao login</a>
      </div>
    <?php else: ?>
      <h2 class="text-2xl font-bold text-industrial-blue mb-1" style="font-family:'Bricolage Grotesque',sans-serif">Esqueceu a senha?</h2>
      <p class="text-gray-500 text-sm mb-6">Informe seu e-mail e enviaremos um link para redefinir sua senha.</p>

      <?php if (!empty($errors)): ?>
        <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm">
          <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="space-y-4">
        <?= csrfField() ?>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">E-mail</label>
          <input type="email" name="email" required placeholder="seu@email.com"
                 class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-industrial-blue focus:border-transparent outline-none text-sm">
        </div>
        <button type="submit" class="w-full bg-industrial-blue text-white font-semibold py-2.5 rounded-xl hover:bg-blue-900 transition-colors text-sm">
          Enviar link de redefinição
        </button>
      </form>
      <p class="text-center text-sm text-gray-500 mt-4">
        <a href="<?= BASE_URL ?>/login.php" class="text-industrial-blue hover:underline">← Voltar ao login</a>
      </p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
