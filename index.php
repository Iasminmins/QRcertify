<?php
/**
 * Página inicial: landing para visitantes, dashboard para usuários logados.
 */
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) {
    redirectToDashboard(currentUser()['role']);
}

header('Content-Type: text/html; charset=UTF-8');
renderLandingPage();
