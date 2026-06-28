<?php
require_once __DIR__ . '/../config/config.php';
requireRole('superadmin');

$db = getDB();
$errors = [];

// Criar nova empresa (formulário simples no topo da página)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrfCheck()) {
        $errors[] = 'Token inválido.';
    } else {
        $action = $_POST['action'];

        if ($action === 'create') {
            $name  = trim($_POST['name'] ?? '');
            $cnpj  = trim($_POST['cnpj'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if ($name === '') $errors[] = 'Nome é obrigatório.';

            if (empty($errors)) {
                try {
                    $stmt = $db->prepare(
                        'INSERT INTO companies (name, cnpj, email, phone) VALUES (?, ?, ?, ?)'
                    );
                    $stmt->execute([$name, $cnpj, $email, $phone]);
                    setFlash('success', 'Empresa cadastrada com sucesso!');
                    redirect('/superadmin/companies.php');
                } catch (PDOException $e) {
                    $errors[] = 'Erro ao cadastrar (CNPJ duplicado?): ' . $e->getMessage();
                }
            }
        } elseif ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $db->prepare(
                'UPDATE companies SET status = IF(status = "active", "inactive", "active") WHERE id = ?'
            );
            $stmt->execute([$id]);
            setFlash('success', 'Status atualizado.');
            redirect('/superadmin/companies.php');
        } elseif ($action === 'set_subscription') {
            $id   = (int) ($_POST['id'] ?? 0);
            $plan = $_POST['plan'] ?? 'starter';
            $sub  = $_POST['subscription_status'] ?? 'trialing';
            $renewTrial = !empty($_POST['renew_trial']);

            $validPlans = array_keys(getPlansCatalog());
            $validSubs  = ['trialing', 'active', 'pending_checkout', 'overdue', 'canceled'];
            if (!in_array($plan, $validPlans, true)) $plan = 'starter';
            if (!in_array($sub, $validSubs, true))   $sub  = 'trialing';

            // Monta o UPDATE. overdue_since acompanha o status:
            //  - overdue  -> grava hoje (inicia a régua de carência) se ainda vazio
            //  - active/trialing/outros -> limpa
            // renew_trial: zera a contagem do trial (trial_ends_at = hoje + 30) e força 'trialing'.
            if ($renewTrial) {
                $stmt = $db->prepare(
                    'UPDATE companies
                        SET plan = ?, subscription_status = "trialing",
                            overdue_since = NULL,
                            trial_ends_at = (CURDATE() + INTERVAL 30 DAY)
                      WHERE id = ?'
                );
                $stmt->execute([$plan, $id]);
            } elseif ($sub === 'overdue') {
                $stmt = $db->prepare(
                    'UPDATE companies
                        SET plan = ?, subscription_status = ?,
                            overdue_since = COALESCE(overdue_since, CURDATE())
                      WHERE id = ?'
                );
                $stmt->execute([$plan, $sub, $id]);
            } else {
                $stmt = $db->prepare(
                    'UPDATE companies
                        SET plan = ?, subscription_status = ?, overdue_since = NULL
                      WHERE id = ?'
                );
                $stmt->execute([$plan, $sub, $id]);
            }
            setFlash('success', 'Assinatura atualizada.');
            redirect('/superadmin/companies.php');
        }
    }
}

$companies = $db->query(
    'SELECT c.*,
            (SELECT COUNT(*) FROM employees WHERE company_id = c.id) AS emp_count,
            (SELECT COUNT(*) FROM users WHERE company_id = c.id) AS user_count
     FROM companies c
     ORDER BY c.created_at DESC'
)->fetchAll();

$pageTitle = 'Empresas';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Empresas (Tenants)</h1>
        <p class="text-gray-600">Gerenciamento de empresas da plataforma</p>
    </div>
</div>

<?php renderFlashes(); ?>
<?php if (!empty($errors)): ?>
    <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Form rápido -->
<details class="bg-white rounded-lg shadow-sm border mb-4">
    <summary class="p-4 cursor-pointer font-medium text-industrial-blue hover:bg-gray-50">
        + Cadastrar nova empresa
    </summary>
    <form method="POST" class="p-4 border-t space-y-3">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Nome *</label>
                <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">CNPJ</label>
                <input type="text" name="cnpj" placeholder="00.000.000/0000-00" class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Telefone</label>
                <input type="text" name="phone" class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
        </div>
        <button type="submit" class="bg-industrial-blue hover:bg-blue-800 text-white px-4 py-2 rounded-md font-medium">
            Cadastrar
        </button>
    </form>
</details>

