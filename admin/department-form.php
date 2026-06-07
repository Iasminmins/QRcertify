<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$user      = currentUser();
$companyId = $user['company_id'];
$db        = getDB();

$id     = (int)($_GET['id'] ?? 0);
$dept   = null;
$errors = [];

if ($id > 0) {
    $s = $db->prepare('SELECT * FROM departments WHERE id = ? AND company_id = ? LIMIT 1');
    $s->execute([$id, $companyId]);
    $dept = $s->fetch();
    if (!$dept) { redirect('/admin/departments.php'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) { die('CSRF inválido.'); }

    $name         = trim($_POST['name']          ?? '');
    $managerName  = trim($_POST['manager_name']  ?? '') ?: null;
    $managerEmail = trim($_POST['manager_email'] ?? '') ?: null;
    $managerPhone = trim($_POST['manager_phone'] ?? '') ?: null;

    if ($name === '')            $errors[] = 'O nome da área é obrigatório.';
    if (mb_strlen($name) > 100) $errors[] = 'Nome muito longo (máx. 100 caracteres).';
    if ($managerEmail && !filter_var($managerEmail, FILTER_VALIDATE_EMAIL))
        $errors[] = 'E-mail do gestor inválido.';

    if (empty($errors)) {
        if ($id > 0) {
            $db->prepare(
                'UPDATE departments SET name=?, manager_name=?, manager_email=?, manager_phone=?
                 WHERE id=? AND company_id=?'
            )->execute([$name, $managerName, $managerEmail, $managerPhone, $id, $companyId]);
            auditLog('department.update', "department #{$id}", $name);
            setFlash('success', 'Área atualizada com sucesso.');
        } else {
            $db->prepare(
                'INSERT INTO departments (company_id, name, manager_name, manager_email, manager_phone)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$companyId, $name, $managerName, $managerEmail, $managerPhone]);
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

<div class="mb-6">
    <a href="<?= BASE_URL ?>/admin/departments.php"
       class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-industrial-blue transition">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Voltar para Áreas e Setores
    </a>
    <h1 class="text-2xl font-bold text-gray-900 mt-3"><?= $id > 0 ? 'Editar Área' : 'Nova Área' ?></h1>
    <p class="text-sm text-gray-500 mt-0.5">
        <a href="<?= BASE_URL ?>/admin/departments.php" class="text-industrial-blue hover:underline">Áreas e Setores</a>
        / <?= $id > 0 ? e($dept['name']) : 'Nova' ?>
    </p>
</div>

<?php if (!empty($errors)): ?>
<div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 text-sm space-y-1">
    <?php foreach ($errors as $err): ?><div>• <?= e($err) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="section-card max-w-xl">
    <div class="section-card-header">
        <h2 class="section-card-title flex items-center gap-2">
            <svg class="h-5 w-5 text-industrial-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
            </svg>
            Dados da Área
        </h2>
    </div>
    <div class="section-card-body">
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>

            <!-- Nome da área -->
            <div>
                <label class="form-label" for="name">Nome da Área *</label>
                <input class="form-input w-full" type="text" id="name" name="name"
                       required maxlength="100"
                       placeholder="Ex: Manutenção, Logística, Segurança"
                       value="<?= e($dept['name'] ?? '') ?>">
            </div>

            <!-- Divisor -->
            <div class="pt-2 border-t border-gray-100">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">
                    Gestor Responsável
                    <span class="font-normal normal-case text-gray-400 ml-1">(opcional)</span>
                </p>

                <!-- Nome -->
                <div class="mb-3">
                    <label class="form-label" for="manager_name">Nome</label>
                    <input class="form-input w-full" type="text" id="manager_name" name="manager_name"
                           maxlength="150" placeholder="Ex: Carlos Oliveira"
                           value="<?= e($dept['manager_name'] ?? '') ?>">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <!-- E-mail -->
                    <div>
                        <label class="form-label" for="manager_email">E-mail</label>
                        <div class="relative">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400 pointer-events-none"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            <input class="form-input w-full pl-9" type="email" id="manager_email"
                                   name="manager_email" maxlength="255"
                                   placeholder="gestor@empresa.com"
                                   value="<?= e($dept['manager_email'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Telefone -->
                    <div>
                        <label class="form-label" for="manager_phone">Telefone / WhatsApp</label>
                        <div class="relative">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400 pointer-events-none"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                            </svg>
                            <input class="form-input w-full pl-9" type="text" id="manager_phone"
                                   name="manager_phone" maxlength="20"
                                   placeholder="(62) 99999-0000"
                                   value="<?= e($dept['manager_phone'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <?php if (!empty($dept['manager_email'])): ?>
                <div class="mt-3 flex items-center gap-2">
                    <a href="mailto:<?= e($dept['manager_email']) ?>"
                       class="inline-flex items-center gap-1.5 text-xs text-industrial-blue hover:underline">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        Enviar e-mail para <?= e($dept['manager_name'] ?? 'gestor') ?>
                    </a>
                    <?php if (!empty($dept['manager_phone'])): ?>
                    <span class="text-gray-300">·</span>
                    <a href="https://wa.me/55<?= preg_replace('/\D/', '', $dept['manager_phone']) ?>" target="_blank"
                       class="inline-flex items-center gap-1.5 text-xs text-green-600 hover:underline">
                        <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                            <path d="M11.5 2C6.262 2 2 6.262 2 11.5c0 1.888.518 3.65 1.42 5.163L2 22l5.463-1.392A9.484 9.484 0 0011.5 21C16.738 21 21 16.738 21 11.5S16.738 2 11.5 2zm0 17.25a7.74 7.74 0 01-3.963-1.09l-.284-.169-2.95.752.78-2.876-.185-.295A7.75 7.75 0 1111.5 19.25z"/>
                        </svg>
                        WhatsApp
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="btn btn-primary">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    <?= $id > 0 ? 'Salvar Alterações' : 'Criar Área' ?>
                </button>
                <a href="<?= BASE_URL ?>/admin/departments.php" class="btn btn-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
