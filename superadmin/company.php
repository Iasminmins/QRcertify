<?php
require_once __DIR__ . '/../config/config.php';
requireRole('superadmin');

$db = getDB();
$errors = [];
$companyId = (int) ($_GET['id'] ?? $_POST['company_id'] ?? 0);

if ($companyId <= 0) {
    setFlash('error', 'Empresa invalida.');
    redirect('/superadmin/companies.php');
}

$stmt = $db->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

if (!$company) {
    setFlash('error', 'Empresa nao encontrada.');
    redirect('/superadmin/companies.php');
}

$period = $_GET['period'] ?? $_POST['period'] ?? '30';
$validPeriods = ['today', '7', '30', 'month', 'all'];
if (!in_array($period, $validPeriods, true)) {
    $period = '30';
}
$periodLabels = [
    'today' => 'Hoje',
    '7' => '7 dias',
    '30' => '30 dias',
    'month' => 'Este mes',
    'all' => 'Todo periodo',
];
$accessSince = match ($period) {
    'today' => date('Y-m-d 00:00:00'),
    '7' => date('Y-m-d H:i:s', strtotime('-7 days')),
    '30' => date('Y-m-d H:i:s', strtotime('-30 days')),
    'month' => date('Y-m-01 00:00:00'),
    default => null,
};
$accessDateSql = $accessSince ? ' AND a.created_at >= ?' : '';
$companyUrl = '/superadmin/company.php?id=' . $companyId . '&period=' . urlencode($period);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrfCheck()) {
        $errors[] = 'Token invalido.';
    } else {
        $action = $_POST['action'];
        $minLen = defined('MIN_PASSWORD_LENGTH') ? MIN_PASSWORD_LENGTH : 8;

        if ($action === 'toggle_user') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $stmtUser = $db->prepare(
                'SELECT id, name, email, status, role
                   FROM users
                  WHERE id = ? AND company_id = ? AND role IN ("admin", "employee")
                  LIMIT 1'
            );
            $stmtUser->execute([$userId, $companyId]);
            $targetUser = $stmtUser->fetch();

            if (!$targetUser) {
                $errors[] = 'Usuario nao encontrado nesta empresa.';
            } else {
                $newStatus = $targetUser['status'] === 'active' ? 'inactive' : 'active';
                $stmtUpdate = $db->prepare('UPDATE users SET status = ? WHERE id = ? AND company_id = ?');
                $stmtUpdate->execute([$newStatus, $userId, $companyId]);

                auditLog(
                    'superadmin.user_status',
                    "user#{$userId}",
                    json_encode(['company_id' => $companyId, 'email' => $targetUser['email'], 'status' => $newStatus]),
                    'autenticacao',
                    'Superadmin alterou o status de acesso de um usuario.',
                    'user',
                    $userId,
                    $targetUser['email'] ?? null,
                    ['status' => $targetUser['status']],
                    ['status' => $newStatus]
                );

                setFlash('success', $newStatus === 'active' ? 'Usuario reativado.' : 'Usuario bloqueado.');
                redirect($companyUrl);
            }
        } elseif ($action === 'create_employee_access') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $newPassword = trim($_POST['new_password'] ?? '');
            $confirmPassword = trim($_POST['confirm_password'] ?? '');

            if (strlen($newPassword) < $minLen) {
                $errors[] = "A senha precisa ter pelo menos {$minLen} caracteres.";
            }
            if ($newPassword !== $confirmPassword) {
                $errors[] = 'A confirmacao da senha nao confere.';
            }

            if (empty($errors)) {
                $stmtEmp = $db->prepare(
                    'SELECT id, name, email, user_id, status
                       FROM employees
                      WHERE id = ? AND company_id = ?
                      LIMIT 1'
                );
                $stmtEmp->execute([$employeeId, $companyId]);
                $employee = $stmtEmp->fetch();

                if (!$employee) {
                    $errors[] = 'Funcionario nao encontrado nesta empresa.';
                } elseif (empty($employee['email'])) {
                    $errors[] = 'Este funcionario nao tem email cadastrado.';
                } elseif (!empty($employee['user_id'])) {
                    $errors[] = 'Este funcionario ja possui usuario vinculado.';
                } else {
                    $stmtExisting = $db->prepare('SELECT id, company_id, role FROM users WHERE email = ? LIMIT 1');
                    $stmtExisting->execute([$employee['email']]);
                    $existingUser = $stmtExisting->fetch();

                    if ($existingUser && (int) $existingUser['company_id'] !== $companyId) {
                        $errors[] = 'Ja existe usuario com este email em outra empresa.';
                    } elseif ($existingUser && $existingUser['role'] !== 'employee') {
                        $errors[] = 'Ja existe usuario com este email em outro perfil.';
                    } else {
                        $db->beginTransaction();
                        try {
                            if ($existingUser) {
                                $newUserId = (int) $existingUser['id'];
                                $stmtUser = $db->prepare('UPDATE users SET name = ?, password = ?, status = "active" WHERE id = ? AND company_id = ?');
                                $stmtUser->execute([$employee['name'], password_hash($newPassword, PASSWORD_DEFAULT), $newUserId, $companyId]);
                            } else {
                                $stmtUser = $db->prepare(
                                    'INSERT INTO users (company_id, name, email, password, role, status)
                                     VALUES (?, ?, ?, ?, "employee", "active")'
                                );
                                $stmtUser->execute([
                                    $companyId,
                                    $employee['name'],
                                    $employee['email'],
                                    password_hash($newPassword, PASSWORD_DEFAULT),
                                ]);
                                $newUserId = (int) $db->lastInsertId();
                            }

                            $stmtLink = $db->prepare('UPDATE employees SET user_id = ? WHERE id = ? AND company_id = ?');
                            $stmtLink->execute([$newUserId, $employeeId, $companyId]);

                            $db->commit();

                            auditLog(
                                'superadmin.employee_access_create',
                                "employee#{$employeeId}",
                                json_encode(['company_id' => $companyId, 'email' => $employee['email'], 'user_id' => $newUserId]),
                                'autenticacao',
                                'Superadmin criou acesso para funcionario.',
                                'user',
                                $newUserId,
                                $employee['email'] ?? null
                            );

                            setFlash('success', 'Acesso criado para ' . $employee['email'] . '.');
                            redirect($companyUrl);
                        } catch (Throwable $e) {
                            if ($db->inTransaction()) {
                                $db->rollBack();
                            }
                            $errors[] = 'Erro ao criar acesso: ' . $e->getMessage();
                        }
                    }
                }
            }
        } elseif ($action === 'reset_password') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $newPassword = trim($_POST['new_password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if ($userId <= 0) {
            $errors[] = 'Usuario invalido.';
        }
        if (strlen($newPassword) < $minLen) {
            $errors[] = "A senha precisa ter pelo menos {$minLen} caracteres.";
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'A confirmacao da senha nao confere.';
        }

        if (empty($errors)) {
            $stmtUser = $db->prepare(
                'SELECT id, name, email, role
                   FROM users
                  WHERE id = ?
                    AND company_id = ?
                    AND role IN ("admin", "employee")
                  LIMIT 1'
            );
            $stmtUser->execute([$userId, $companyId]);
            $targetUser = $stmtUser->fetch();

            if (!$targetUser) {
                $errors[] = 'Usuario nao encontrado nesta empresa.';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmtUpdate = $db->prepare('UPDATE users SET password = ? WHERE id = ? AND company_id = ?');
                $stmtUpdate->execute([$hash, $userId, $companyId]);

                auditLog(
                    'superadmin.password_reset',
                    "user#{$userId}",
                    json_encode(['company_id' => $companyId, 'email' => $targetUser['email']]),
                    'autenticacao',
                    'Superadmin redefiniu a senha de um usuario.',
                    'user',
                    $userId,
                    $targetUser['email'] ?? null
                );

                setFlash('success', 'Senha redefinida para ' . $targetUser['email'] . '.');
                redirect($companyUrl);
            }
        }
        }
    }
}