<!-- Tabela -->
<div class="bg-white rounded-lg shadow-sm border overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empresa</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden md:table-cell">CNPJ</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden lg:table-cell">Email</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plano</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Assinatura</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Funcs.</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($companies as $c): ?>
                    <?php $badge = getStatusBadge($c['status']); ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3 text-sm font-medium"><?= e($c['name']) ?></td>
                        <td class="px-5 py-3 text-sm text-gray-600 hidden md:table-cell"><?= e($c['cnpj'] ?? '-') ?></td>
                        <td class="px-5 py-3 text-sm text-gray-600 hidden lg:table-cell"><?= e($c['email'] ?? '-') ?></td>
                        <td class="px-5 py-3 text-sm">
                          <span class="font-semibold text-gray-800"><?= ucfirst($c['plan'] ?? 'starter') ?></span>
                        </td>
                        <td class="px-5 py-3 text-sm">
                          <?php
                            $sub = $c['subscription_status'] ?? 'trialing';
                            $subColors = ['active'=>'green','trialing'=>'blue','pending_checkout'=>'gray','overdue'=>'red','canceled'=>'gray'];
                            $subLabels = ['active'=>'Ativo','trialing'=>'Trial','pending_checkout'=>'Checkout pendente','overdue'=>'Inadimplente','canceled'=>'Cancelado'];
                            $sc = $subColors[$sub] ?? 'gray';
                          ?>
                          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium
                            <?= $sc==='green'?'bg-green-100 text-green-700':($sc==='blue'?'bg-blue-100 text-blue-700':($sc==='red'?'bg-red-100 text-red-700':'bg-gray-100 text-gray-600')) ?>">
                            <?= $subLabels[$sub] ?? $sub ?>
                          </span>
                        </td>
                        <td class="px-5 py-3 text-sm text-center"><?= (int) $c['emp_count'] ?></td>
                        <td class="px-5 py-3">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border <?= $badge['class'] ?>">
                                <?= e($badge['label']) ?>
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                <button type="button"
                                    onclick="document.getElementById('sub-<?= (int)$c['id'] ?>').showModal()"
                                    class="text-sm text-industrial-blue hover:underline font-medium">
                                    Assinatura
                                </button>
                                <form method="POST" class="inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <button type="submit" class="text-sm text-gray-600 hover:text-industrial-blue hover:underline">
                                        <?= $c['status'] === 'active' ? 'Desativar' : 'Ativar' ?>
                                    </button>
                                </form>
                            </div>

                            <!-- Modal de assinatura -->
                            <dialog id="sub-<?= (int)$c['id'] ?>" class="rounded-xl p-0 w-full max-w-md backdrop:bg-black/40">
                                <form method="POST" class="text-left">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="set_subscription">
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <div class="flex items-center justify-between border-b p-4">
                                        <h3 class="font-bold text-gray-900">Assinatura — <?= e($c['name']) ?></h3>
                                        <button type="button" onclick="document.getElementById('sub-<?= (int)$c['id'] ?>').close()" class="text-gray-400 hover:text-gray-700 text-lg leading-none">&times;</button>
                                    </div>
                                    <div class="p-4 space-y-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Plano</label>
                                            <select name="plan" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                                                <?php foreach (getPlansCatalog() as $pk => $pdef): ?>
                                                    <option value="<?= e($pk) ?>" <?= ($c['plan'] ?? 'starter') === $pk ? 'selected' : '' ?>>
                                                        <?= e($pdef['name']) ?> — R$ <?= number_format($pdef['price'], 0, ',', '.') ?>/mês
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Status da assinatura</label>
                                            <select name="subscription_status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                                                <?php
                                                $subOptions = ['trialing'=>'Trial','active'=>'Ativo','pending_checkout'=>'Checkout pendente','overdue'=>'Inadimplente','canceled'=>'Cancelado'];
                                                $curSub = $c['subscription_status'] ?? 'trialing';
                                                foreach ($subOptions as $sv => $slabel): ?>
                                                    <option value="<?= $sv ?>" <?= $curSub === $sv ? 'selected' : '' ?>><?= $slabel ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="text-xs text-gray-400 mt-1">"Ativo" libera o acesso. "Cancelado/Inadimplente" inicia o bloqueio conforme a régua.</p>
                                        </div>
                                        <label class="flex items-start gap-2 text-sm text-gray-700 bg-amber-50 border border-amber-200 rounded-md p-3">
                                            <input type="checkbox" name="renew_trial" value="1" class="mt-0.5">
                                            <span><strong>Renovar trial (+30 dias)</strong><br>
                                            <span class="text-xs text-gray-500">Zera a contagem e coloca em Trial por mais 30 dias a partir de hoje. Sobrepõe o status acima.</span></span>
                                        </label>
                                    </div>
                                    <div class="flex justify-end gap-2 border-t p-4">
                                        <button type="button" onclick="document.getElementById('sub-<?= (int)$c['id'] ?>').close()" class="px-4 py-2 text-sm border border-gray-200 rounded-md text-gray-600 hover:bg-gray-50">Cancelar</button>
                                        <button type="submit" class="px-4 py-2 text-sm bg-industrial-blue text-white rounded-md font-medium hover:bg-blue-800">Salvar</button>
                                    </div>
                                </form>
                            </dialog>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function maskCnpj(v){
    v=v.replace(/\D/g,'').slice(0,14);
    if(v.length>12) return v.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/,'$1.$2.$3/$4-$5');
    if(v.length>8)  return v.replace(/(\d{2})(\d{3})(\d{3})(\d+)/,'$1.$2.$3/$4');
    if(v.length>5)  return v.replace(/(\d{2})(\d{3})(\d+)/,'$1.$2.$3');
    if(v.length>2)  return v.replace(/(\d{2})(\d+)/,'$1.$2');
    return v;
}
function maskPhone(v){
    v=v.replace(/\D/g,'').slice(0,11);
    if(v.length===11) return v.replace(/(\d{2})(\d{5})(\d{4})/,'($1) $2-$3');
    if(v.length>=10)  return v.replace(/(\d{2})(\d{4})(\d{4})/,'($1) $2-$3');
    if(v.length>6)    return v.replace(/(\d{2})(\d{4})(\d+)/,'($1) $2-$3');
    if(v.length>2)    return v.replace(/(\d{2})(\d+)/,'($1) $2');
    return v;
}
function applyMask(name,fn){
    document.querySelectorAll('input[name="'+name+'"]').forEach(el=>{
        el.addEventListener('input',function(){ this.value=fn(this.value); });
    });
}
applyMask('cnpj',  maskCnpj);
applyMask('phone', maskPhone);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
