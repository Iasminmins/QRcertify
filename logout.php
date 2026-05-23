<?php
require_once __DIR__ . '/config/config.php';
logout();
session_start();
setFlash('info', 'Sessão encerrada com sucesso.');
redirect('/');
