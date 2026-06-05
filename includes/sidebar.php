<?php
/**
 * Sidebar de navegação — varia por role
 */
$user    = currentUser();
$role    = $user['role'] ?? '';
$current = $_SERVER['SCRIPT_NAME'] ?? '';

function navLink(string $href, string $icon, string $label): void {
    $current = $_SERVER['SCRIPT_NAME'] ?? '';
    $active  = strpos($current, parse_url($href, PHP_URL_PATH)) !== false ? 'active' : '';
    echo '<a href="' . $href . '" class="nav-link ' . $active . '">';
    echo '<svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.75">';
    echo $icon;
    echo '</svg>';
    echo '<span class="nav-label">' . $label . '</span>';
    echo '</a>';
}

$icons = [
    'dashboard'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>',
    'employees'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>',
    'certificates' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
    'settings'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
    'companies'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>',
    'mycerts'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
    'notify'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>',
    'export'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>',
    'report'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
    'audit'        => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>',
    'departments'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>',
    'billing'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>',
];

$base = BASE_URL;
?>

<!-- ══════════════════════════════════════════════════════════
     SIDEBAR
     ══════════════════════════════════════════════════════════ -->
<aside id="sidebar">

    <!-- Brand no topo da sidebar (desktop) -->
    <div class="flex items-center gap-3 px-4 py-4 border-b border-gray-100 flex-shrink-0">
        <img src="<?= BASE_URL ?>/public/assets/logo-qrcertify.svg"
             alt="QRCertify"
             class="brand-logo brand-logo-sidebar"
             style="width:2rem;height:2rem;max-width:2rem;max-height:2rem;object-fit:contain">
        <span class="sidebar-brand-text text-sm font-bold text-gray-700 leading-tight transition-all duration-200">
            QRCertify
        </span>
    </div>

    <!-- Navegação -->
    <nav class="flex-1 overflow-y-auto px-3 py-3 space-y-0.5">

        <?php if ($role === 'superadmin'): ?>
            <div class="nav-section-label">Plataforma</div>
            <?php navLink("{$base}/superadmin/dashboard.php",  $icons['dashboard'],    'Dashboard') ?>
            <?php navLink("{$base}/superadmin/companies.php",  $icons['companies'],    'Empresas') ?>

        <?php elseif ($role === 'admin'): ?>
            <div class="nav-section-label">Gestão</div>
            <?php navLink("{$base}/admin/dashboard.php",    $icons['dashboard'],    'Dashboard') ?>
            <?php navLink("{$base}/admin/employees.php",          $icons['employees'],    'Colaboradores') ?>
            <?php navLink("{$base}/admin/departments.php",        $icons['departments'], 'Áreas e Setores') ?>
            <?php navLink("{$base}/admin/certificates.php",       $icons['certificates'],'Certificados') ?>
            <?php navLink("{$base}/admin/employee-import.php",    $icons['export'],      'Importar Colaboradores') ?>
            <?php navLink("{$base}/admin/certificate-import.php", $icons['export'],      'Importar Certificados') ?>
            <?php navLink("{$base}/admin/aso-import.php",         $icons['export'],      'Importar ASO') ?>

            <div class="nav-section-label" style="margin-top:.75rem">Ferramentas</div>
            <?php navLink("{$base}/admin/notify-expiring.php",    $icons['notify'],  'Notificações') ?>
            <?php navLink("{$base}/admin/export.php",             $icons['export'],  'Exportar') ?>
            <?php navLink("{$base}/admin/report-uncertified.php", $icons['report'],  'Conformidade') ?>
            <?php navLink("{$base}/admin/audit-log.php",          $icons['audit'],   'Auditoria') ?>
            <div class="nav-section-label" style="margin-top:.75rem">Conta</div>
            <?php navLink("{$base}/admin/settings.php", $icons['settings'], 'Configurações') ?>
            <?php navLink("{$base}/admin/plano.php",    $icons['billing'],     'Meu Plano') ?>

        <?php elseif ($role === 'employee'): ?>
            <div class="nav-section-label">Minha Área</div>
            <?php navLink("{$base}/employee/certificates.php", $icons['mycerts'], 'Meus Certificados') ?>
        <?php endif; ?>

    </nav>

    <!-- Rodapé da sidebar: usuário -->
    <?php if ($user): ?>
    <div class="border-t border-gray-100 px-3 py-3 flex-shrink-0">
        <div class="flex items-center gap-2.5 px-2 py-2 rounded-lg bg-gray-50">
            <div class="h-8 w-8 rounded-full bg-industrial-blue flex items-center justify-center overflow-hidden flex-shrink-0">
                <?php if (!empty($user['photo'])): ?>
                    <img src="<?= e($user['photo']) ?>" alt="" class="h-full w-full object-cover">
                <?php else: ?>
                    <span class="text-xs font-bold text-white">
                        <?= e(strtoupper(mb_substr($user['name'], 0, 1))) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="flex-1 min-w-0 nav-label">
                <div class="text-xs font-semibold text-gray-800 truncate"><?= e($user['name']) ?></div>
                <div class="text-xs text-gray-400 capitalize"><?= e($user['role']) ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</aside>

<!-- ══════════════════════════════════════════════════════════
     MAIN CONTENT
     ══════════════════════════════════════════════════════════ -->
<div id="main-content">