$countsStmt = $db->prepare(
    'SELECT
        (SELECT COUNT(*) FROM users WHERE company_id = ?) AS users_total,
        (SELECT COUNT(*) FROM users WHERE company_id = ? AND status = "active") AS users_active,
        (SELECT COUNT(*) FROM users WHERE company_id = ? AND role = "employee") AS employee_users_total,
        (SELECT COUNT(*) FROM users WHERE company_id = ? AND role = "employee" AND status = "active") AS employee_users_active,
        (SELECT COUNT(*) FROM employees WHERE company_id = ?) AS employees_total,
        (SELECT COUNT(*) FROM employees WHERE company_id = ? AND status = "active") AS employees_active,
        (SELECT COUNT(*)
           FROM employees e
           INNER JOIN users u ON u.id = e.user_id
          WHERE e.company_id = ? AND e.status = "active" AND u.status = "active") AS employees_with_active_access,
        (SELECT COUNT(*) FROM certificates c
           INNER JOIN employees e ON e.id = c.employee_id
          WHERE e.company_id = ?) AS certs_total'
);
$countsStmt->execute([
    $companyId, $companyId, $companyId, $companyId,
    $companyId, $companyId, $companyId, $companyId,
]);
$counts = $countsStmt->fetch() ?: [];

$accessSql = 'SELECT COUNT(*) AS total_accesses, MAX(a.created_at) AS last_access
       FROM audit_log a
      WHERE a.action = "login.success"'
        . $accessDateSql .
        ' AND (
            company_id = ?
            OR user_id IN (SELECT id FROM users WHERE company_id = ?)
        )';
