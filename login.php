<?php
require_once __DIR__ . '/config/config.php';

// Se já está logado, redireciona pra dashboard
if (isLoggedIn()) {
    redirectToDashboard(currentUser()['role']);
}

$errors = [];
$email = '';
// Preserva role se vier via query string (ex: redirect de requireLogin)
$role = $_GET['role'] ?? 'employee';
if (!in_array($role, ['superadmin', 'admin', 'employee'], true)) {
    $role = 'employee';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $errors[] = 'Token de segurança inválido. Recarregue a página.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'employee';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email inválido.';
        }
        if (strlen($password) < MIN_PASSWORD_LENGTH) {
            $errors[] = 'A senha deve ter pelo menos ' . MIN_PASSWORD_LENGTH . ' caracteres.';
        }
        if (!in_array($role, ['superadmin', 'admin', 'employee'], true)) {
            $errors[] = 'Tipo de usuário inválido.';
        }

        if (empty($errors)) {
            $result = attemptLogin($email, $password, $role);
            if ($result === 'blocked') {
                $errors[] = 'Muitas tentativas incorretas. Aguarde 15 minutos e tente novamente.';
            } elseif ($result) {
                setFlash('success', 'Login realizado com sucesso!');
                redirectToDashboard($result['role']);
            } else {
                $errors[] = 'E-mail, senha ou tipo de usuário incorretos.';
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
    <title>Login | <?= e(SITE_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'industrial-blue': '#1e3a8a',
                        'industrial-yellow': '#facc15',
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen flex items-center justify-center bg-gradient-to-br from-industrial-blue to-blue-900 p-4">
    <div class="absolute inset-0 opacity-5" style="background-image: linear-gradient(white 1px, transparent 1px), linear-gradient(90deg, white 1px, transparent 1px); background-size: 20px 20px;"></div>

    <div class="w-full max-w-md relative z-10">
        <!-- Logo -->
        <div class="flex justify-center mb-8">
            <a href="<?= BASE_URL ?>/" class="block" title="Voltar ao site">
                <div class="h-16 w-16 rounded-full bg-white flex items-center justify-center shadow-lg">
                    <div class="h-10 w-10 rounded-full bg-industrial-yellow"></div>
                </div>
            </a>
        </div>

        <!-- Tabs -->
        <div class="grid grid-cols-2 gap-2 mb-6 bg-white/10 backdrop-blur-sm p-1 rounded-lg">
            <button type="button" id="tab-tenant" class="px-4 py-2 rounded-md text-sm font-medium text-white bg-white/20 transition flex items-center justify-center gap-2">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5"/></svg>
                Empresa
            </button>
            <button type="button" id="tab-platform" class="px-4 py-2 rounded-md text-sm font-medium text-white/70 transition flex items-center justify-center gap-2">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                Plataforma
            </button>
        </div>

        <!-- Card -->
        <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-2xl p-6">
            <h2 class="text-2xl font-bold text-industrial-blue mb-1" id="form-title">QRCertify</h2>
            <p class="text-sm text-gray-600 mb-6" id="form-desc">Faça login em sua conta de empresa para acessar os certificados</p>

            <?php if (!empty($errors)): ?>
                <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm">
                    <?php foreach ($errors as $error): ?>
                        <div><?= e($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-4">
                <?= csrfField() ?>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" value="<?= e($email) ?>" placeholder="seu.email@exemplo.com" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue focus:border-transparent outline-none">
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-sm font-medium text-gray-700">Senha</label>
                        <a href="<?= BASE_URL ?>/esqueci-senha.php" class="text-xs text-industrial-blue hover:underline">Esqueci minha senha</a>
                    </div>
                    <input type="password" name="password" placeholder="••••••••" required minlength="<?= MIN_PASSWORD_LENGTH ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-industrial-blue focus:border-transparent outline-none">
                </div>

                <div id="role-tenant">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Entrar como</label>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="role" value="admin" <?= $role === 'admin' ? 'checked' : '' ?> class="h-4 w-4 text-industrial-blue">
                            <span class="text-sm">Administrador</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="role" value="employee" <?= $role === 'employee' || empty($role) ? 'checked' : '' ?> class="h-4 w-4 text-industrial-blue">
                            <span class="text-sm">Colaborador</span>
                        </label>
                    </div>
                </div>

                <input type="hidden" id="role-platform" name="role-platform-val" value="superadmin">

                <button type="submit" id="submit-btn"
                    class="w-full bg-industrial-blue hover:bg-blue-800 text-white font-medium py-2.5 rounded-md transition">
                    Entrar
                </button>
            </form>

            <div class="mt-6 pt-4 border-t border-gray-200 text-center space-y-2">
                <a href="checkout.php" class="block text-sm text-industrial-blue hover:underline font-medium">Não tem conta? Criar gratuitamente →</a>
                <a href="<?= BASE_URL ?>/" class="block text-sm text-gray-500 hover:text-gray-700">← Voltar ao site</a>
            </div>
        </div>

        <p class="mt-6 text-center text-sm text-white/80">
            QRCertify &mdash; Gestão de Certificados de NR
        </p>
    </div>

    <script>
        const tabTenant = document.getElementById('tab-tenant');
        const tabPlatform = document.getElementById('tab-platform');
        const roleTenant = document.getElementById('role-tenant');
        const formTitle = document.getElementById('form-title');
        const formDesc = document.getElementById('form-desc');
        const radios = document.querySelectorAll('input[name="role"]');

        function setTab(which) {
            if (which === 'platform') {
                tabPlatform.classList.add('bg-white/20', 'text-white');
                tabPlatform.classList.remove('text-white/70');
                tabTenant.classList.remove('bg-white/20');
                tabTenant.classList.add('text-white/70');
                roleTenant.style.display = 'none';
                formTitle.textContent = 'Plataforma SaaS';
                formDesc.textContent = 'Acesso administrativo à plataforma multi-tenant';
                radios.forEach(r => r.checked = false);
                let hidden = document.querySelector('input[name="role"][type="hidden"]');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'role';
                    hidden.id = 'role-super-hidden';
                    document.querySelector('form').appendChild(hidden);
                }
                hidden.value = 'superadmin';
            } else {
                tabTenant.classList.add('bg-white/20', 'text-white');
                tabTenant.classList.remove('text-white/70');
                tabPlatform.classList.remove('bg-white/20');
                tabPlatform.classList.add('text-white/70');
                roleTenant.style.display = '';
                formTitle.textContent = 'QRCertify';
                formDesc.textContent = 'Faça login em sua conta de empresa para acessar os certificados';
                const hidden = document.getElementById('role-super-hidden');
                if (hidden) hidden.remove();
            }
        }

        tabTenant.addEventListener('click', () => setTab('tenant'));
        tabPlatform.addEventListener('click', () => setTab('platform'));
    </script>
</body>
</html>
