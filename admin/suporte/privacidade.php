<?php
require_once __DIR__ . '/../../config/config.php';
$pageTitle = 'Politica de Privacidade';
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
        <h1 class="text-3xl font-bold text-gray-900 mt-6 mb-4">Politica de Privacidade</h1>
        <p class="text-sm text-gray-500 mb-8">Ultima atualizacao: maio de 2026</p>

        <div class="prose prose-sm max-w-none space-y-4 text-gray-700 leading-relaxed">
            <p>Esta politica descreve como o <?= e(SITE_NAME) ?> trata dados pessoais de usuarios e colaboradores cadastrados pelas empresas clientes, em conformidade com a LGPD (Lei 13.709/2018).</p>
            <h2 class="text-lg font-semibold text-gray-900">Dados coletados</h2>
            <ul class="list-disc pl-5 space-y-1">
                <li>Dados de cadastro da empresa e do administrador (nome, e-mail, CNPJ, endereco)</li>
                <li>Dados de colaboradores (nome, CPF, e-mail, cargo, certificados, fotos opcionais)</li>
                <li>Registros de acesso e auditoria para seguranca</li>
            </ul>
            <h2 class="text-lg font-semibold text-gray-900">Finalidade</h2>
            <p>Prestacao do servico de carteira digital, validacao por QR Code, alertas de vencimento e gestao multi-empresa.</p>
            <h2 class="text-lg font-semibold text-gray-900">Compartilhamento</h2>
            <p>Dados de validacao publica de certificados sao exibidos apenas via link/QR autorizado. Processadores de pagamento (Asaas) recebem dados necessarios a cobranca.</p>
            <h2 class="text-lg font-semibold text-gray-900">Seus direitos</h2>
            <p>Solicitacoes de acesso, correcao ou exclusao podem ser enviadas ao administrador da sua empresa ou para <a href="mailto:qrcertify@gmail.com" class="text-industrial-blue underline">qrcertify@gmail.com</a> / <a href="tel:+5524999327549" class="text-industrial-blue underline">(24) 99932-7549</a>.</p>
        </div>
    </main>
</body>
</html>