$accessParams = $accessSince ? [$accessSince, $companyId, $companyId] : [$companyId, $companyId];
$accessStmt = $db->prepare($accessSql);
$accessStmt->execute($accessParams);
$accessStats = $accessStmt->fetch() ?: ['total_accesses' => 0, 'last_access' => null];

$usersStmt = $db->prepare(
    'SELECT u.id, u.name, u.email, u.role, u.status, u.created_at,
            e.id AS employee_id,
            e.name AS employee_name,
            e.status AS employee_status,
            (SELECT COUNT(*) FROM audit_log a WHERE a.user_id = u.id AND a.action = "login.success"' . $accessDateSql . ') AS access_count,
            (SELECT MAX(a.created_at) FROM audit_log a WHERE a.user_id = u.id AND a.action = "login.success") AS last_access
       FROM users u
       LEFT JOIN employees e ON e.user_id = u.id
      WHERE u.company_id = ?
      ORDER BY FIELD(u.role, "admin", "employee"), u.status DESC, u.name ASC'
);
$usersStmt->execute($accessSince ? [$accessSince, $companyId] : [$companyId]);
$users = $usersStmt->fetchAll();

$employeesStmt = $db->prepare(
    'SELECT e.id, e.name, e.email, e.role, e.status, e.created_at,
            u.id AS user_id,
            u.email AS login_email,
            u.status AS user_status,
            (SELECT COUNT(*) FROM audit_log a WHERE a.user_id = u.id AND a.action = "login.success"' . $accessDateSql . ') AS access_count,
            (SELECT MAX(a.created_at) FROM audit_log a WHERE a.user_id = u.id AND a.action = "login.success") AS last_access
       FROM employees e
       LEFT JOIN users u ON u.id = e.user_id
      WHERE e.company_id = ?
      ORDER BY e.status DESC, e.name ASC'
);
$employeesStmt->execute($accessSince ? [$accessSince, $companyId] : [$companyId]);
$employees = $employeesStmt->fetchAll();

