<?php
/**
 * Funções de autenticação e autorização
 */

// Limite de tentativas de login por IP (janela de 15 minutos)
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_WINDOW_SECONDS', 15 * 60);

/**
 * Conta tentativas de login falhas recentes para um IP.
 */
function countLoginFailures(string $ip): int {
    try {
        $db    = getDB();
        $since = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_SECONDS);
        $stmt  = $db->prepare(
            'SELECT COUNT(*) FROM audit_log
             WHERE action = "login.fail" AND ip = ? AND created_at >= ?'
        );
        $stmt->execute([$ip, $since]);
        return (int) $stmt->fetchColumn();
    } catch (\Exception $e) {
        return 0;
    }
}

/**
 * Tenta autenticar um usuário com email + senha + role.
 * Retorna o array do usuário em caso de sucesso, null se falhar,
 * ou a string 'blocked' se o IP estiver bloqueado por brute-force.
 */
function attemptLogin(string $email, string $password, string $role): array|null|string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // ── Rate limiting ─────────────────────────────────────────
    if (countLoginFailures($ip) >= LOGIN_MAX_ATTEMPTS) {
        return 'blocked';
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id, company_id, name, email, password, role, photo, status
         FROM users
         WHERE email = ? AND role = ? AND status = "active"
         LIMIT 1'
    );
    $stmt->execute([$email, $role]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        // Registra falha no audit_log
        auditLog('login.fail', "email:{$email}", json_encode(['role' => $role, 'ip' => $ip]));
        return null;
    }

    // Login bem-sucedido
    unset($user['password']);
    $_SESSION['user'] = $user;
    auditLog('login.success', "user#{$user['id']}", json_encode(['role' => $role]));
    return $user;
}

/**
 * Encerra a sessão do usuário.
 */
function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Retorna o usuário logado (array) ou null.
 */
function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * Verifica se há usuário logado.
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user']);
}

/**
 * Verifica se o usuário logado tem uma role específica.
 */
function hasRole(string $role): bool {
    return isLoggedIn() && $_SESSION['user']['role'] === $role;
}

/**
 * Exige que o usuário esteja logado. Se não estiver, redireciona pro login.
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        setFlash('error', 'Você precisa fazer login para acessar esta página.');
        redirect('/login.php');
    }

    // Bloqueia empresas canceladas (exceto superadmin)
    $user = currentUser();
    if ($user['role'] !== 'superadmin' && !empty($user['company_id'])) {
        try {
            $db   = getDB();
            $stmt = $db->prepare('SELECT subscription_status FROM companies WHERE id = ?');
            $stmt->execute([$user['company_id']]);
            $status = $stmt->fetchColumn();
            if ($status === 'pending_checkout') {
                session_destroy();
                setFlash('error', 'Finalize o checkout seguro do Asaas para ativar seus 30 dias gratis.');
                redirect('/login.php');
            }
            if ($status === 'canceled') {
                session_destroy();
                setFlash('error', 'Sua assinatura foi cancelada. Entre em contato ou assine novamente.');
                redirect('/login.php');
            }
        } catch (\Exception $e) { /* não bloqueia por erro de DB */ }
    }
}

/**
 * Exige uma role específica. Se não tiver, redireciona pra home apropriada.
 */
function requireRole(string ...$allowedRoles): void {
    requireLogin();
    $user = currentUser();

    if (!in_array($user['role'], $allowedRoles, true)) {
        setFlash('error', 'Você não tem permissão para acessar esta página.');

        // Redireciona pra dashboard da role do usuário
        switch ($user['role']) {
            case 'superadmin':
                redirect('/superadmin/dashboard.php');
                break;
            case 'admin':
                redirect('/admin/dashboard.php');
                break;
            case 'employee':
                redirect('/employee/certificates.php');
                break;
            default:
                redirect('/login.php');
        }
    }
}

/**
 * Redireciona o usuário para sua dashboard correta após login.
 */
function redirectToDashboard(string $role): void {
    switch ($role) {
        case 'superadmin':
            redirect('/superadmin/dashboard.php');
            break;
        case 'admin':
            redirect('/admin/dashboard.php');
            break;
        case 'employee':
            redirect('/employee/certificates.php');
            break;
        default:
            redirect('/login.php');
    }
}
