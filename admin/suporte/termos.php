<?php
require_once __DIR__ . '/../../config/config.php';
$pageTitle = 'Termos de Uso';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | <?= e(SITE_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800">
    <main class="max-w-3xl mx-auto px-5 py-12">
        <a href="<?= BASE_URL ?>/" class="text-sm text-industrial-blue hover:underline">&larr; Voltar ao site</a>
        <h1 class="text-3xl font-bold text-gray-900 mt-6 mb-4">Termos de Uso</h1>
        <p class="text-sm text-gray-500 mb-8">Ultima atualizacao: maio de 2026</p>

        <div class="prose prose-sm max-w-none space-y-4 text-gray-700 leading-relaxed">
            <p>O <?= e(SITE_NAME) ?> e uma plataforma SaaS para gestao de certificados de Normas Regulamentadoras (NRs) e treinamentos obrigatorios.</p>
            <h2 class="text-lg font-semibold text-gray-900">1. Cadastro e conta</h2>
            <p>O administrador da empresa e responsavel pelos dados cadastrados e pelo uso da conta pelos colaboradores autorizados.</p>
            <h2 class="text-lg font-semibold text-gray-900">2. Assinatura e pagamento</h2>
            <p>Planos pagos sao cobrados conforme o ciclo contratado. O periodo de teste gratuito segue as condicoes exibidas no checkout.</p>
            <h2 class="text-lg font-semibold text-gray-900">3. Uso aceitavel</h2>
            <p>E proibido usar o sistema para dados falsos, violacao de leis trabalhistas ou tentativa de acesso nao autorizado a dados de outras empresas.</p>
            <h2 class="text-lg font-semibold text-gray-900">4. Responsabilidade</h2>
            <p>A empresa contratante mantem a obrigacao legal de manter certificados validos. O sistema auxilia no controle, mas nao substitui auditoria ou consultoria juridica.</p>
            <h2 class="text-lg font-semibold text-gray-900">5. Contato</h2>
            <p>Duvidas: <a href="mailto:qrcertify@gmail.com" class="text-industrial-blue underline">qrcertify@gmail.com</a> ou <a href="tel:+5524999327549" class="text-industrial-blue underline">(24) 99932-7549</a>.</p>
        </div>
    </main>
</body>
</html>
