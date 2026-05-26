<?php
require_once __DIR__ . '/../config/config.php';
requireRole('employee');

$user   = currentUser();
$db     = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $errors[] = 'Token de segurança inválido.';
    } else {
        $currentPwd = $_POST['current_password'] ?? '';
        $newPwd     = trim($_POST['new_password'] ?? '');
        $confirmPwd = trim($_POST['confirm_password'] ?? '');

        // Busca hash atual
        $stmt = $db->prepare('SELECT password FROM users WHERE id = ? AND role = "employee" LIMIT 1');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($currentPwd, $row['password'])) {
            $errors[] = 'Senha atual incorreta.';
        }
        if (strlen($newPwd) < 6) {
            $errors[] = 'A nova senha deve ter pelo menos 6 caracteres.';
        }
        if ($newPwd !== $confirmPwd) {
            $errors[] = 'As senhas não coincidem.';
        }

        if (empty($errors)) {
            $hashed = password_hash($newPwd, PASSWORD_DEFAULT);
            $db->prepare('UPDATE users SET password = ? WHERE id = ? AND role = "employee"')
               ->execute([$hashed, $user['id']]);
            auditLog('employee.password_change', "user#{$user['id']}");
            setFlash('success', 'Senha alterada com sucesso!');
            redirect('/employee/certificates.php');
        }
    }
}

$pageTitle = 'Alterar Senha';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="mb-6">
    <a href="<?= BASE_URL ?>/employee/certificates.php"
       class="text-sm text-gray-600 hover:text-industrial-blue inline-flex items-center gap-1">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Voltar
    </a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Alterar Senha</h1>
    <p class="text-sm text-gray-500 mt-1">Defina uma senha pessoal para sua conta.</p>
</div>

<?php if (!empty($errors)): ?>
    <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm space-y-1">
        <?php foreach ($errors as $e): ?><div>• <?= e($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="POST" class="max-w-md space-y-5">
    <?= csrfField() ?>

    <div class="bg-white rounded-lg shadow-sm border p-6 space-y-4">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Senha atual</label>
            <input type="password" name="current_password" required autocomplete="current-password"
                   placeholder="••••••••"
                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
            <p class="text-xs text-gray-400 mt-1">Se nunca alterou, sua senha é o CPF (só números).</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nova senha</label>
            <input type="password" name="new_password" required autocomplete="new-password"
                   placeholder="Mínimo 6 caracteres"
                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar nova senha</label>
            <input type="password" name="confirm_password" required autocomplete="new-password"
                   placeholder="Repita a nova senha"
                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue outline-none">
        </div>
    </div>

    <div class="flex gap-3">
        <button type="submit"
                class="bg-industrial-blue hover:bg-blue-800 text-white px-5 py-2 rounded-md font-medium transition">
            Salvar nova senha
        </button>
        <a href="<?= BASE_URL ?>/employee/certificates.php"
           class="px-5 py-2 border border-gray-300 rounded-md font-medium hover:bg-gray-50 transition">
            Cancelar
        </a>
    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
