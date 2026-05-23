<?php
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) redirectToDashboard(currentUser()['role']);

$token   = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errors  = [];
$success = false;
$valid   = false;
$db      = getDB();

if ($token) {
    $stmt = $db->prepare(
        'SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW()'
    );
    $stmt->execute([$token]);
    $reset = $stmt->fetch();
    $valid = (bool) $reset;
}

if (!$valid && !$success) {
    $errors[] = 'Link inválido ou expirado. Solicite um novo.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    if (!csrfCheck()) {
        $errors[] = 'Token inválido.';
    } else {
        $password  = $_POST['password']  ?? '';
        $password2 = $_POST['password2'] ?? '';

        if (strlen($password) < MIN_PASSWORD_LENGTH) {
            $errors[] = 'Senha deve ter pelo menos ' . MIN_PASSWORD_LENGTH . ' caracteres.';
        }
        if ($password !== $password2) $errors[] = 'As senhas não coincidem.';

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // Atualiza senha do usuário
            $db->prepare('UPDATE users SET password = ? WHERE email = ?')
               ->execute([$hash, $reset['email']]);

            // Marca token como usado
            $db->prepare('UPDATE password_resets SET used = 1 WHERE token = ?')
               ->execute([$token]);

            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Redefinir senha — QRCertify</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{'industrial-blue':'#1e3a8a','industrial-yellow':'#facc15'}}}}</script>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>body{font-family:'IBM Plex Sans',sans-serif;}</style>
</head>
<body class="min-h-screen flex items-center justify-center bg-gradient-to-br from-industrial-blue to-blue-900 p-4">
<div class="w-full max-w-md">
  <div class="flex justify-center mb-8">
    <div class="h-12 w-12 rounded-full bg-white flex items-center justify-center shadow-lg">
      <div class="h-7 w-7 rounded-full bg-industrial-yellow"></div>
    </div>
  </div>

  <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-2xl p-8">
    <?php if ($success): ?>
      <div class="text-center">
        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <svg width="32" height="32" fill="none" stroke="#16a34a" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h2 class="text-xl font-bold text-gray-900 mb-2" style="font-family:'Bricolage Grotesque',sans-serif">Senha redefinida!</h2>
        <p class="text-gray-500 text-sm mb-6">Sua senha foi atualizada com sucesso. Faça login com a nova senha.</p>
        <a href="<?= BASE_URL ?>/login.php" class="block text-center bg-industrial-blue text-white font-semibold py-2.5 rounded-xl hover:bg-blue-900 transition-colors text-sm">Ir para o login</a>
      </div>

    <?php elseif (!$valid): ?>
      <div class="text-center">
        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <svg width="32" height="32" fill="none" stroke="#dc2626" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <h2 class="text-xl font-bold text-gray-900 mb-2" style="font-family:'Bricolage Grotesque',sans-serif">Link inválido</h2>
        <p class="text-gray-500 text-sm mb-6">Este link expirou ou já foi utilizado. Solicite um novo.</p>
        <a href="<?= BASE_URL ?>/esqueci-senha.php" class="block text-center bg-industrial-blue text-white font-semibold py-2.5 rounded-xl hover:bg-blue-900 transition-colors text-sm">Solicitar novo link</a>
      </div>

    <?php else: ?>
      <h2 class="text-2xl font-bold text-industrial-blue mb-1" style="font-family:'Bricolage Grotesque',sans-serif">Nova senha</h2>
      <p class="text-gray-500 text-sm mb-6">Escolha uma senha forte com pelo menos 8 caracteres.</p>

      <?php if (!empty($errors)): ?>
        <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm">
          <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="space-y-4">
        <?= csrfField() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Nova senha</label>
          <input type="password" name="password" required minlength="8" placeholder="Mínimo 8 caracteres"
                 class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-industrial-blue outline-none text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar senha</label>
          <input type="password" name="password2" required minlength="8" placeholder="Repita a senha"
                 class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-industrial-blue outline-none text-sm">
        </div>
        <button type="submit" class="w-full bg-industrial-blue text-white font-semibold py-2.5 rounded-xl hover:bg-blue-900 transition-colors text-sm">
          Salvar nova senha
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
