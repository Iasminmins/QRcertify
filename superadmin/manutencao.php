<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/maintenance.php';
requireRole('superadmin');

$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $errors[] = 'Token de segurança inválido. Recarregue a página e tente novamente.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'regenerate_hashes') {
                $updated = regeneratePredictablePublicHashes($db);
                auditLog('maintenance.public_hash', 'employees', json_encode(['updated' => $updated]), 'configuracoes',
                    "Regeneração de hashes públicos previsíveis: {$updated} colaborador(es).");
                setFlash('success', "Hashes públicos atualizados: {$updated}. Carteiras desses colaboradores precisam de um QR Code novo.");
                redirect('/superadmin/manutencao.php');
            } elseif ($action === 'backfill_sha256') {
                $result = backfillFileSha256($db);
                auditLog('maintenance.sha256', 'files', json_encode($result), 'configuracoes',
                    "Cálculo de SHA-256 de arquivos existentes: {$result['updated']} atualizado(s), {$result['missing']} ausente(s).");
                setFlash('success', "SHA-256 calculado para {$result['updated']} arquivo(s). Ausentes ou ilegíveis: {$result['missing']}.");
                redirect('/superadmin/manutencao.php');
            } else {
                $errors[] = 'Ação inválida.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Falha ao executar a rotina. Verifique se as migrações 021 a 023 já foram aplicadas no banco.';
        }
    }
}

$pageTitle = 'Manutenção';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Manutenção</h1>
    <p class="text-gray-600">Rotinas de execução única, para hospedagem sem acesso SSH. Faça backup do banco antes.</p>
</div>

<?php renderFlashes(); ?>
<?php if (!empty($errors)): ?>
    <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="bg-white rounded-lg shadow-sm border p-5">
        <h2 class="font-semibold text-gray-900">Regenerar links previsíveis das carteiras</h2>
        <p class="text-sm text-gray-600 mt-2">Troca os identificadores públicos criados pela migração 005 (calculados a partir do id e da data de cadastro) por identificadores aleatórios. Colaboradores que já têm identificador aleatório não são alterados.</p>
        <p class="text-sm text-amber-700 mt-2">Os QR Codes já impressos desses colaboradores deixam de funcionar e precisam ser gerados de novo.</p>
        <form method="POST" class="mt-4" onsubmit="return confirm('Confirmar a troca dos links? Os QR Codes impressos desses colaboradores deixarão de funcionar.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="regenerate_hashes">
            <button type="submit" class="px-4 py-2 rounded-md bg-industrial-blue text-white text-sm font-medium hover:opacity-90">Executar</button>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow-sm border p-5">
        <h2 class="font-semibold text-gray-900">Calcular SHA-256 dos arquivos existentes</h2>
        <p class="text-sm text-gray-600 mt-2">Gera o código de integridade dos certificados, ASOs e documentos enviados antes da migração 021. Arquivos que já têm código não são alterados, então pode ser executado mais de uma vez.</p>
        <form method="POST" class="mt-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="backfill_sha256">
            <button type="submit" class="px-4 py-2 rounded-md bg-industrial-blue text-white text-sm font-medium hover:opacity-90">Executar</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
