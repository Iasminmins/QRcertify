<?php
require_once __DIR__ . '/../../config/config.php';
$pageTitle = 'Política de Privacidade';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <?php renderGoogleTagManagerHead(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | <?= e(SITE_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/public/assets/logo-qrcertify.svg">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800">
<?php renderGoogleTagManagerBody(); ?>
    <main class="max-w-3xl mx-auto px-5 py-12">
        <a href="<?= BASE_URL ?>/" class="text-sm text-industrial-blue hover:underline">&larr; Voltar ao site</a>
        <h1 class="text-3xl font-bold text-gray-900 mt-6 mb-4">Política de Privacidade</h1>
        <p class="text-sm text-gray-500 mb-8">Última atualização: maio de 2026</p>

        <div class="prose prose-sm max-w-none space-y-4 text-gray-700 leading-relaxed">
            <p>Esta política descreve como o <?= e(SITE_NAME) ?> trata dados pessoais de usuários e colaboradores cadastrados pelas empresas clientes, em conformidade com a LGPD (Lei 13.709/2018).</p>
            <h2 class="text-lg font-semibold text-gray-900">Dados coletados</h2>
            <ul class="list-disc pl-5 space-y-1">
                <li>Dados de cadastro da empresa e do administrador (nome, e-mail, CNPJ, endereço)</li>
                <li>Dados de colaboradores (nome, CPF, e-mail, cargo, certificados, fotos opcionais)</li>
                <li>Registros de acesso e auditoria para segurança</li>
            </ul>
            <h2 class="text-lg font-semibold text-gray-900">Finalidade</h2>
            <p>Prestação do serviço de carteira digital, validação por QR Code, alertas de vencimento e gestão multi-empresa.</p>
            <h2 class="text-lg font-semibold text-gray-900">Compartilhamento</h2>
            <p>Dados de validação pública de certificados são exibidos apenas via link/QR autorizado. Processadores de pagamento (Asaas) recebem dados necessários à cobrança.</p>
            <h2 class="text-lg font-semibold text-gray-900">Seus direitos</h2>
            <p>Solicitações de acesso, correção ou exclusão podem ser enviadas ao administrador da sua empresa ou para <a href="mailto:qrcertify@gmail.com" class="text-industrial-blue underline">qrcertify@gmail.com</a> / <a href="tel:+5524999327549" class="text-industrial-blue underline">(24) 99932-7549</a>.</p>
        </div>
    </main>
</body>
</html>