$recentAccessSql = 'SELECT a.created_at, a.action, a.ip, a.target, u.name, u.email, u.role
       FROM audit_log a
       LEFT JOIN users u ON u.id = a.user_id
      WHERE a.action IN ("login.success", "login.fail")'
        . $accessDateSql .
        ' AND (
            a.company_id = ?
            OR a.user_id IN (SELECT id FROM users WHERE company_id = ?)
        )
      ORDER BY a.created_at DESC
      LIMIT 12';
$recentAccessStmt = $db->prepare($recentAccessSql);
$recentAccessStmt->execute($accessSince ? [$accessSince, $companyId, $companyId] : [$companyId, $companyId]);
$recentAccesses = $recentAccessStmt->fetchAll();

$healthStmt = $db->prepare(
    'SELECT
        (SELECT COUNT(*)
           FROM users u
          WHERE u.company_id = ? AND u.status = "active"
            AND NOT EXISTS (
                SELECT 1 FROM audit_log a
                 WHERE a.user_id = u.id
                   AND a.action = "login.success"
                   AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            )) AS users_without_recent_access,
        (SELECT COUNT(*)
           FROM employees e
           LEFT JOIN users u ON u.id = e.user_id
          WHERE e.company_id = ?
            AND e.status = "active"
            AND (e.user_id IS NULL OR u.status <> "active")) AS employees_without_access,
        (SELECT COUNT(*) FROM users WHERE company_id = ? AND status <> "active") AS inactive_users,
        (SELECT COUNT(*)
           FROM certificates c
           INNER JOIN employees e ON e.id = c.employee_id
          WHERE e.company_id = ? AND c.status = "expired") AS expired_certs'
);
$healthStmt->execute([$companyId, $companyId, $companyId, $companyId]);
$health = $healthStmt->fetch() ?: [];

$adminHistoryStmt = $db->prepare(
    'SELECT a.created_at, a.action, a.description, a.target, a.entity_name, a.ip,
            actor.name AS actor_name,
            actor.email AS actor_email
       FROM audit_log a
       LEFT JOIN users actor ON actor.id = a.user_id
      WHERE a.action NOT IN ("login.success", "login.fail")
        AND (
            a.company_id = ?
            OR (a.entity_type = "user" AND a.entity_id IN (SELECT id FROM users WHERE company_id = ?))
            OR (a.entity_type = "employee" AND a.entity_id IN (SELECT id FROM employees WHERE company_id = ?))
            OR a.detail LIKE ?
        )
      ORDER BY a.created_at DESC
      LIMIT 12'
);
$adminHistoryStmt->execute([$companyId, $companyId, $companyId, '%"company_id":' . $companyId . '%']);
$adminHistory = $adminHistoryStmt->fetchAll();

$plans = getPlansCatalog();
$planKey = normalizePlanKey($company['plan'] ?? 'starter');
$plan = $plans[$planKey] ?? $plans['starter'];
$subLabels = [
    'active' => 'Ativo',
    'trialing' => 'Trial',
    'pending_checkout' => 'Checkout pendente',
    'overdue' => 'Inadimplente',
    'canceled' => 'Cancelado',
];

$fmtDateTime = static fn($value): string => $value ? date('d/m/Y H:i', strtotime($value)) : '-';
$statusBadge = static function (?string $status): string {
    $badge = getStatusBadge($status ?: 'inactive');
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ' . $badge['class'] . '">' . e($badge['label']) . '</span>';
};

$pageTitle = 'Empresa - ' . ($company['name'] ?? 'Detalhe');
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>/superadmin/companies.php" class="text-sm text-industrial-blue hover:underline">Voltar para empresas</a>
        <h1 class="page-title mt-1"><?= e($company['name']) ?></h1>
        <p class="page-subtitle"><?= e($company['email'] ?? '-') ?> - <?= e($company['cnpj'] ?? '-') ?></p>
    </div>
    <a href="<?= BASE_URL ?>/superadmin/companies.php" class="btn btn-secondary">Gerenciar empresas</a>
