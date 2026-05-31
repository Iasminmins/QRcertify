<?php
/**
 * Header comum — admin / employee / superadmin
 * Variável esperada: $pageTitle (string)
 */
$pageTitle = $pageTitle ?? 'QRCertify';
$user = currentUser();
$initials = $user ? strtoupper(mb_substr($user['name'], 0, 1)) : '?';
$logoHref = BASE_URL . '/';
if ($user) {
    $logoHref = match ($user['role']) {
        'superadmin' => BASE_URL . '/superadmin/dashboard.php',
        'admin'      => BASE_URL . '/admin/dashboard.php',
        'employee'   => BASE_URL . '/employee/certificates.php',
        default      => BASE_URL . '/',
    };
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | <?= e(SITE_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/public/assets/logo-qrcertify.svg">

    <!-- Tailwind CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'industrial-blue':   '#1e3a8a',
                        'industrial-yellow': '#facc15',
                    }
                }
            }
        }
    </script>

    <!-- CSS customizado -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/style.css">

    <!-- Inter font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

<!-- ══════════════════════════════════════════════════════════
     HEADER FIXO
     ══════════════════════════════════════════════════════════ -->
<header id="app-header">

    <!-- Botão toggle sidebar (visível em mobile + no desktop) -->
    <button id="sidebar-toggle"
            class="flex-shrink-0 p-2 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700 transition"
            aria-label="Menu">
        <svg id="icon-menu" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
    </button>

    <!-- Logo + nome do sistema -->
    <a href="<?= $logoHref ?>" class="flex items-center gap-2.5 flex-shrink-0 no-underline">
        <img src="<?= BASE_URL ?>/public/assets/logo-qrcertify.svg"
             alt="QRCertify"
             class="brand-logo brand-logo-header">
        <span class="text-base font-bold text-gray-800 hidden sm:block leading-none">
            QRCertify
        </span>
    </a>

    <!-- Espaço flexível -->
    <div class="flex-1"></div>

    <!-- Área do usuário -->
    <?php if ($user): ?>
    <div class="flex items-center gap-3">
        <!-- Info do usuário (apenas desktop) -->
        <div class="hidden sm:block text-right leading-tight">
            <div class="text-sm font-semibold text-gray-800"><?= e($user['name']) ?></div>
            <div class="text-xs text-gray-400 capitalize"><?= e($user['role']) ?></div>
        </div>

        <!-- Avatar -->
        <div class="h-9 w-9 rounded-full bg-industrial-blue flex items-center justify-center
                    overflow-hidden ring-2 ring-industrial-blue/20 flex-shrink-0">
            <?php if (!empty($user['photo'])): ?>
                <img src="<?= e($user['photo']) ?>" alt="" class="h-full w-full object-cover">
            <?php else: ?>
                <span class="text-sm font-bold text-white"><?= $initials ?></span>
            <?php endif; ?>
        </div>

        <!-- Botão sair -->
        <a href="<?= BASE_URL ?>/logout.php"
           class="flex items-center gap-1.5 text-sm text-gray-500 hover:text-red-600
                  px-2.5 py-1.5 rounded-lg hover:bg-red-50 transition flex-shrink-0">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            <span class="hidden md:inline">Sair</span>
        </a>
    </div>
    <?php endif; ?>

</header>

<!-- Overlay mobile -->
<div id="sidebar-overlay"></div>
