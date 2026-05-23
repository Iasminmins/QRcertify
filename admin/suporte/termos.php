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
        <a href="<?= BASE_URL ?>/" class="text-sm text-industrial-blue hover:underline">← Voltar ao site</a>
        <h1 class="text-3xl font-bold text-gray-900 mt-6 mb-4">Termos de Uso</h1>
        <p class="text-sm text-gray-500 mb-8">Última atualização: maio de 2026</p>

        <div class="prose prose-sm max-w-none space-y-4 text-gray-700 leading-relaxed">
            <p>O <?= e(SITE_NAME) ?> é uma plataforma SaaS para gestão de certificados de Normas Regulamentadoras (NRs) e treinamentos obrigatórios.</p>
            <h2 class="text-lg font-semibold text-gray-900">1. Cadastro e conta</h2>
            <p>O administrador da empresa é responsável pelos dados cadastrados e pelo uso da conta pelos colaboradores autorizados.</p>
            <h2 class="text-lg font-semibold text-gray-900">2. Assinatura e pagamento</h2>
            <p>Planos pagos são cobrados conforme o ciclo contratado. O período de teste gratuito segue as condições exibidas no checkout.</p>
            <h2 class="text-lg font-semibold text-gray-900">3. Uso aceitável</h2>
            <p>É proibido usar o sistema para dados falsos, violação de leis trabalhistas ou tentativa de acesso não autorizado a dados de outras empresas.</p>
            <h2 class="text-lg font-semibold text-gray-900">4. Responsabilidade</h2>
            <p>A empresa contratante mantém a obrigação legal de manter certificados válidos. O sistema auxilia no controle, mas não substitui auditoria ou consultoria jurídica.</p>
            <h2 class="text-lg font-semibold text-gray-900">5. Contato</h2>
            <p>Dúvidas: <a href="mailto:contato@qrcertify.com.br" class="text-industrial-blue underline">contato@qrcertify.com.br</a></p>
        </div>
    </main>
</body>
</html>
