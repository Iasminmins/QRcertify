<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$id     = (int)($_GET['id'] ?? 0);
$dept   = null;
$errors = [];

// Edição: carrega dept existente
if ($id > 0) {
    $s = $db->prepare('SELECT * FROM departments WHERE id = ? AND company_id = ? LIMIT 1');
    $s->execute([$id, $companyId]);
    $dept = $s->fetch();
    if (!$dept) { redirect('/admin/departments.php'); }
}

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) { die('CSRF inválido.'); }

    $name        = trim($_POST['name']         ?? '');
    $managerName = trim($_POST['manager_name'] ?? '') ?: null;

    if ($name === '')           { $errors[] = 'O nome da área é obrigatório.'; }
    if (mb_strlen($name) > 100) { $errors[] = 'Nome muito longo (máx. 100 caracteres).'; }

    if (empty($errors)) {
        if ($id > 0) {
            $s = $db->prepare(
                'UPDATE departments SET name = ?, manager_name = ? WHERE id = ? AND company_id = ?'
            );
            $s->execute([$name, $managerName, $id, $companyId]);
            auditLog('department.update', "department #{$id}", $name);
            setFlash('success', 'Área atualizada com sucesso.');
        } else {
            $s = $db->prepare(
                'INSERT INTO departments (company_id, name, manager_name) VALUES (?, ?, ?)'
            );
            $s->execute([$companyId, $name, $managerName]);
            $newId = $db->lastInsertId();
            auditLog('department.create', "department #{$newId}", $name);
            setFlash('success', 'Área criada com sucesso.');
        }
        redirect('/admin/departments.php');
    }
}

$pageTitle = $id > 0 ? 'Editar Área' : 'Nova Área';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= $id > 0 ? 'Editar Área' : 'Nova Área' ?></h1>
        <p class="page-subtitle">
            <a href="<?= BASE_URL ?>/admin/departments.php" class="hover:underline text-industrial-blue">
                Áreas e Setores
            </a> / <?= $id > 0 ? e($dept['name']) : 'Nova' ?>
        </p>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error mb-4">
    <?php foreach ($errors as $err): ?>
        <p><?= e($err) ?></p>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card max-w-lg">
    <form method="POST">
        <?= csrfField() ?>

        <div class="form-group">
            <label class="form-label" for="name">Nome da Área *</label>
            <input class="form-input" type="text" id="name" name="name" required
                   maxlength="100" placeholder="Ex: Manutenção, Logística, Segurança"
                   value="<?= e($dept['name'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label class="form-label" for="manager_name">Gestor Responsável</label>
            <input class="form-input" type="text" id="manager_name" name="manager_name"
                   maxlength="150" placeholder="Ex: Carlos Oliveira"
                   value="<?= e($dept['manager_name'] ?? '') ?>">
            <p class="text-xs text-gray-400 mt-1">Nome do responsável pela área. Não precisa ser um usuário do sistema.</p>
        </div>

        <div class="flex items-center gap-3 mt-6">
            <button type="submit" class="btn btn-primary">
                <?= $id > 0 ? 'Salvar Alterações' : 'Criar Área' ?>
            </button>
            <a href="<?= BASE_URL ?>/admin/departments.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