</div>

<?php renderFlashes(); ?>
<?php if (!empty($errors)): ?>
    <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <div>
        <p class="text-sm font-semibold text-gray-900">Periodo de acessos</p>
        <p class="text-xs text-gray-400">Os contadores de acesso usam o periodo selecionado.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <?php foreach ($periodLabels as $key => $label): ?>
            <a href="<?= BASE_URL ?>/superadmin/company.php?id=<?= (int) $companyId ?>&period=<?= e($key) ?>"
               class="px-3 py-1.5 rounded-md border text-sm font-medium <?= $period === $key ? 'bg-industrial-blue text-white border-industrial-blue' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50' ?>">
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Acessos no periodo</p>
        <p class="text-2xl font-extrabold text-gray-900"><?= (int) ($accessStats['total_accesses'] ?? 0) ?></p>
        <p class="text-xs text-gray-400 mt-1"><?= e($periodLabels[$period]) ?> - ultimo: <?= e($fmtDateTime($accessStats['last_access'] ?? null)) ?></p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Usuarios ativos</p>
        <p class="text-2xl font-extrabold text-green-600"><?= (int) ($counts['users_active'] ?? 0) ?></p>
        <p class="text-xs text-gray-400 mt-1"><?= (int) ($counts['users_total'] ?? 0) ?> usuarios cadastrados</p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Funcionarios com acesso</p>
        <p class="text-2xl font-extrabold text-blue-600"><?= (int) ($counts['employees_with_active_access'] ?? 0) ?></p>
        <p class="text-xs text-gray-400 mt-1"><?= (int) ($counts['employees_active'] ?? 0) ?> colaboradores ativos</p>
    </div>
    <div class="card p-5">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Certificados</p>
        <p class="text-2xl font-extrabold text-gray-900"><?= (int) ($counts['certs_total'] ?? 0) ?></p>
        <p class="text-xs text-gray-400 mt-1"><?= e($plan['name']) ?> - <?= e($subLabels[$company['subscription_status'] ?? ''] ?? ($company['subscription_status'] ?? '-')) ?></p>
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <div class="rounded-lg border border-amber-100 bg-amber-50 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Sem acesso recente</p>
        <p class="text-2xl font-extrabold text-amber-600 mt-1"><?= (int) ($health['users_without_recent_access'] ?? 0) ?></p>
        <p class="text-xs text-amber-700 mt-1">usuarios ativos sem login ha 30 dias</p>
    </div>
    <div class="rounded-lg border border-blue-100 bg-blue-50 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">Sem login</p>
        <p class="text-2xl font-extrabold text-blue-600 mt-1"><?= (int) ($health['employees_without_access'] ?? 0) ?></p>
        <p class="text-xs text-blue-700 mt-1">funcionarios ativos sem acesso ativo</p>
    </div>
    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-600">Bloqueados</p>
        <p class="text-2xl font-extrabold text-gray-900 mt-1"><?= (int) ($health['inactive_users'] ?? 0) ?></p>
        <p class="text-xs text-gray-500 mt-1">usuarios inativos</p>
    </div>
    <div class="rounded-lg border border-red-100 bg-red-50 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-red-700">Vencidos</p>
        <p class="text-2xl font-extrabold text-red-600 mt-1"><?= (int) ($health['expired_certs'] ?? 0) ?></p>
        <p class="text-xs text-red-700 mt-1">certificados expirados</p>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
    <div class="card p-6 xl:col-span-2">
        <div class="flex items-center justify-between gap-3 mb-4">
            <h2 class="font-bold text-gray-900">Usuarios da plataforma</h2>
            <span class="text-xs text-gray-400">admins e funcionarios com login</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3 text-left">Usuario</th>
                        <th class="px-4 py-3 text-left">Email</th>
                        <th class="px-4 py-3 text-left">Perfil</th>
                        <th class="px-4 py-3 text-center">Acessos</th>
                        <th class="px-4 py-3 text-left">Ultimo acesso</th>
                        <th class="px-4 py-3 text-right">Acoes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($users as $u): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900"><?= e($u['name']) ?></div>
                                <div class="text-xs text-gray-400"><?= $statusBadge($u['status']) ?></div>
                            </td>
                            <td class="px-4 py-3 text-gray-600"><?= e($u['email']) ?></td>
                            <td class="px-4 py-3 text-gray-600"><?= e($u['role']) ?></td>
                            <td class="px-4 py-3 text-center font-semibold"><?= (int) $u['access_count'] ?></td>
                            <td class="px-4 py-3 text-gray-600"><?= e($fmtDateTime($u['last_access'])) ?></td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-3">
                                <button type="button"
                                        onclick="document.getElementById('pwd-<?= (int) $u['id'] ?>').showModal()"
                                        class="text-sm text-industrial-blue font-medium hover:underline">
                                    Redefinir
                                </button>
                                <form method="POST" class="inline"
                                      onsubmit="return confirm('<?= $u['status'] === 'active' ? 'Bloquear o acesso deste usuario?' : 'Reativar o acesso deste usuario?' ?>');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_user">
                                    <input type="hidden" name="company_id" value="<?= (int) $companyId ?>">
                                    <input type="hidden" name="period" value="<?= e($period) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <button type="submit" class="text-sm <?= $u['status'] === 'active' ? 'text-red-600 hover:text-red-700' : 'text-green-600 hover:text-green-700' ?> hover:underline">
                                        <?= $u['status'] === 'active' ? 'Bloquear' : 'Reativar' ?>
                                    </button>
                                </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Nenhum usuario cadastrado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card p-6">
        <h2 class="font-bold text-gray-900 mb-4">Resumo da empresa</h2>
        <div class="space-y-3 text-sm">
            <div class="flex justify-between gap-4"><span class="text-gray-500">Plano</span><strong><?= e($plan['name']) ?></strong></div>
            <div class="flex justify-between gap-4"><span class="text-gray-500">Assinatura</span><strong><?= e($subLabels[$company['subscription_status'] ?? ''] ?? ($company['subscription_status'] ?? '-')) ?></strong></div>
            <div class="flex justify-between gap-4"><span class="text-gray-500">Status</span><span><?= $statusBadge($company['status'] ?? 'inactive') ?></span></div>
            <div class="flex justify-between gap-4"><span class="text-gray-500">Criada em</span><strong><?= e($fmtDateTime($company['created_at'] ?? null)) ?></strong></div>
            <div class="flex justify-between gap-4"><span class="text-gray-500">Atualizada em</span><strong><?= e($fmtDateTime($company['updated_at'] ?? null)) ?></strong></div>
            <div class="flex justify-between gap-4"><span class="text-gray-500">Telefone</span><strong><?= e($company['phone'] ?? '-') ?></strong></div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
    <div class="card p-6 xl:col-span-2">
        <div class="flex items-center justify-between gap-3 mb-4">
            <h2 class="font-bold text-gray-900">Funcionarios e acesso</h2>
            <span class="text-xs text-gray-400"><?= (int) ($counts['employees_total'] ?? 0) ?> colaboradores</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3 text-left">Funcionario</th>
                        <th class="px-4 py-3 text-left">Email cadastro</th>
                        <th class="px-4 py-3 text-left">Email login</th>
                        <th class="px-4 py-3 text-left">Acesso</th>
                        <th class="px-4 py-3 text-center">Acessos</th>
                        <th class="px-4 py-3 text-left">Ultimo acesso</th>
                        <th class="px-4 py-3 text-right">Acoes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($employees as $emp): ?>
                        <?php
                            $hasActiveAccess = !empty($emp['user_id']) && ($emp['user_status'] ?? '') === 'active';
                            $accessClass = $hasActiveAccess ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600';
                            $accessText = $hasActiveAccess ? 'Ativo' : (!empty($emp['user_id']) ? 'Usuario inativo' : 'Sem login');
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900"><?= e($emp['name']) ?></div>
                                <div class="text-xs text-gray-400"><?= e($emp['role'] ?? '-') ?> - <?= $statusBadge($emp['status']) ?></div>
                            </td>
                            <td class="px-4 py-3 text-gray-600"><?= e($emp['email']) ?></td>
                            <td class="px-4 py-3 text-gray-600"><?= e($emp['login_email'] ?? '-') ?></td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $accessClass ?>"><?= e($accessText) ?></span>
                            </td>
                            <td class="px-4 py-3 text-center font-semibold"><?= (int) ($emp['access_count'] ?? 0) ?></td>
                            <td class="px-4 py-3 text-gray-600"><?= e($fmtDateTime($emp['last_access'] ?? null)) ?></td>
                            <td class="px-4 py-3 text-right">
                                <?php if (empty($emp['user_id'])): ?>
                                    <button type="button"
                                            onclick="document.getElementById('access-<?= (int) $emp['id'] ?>').showModal()"
                                            class="text-sm text-industrial-blue font-medium hover:underline">
                                        Criar acesso
                                    </button>
                                <?php elseif (($emp['user_status'] ?? '') !== 'active'): ?>
                                    <span class="text-xs text-gray-400">Reative em usuarios</span>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">Ok</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($employees)): ?>
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Nenhum funcionario cadastrado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card p-6">
        <h2 class="font-bold text-gray-900 mb-4">Acessos recentes</h2>
        <div class="space-y-3">
            <?php foreach ($recentAccesses as $access): ?>
                <?php $ok = $access['action'] === 'login.success'; ?>
                <div class="border-b border-gray-100 pb-3 last:border-0 last:pb-0">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-gray-900"><?= e($access['name'] ?: ($access['email'] ?: $access['target'])) ?></p>
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $ok ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                            <?= $ok ? 'Login ok' : 'Falha' ?>
                        </span>
                    </div>
                    <p class="text-xs text-gray-400 mt-1"><?= e($fmtDateTime($access['created_at'])) ?> - <?= e($access['ip'] ?? '-') ?></p>
                </div>
            <?php endforeach; ?>
            <?php if (empty($recentAccesses)): ?>
                <p class="text-sm text-gray-400">Nenhum acesso registrado ainda.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card p-6 mb-6">
    <div class="flex items-center justify-between gap-3 mb-4">
        <h2 class="font-bold text-gray-900">Historico administrativo</h2>
        <span class="text-xs text-gray-400">ultimas alteracoes relacionadas a esta empresa</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 text-left">Data</th>
                    <th class="px-4 py-3 text-left">Acao</th>
                    <th class="px-4 py-3 text-left">Responsavel</th>
                    <th class="px-4 py-3 text-left">Alvo</th>
                    <th class="px-4 py-3 text-left">Descricao</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($adminHistory as $item): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-600"><?= e($fmtDateTime($item['created_at'])) ?></td>
                        <td class="px-4 py-3 font-medium text-gray-900"><?= e($item['action']) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= e($item['actor_name'] ?: ($item['actor_email'] ?: '-')) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= e($item['entity_name'] ?: ($item['target'] ?: '-')) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= e($item['description'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($adminHistory)): ?>
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Nenhuma acao administrativa registrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach ($users as $u): ?>
<dialog id="pwd-<?= (int) $u['id'] ?>" class="rounded-xl p-0 w-full max-w-md backdrop:bg-black/40">
    <form method="POST" class="text-left">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="company_id" value="<?= (int) $companyId ?>">
        <input type="hidden" name="period" value="<?= e($period) ?>">
        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
        <div class="flex items-center justify-between border-b p-4">
            <h3 class="font-bold text-gray-900">Redefinir senha</h3>
            <button type="button" onclick="document.getElementById('pwd-<?= (int) $u['id'] ?>').close()" class="text-gray-400 hover:text-gray-700 text-lg leading-none">x</button>
        </div>
        <div class="p-4 space-y-4">
            <div class="rounded-lg bg-gray-50 border border-gray-200 p-3">
                <p class="text-sm font-semibold text-gray-900"><?= e($u['name']) ?></p>
                <p class="text-xs text-gray-500"><?= e($u['email']) ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Nova senha</label>
                <input type="password" name="new_password" required minlength="<?= defined('MIN_PASSWORD_LENGTH') ? MIN_PASSWORD_LENGTH : 8 ?>" autocomplete="new-password" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar senha</label>
                <input type="password" name="confirm_password" required minlength="<?= defined('MIN_PASSWORD_LENGTH') ? MIN_PASSWORD_LENGTH : 8 ?>" autocomplete="new-password" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
        </div>
        <div class="flex justify-end gap-2 border-t p-4">
            <button type="button" onclick="document.getElementById('pwd-<?= (int) $u['id'] ?>').close()" class="px-4 py-2 text-sm border border-gray-200 rounded-md text-gray-600 hover:bg-gray-50">Cancelar</button>
            <button type="submit" class="px-4 py-2 text-sm bg-industrial-blue text-white rounded-md font-medium hover:bg-blue-800">Salvar senha</button>
        </div>
    </form>
</dialog>
<?php endforeach; ?>

<?php foreach ($employees as $emp): ?>
    <?php if (!empty($emp['user_id'])) continue; ?>
    <dialog id="access-<?= (int) $emp['id'] ?>" class="rounded-xl p-0 w-full max-w-md backdrop:bg-black/40">
        <form method="POST" class="text-left">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_employee_access">
            <input type="hidden" name="company_id" value="<?= (int) $companyId ?>">
            <input type="hidden" name="period" value="<?= e($period) ?>">
            <input type="hidden" name="employee_id" value="<?= (int) $emp['id'] ?>">
            <div class="flex items-center justify-between border-b p-4">
                <h3 class="font-bold text-gray-900">Criar acesso</h3>
                <button type="button" onclick="document.getElementById('access-<?= (int) $emp['id'] ?>').close()" class="text-gray-400 hover:text-gray-700 text-lg leading-none">x</button>
            </div>
            <div class="p-4 space-y-4">
                <div class="rounded-lg bg-gray-50 border border-gray-200 p-3">
                    <p class="text-sm font-semibold text-gray-900"><?= e($emp['name']) ?></p>
                    <p class="text-xs text-gray-500"><?= e($emp['email'] ?: 'Sem email cadastrado') ?></p>
                </div>
                <?php if (empty($emp['email'])): ?>
                    <p class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-md p-3">
                        Cadastre um email para este funcionario antes de criar acesso.
                    </p>
                <?php else: ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Senha inicial</label>
                        <input type="password" name="new_password" required minlength="<?= defined('MIN_PASSWORD_LENGTH') ? MIN_PASSWORD_LENGTH : 8 ?>" autocomplete="new-password" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar senha</label>
                        <input type="password" name="confirm_password" required minlength="<?= defined('MIN_PASSWORD_LENGTH') ? MIN_PASSWORD_LENGTH : 8 ?>" autocomplete="new-password" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    </div>
                <?php endif; ?>
            </div>
            <div class="flex justify-end gap-2 border-t p-4">
                <button type="button" onclick="document.getElementById('access-<?= (int) $emp['id'] ?>').close()" class="px-4 py-2 text-sm border border-gray-200 rounded-md text-gray-600 hover:bg-gray-50">Cancelar</button>
                <?php if (!empty($emp['email'])): ?>
                    <button type="submit" class="px-4 py-2 text-sm bg-industrial-blue text-white rounded-md font-medium hover:bg-blue-800">Criar acesso</button>
                <?php endif; ?>
            </div>
        </form>
    </dialog>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
