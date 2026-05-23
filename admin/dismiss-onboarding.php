<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');
$_SESSION['onboarding_dismissed'] = true;
http_response_code(200);
echo 'OK';
