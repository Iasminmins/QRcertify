<?php

require_once __DIR__ . '/seo_components.php';

function seoPages(): array {
    return [
        'sistema-controle-aso' => [
            'title' => 'Sistema para controle de ASO | QRCertify',
            'description' => 'Controle ASOs admissionais, periodicos e demissionais com alertas de vencimento, historico por trabalhador e validacao por QR Code.',
            'eyebrow' => 'Controle de ASO',
            'h1' => 'Sistema para controle de ASO sem planilha perdida',
            'lead' => 'Centralize ASOs admissionais, periodicos, de retorno ao trabalho, mudanca de risco e demissionais em um painel simples, com alertas antes do vencimento e acesso rapido por trabalhador.',
            'primary' => 'Organizar ASOs agora',
            'problem' => 'Quando o ASO fica em pasta, e-mail ou planilha, a empresa so percebe o risco perto de uma auditoria, mobilizacao ou renovacao urgente.',
            'outcomes' => [
                'Alertas de ASO vencendo antes do prazo critico.',
                'Historico por colaborador com status de aptidao.',
                'Importacao em massa para sair da planilha sem retrabalho.',
            ],
            'features' => [
                ['ASO por tipo', 'Registre admissional, periodico, retorno ao trabalho, mudanca de risco e demissional.'],
                ['Vencimentos visiveis', 'Filtre documentos validos, vencendo e expirados antes de virar pendencia.'],
                ['Consulta rapida', 'Encontre o trabalhador e veja a situacao ocupacional em poucos segundos.'],
            ],
            'faq' => [
                ['O QRCertify substitui a planilha de ASO?', 'Sim. Ele organiza os dados, documentos e vencimentos em um fluxo proprio para acompanhamento de ASO.'],
                ['Consigo usar com certificados de NR tambem?', 'Sim. ASOs e certificados ficam no mesmo ambiente, com alertas e carteira digital por trabalhador.'],
            ],
        ],
        'gestao-certificados-nr' => [
            'title' => 'Gestao de certificados NR | QRCertify',
            'description' => 'Gerencie certificados de NR com vencimentos, QR Code, carteira digital e alertas para equipes industriais, obras e consultorias SST.',
            'eyebrow' => 'Certificados de NR',
            'h1' => 'Gestao de certificados NR com vencimentos sob controle',
            'lead' => 'Cadastre certificados, acompanhe validade, identifique pendencias e entregue uma carteira digital com QR Code para trabalhadores, equipes e clientes.',
            'primary' => 'Controlar certificados',
            'problem' => 'Certificado vencido costuma aparecer na pior hora: portaria, auditoria, fiscalizacao, mobilizacao de equipe ou liberacao de servico.',
            'outcomes' => [
                'Painel de certificados validos, vencendo e vencidos.',
                'QR Code para validacao publica do certificado.',
                'Organizacao por trabalhador, empresa, setor e tipo de treinamento.',
            ],
            'features' => [
                ['NRs em um painel', 'Acompanhe treinamentos como NR-10, NR-11, NR-12, NR-33 e NR-35.'],
                ['Validacao por QR Code', 'Compartilhe uma consulta publica para comprovar a autenticidade do registro.'],
                ['Alertas preventivos', 'Veja o que precisa renovar antes de bloquear uma operacao.'],
            ],
            'faq' => [
                ['Serve para consultoria SST?', 'Sim. Consultorias podem organizar documentos dos clientes e criar recorrencia com alertas de renovacao.'],
                ['Preciso cadastrar tudo manualmente?', 'Voce pode cadastrar individualmente ou usar importacao em massa conforme o volume.'],
            ],
        ],
        'carteira-digital-trabalhador' => [
            'title' => 'Carteira digital do trabalhador com QR Code | QRCertify',
            'description' => 'Crie uma carteira digital do trabalhador com ASO, certificados de NR, status e validacao por QR Code para consulta rapida.',
            'eyebrow' => 'Carteira digital',
            'h1' => 'Carteira digital do trabalhador com QR Code verificavel',
            'lead' => 'Reuna ASO, certificados e autorizacoes em uma carteira digital simples de consultar, pronta para portarias, auditorias e equipes de campo.',
            'primary' => 'Criar carteira digital',
            'problem' => 'Equipes em campo precisam provar rapidamente que estao aptas e treinadas, sem depender de pasta fisica ou envio manual de arquivo.',
            'outcomes' => [
                'Consulta publica por QR Code com dados essenciais.',
                'Status claro de certificados, ASO e autorizacoes.',
                'Menos retrabalho para RH, seguranca do trabalho e operacao.',
            ],
            'features' => [
                ['QR individual', 'Cada trabalhador pode ter uma consulta propria para validacao dos documentos.'],
                ['Pronto para campo', 'Funciona bem para obras, industria, manutencao, facilities e prestadores.'],
                ['Dados centralizados', 'A empresa controla o que aparece e mantem o historico atualizado.'],
            ],
            'faq' => [
                ['A carteira substitui documento obrigatorio?', 'Ela facilita consulta e controle, mas a empresa deve manter os documentos conforme suas obrigacoes legais.'],
                ['Da para mostrar so dados necessarios?', 'Sim. A proposta e exibir o essencial para validacao, evitando circulacao desnecessaria de arquivos.'],
            ],
        ],
        'sistema-sst-empresas' => [
            'title' => 'Sistema SST para empresas | QRCertify',
            'description' => 'Sistema SST para empresas controlarem ASOs, certificados, vencimentos e documentos dos trabalhadores com alertas e QR Code.',
            'eyebrow' => 'SST para empresas',
            'h1' => 'Sistema SST para empresas que precisam sair da planilha',
            'lead' => 'Organize documentos de seguranca do trabalho, acompanhe vencimentos e facilite auditorias com um painel feito para empresas com equipes proprias e terceirizadas.',
            'primary' => 'Ver solucao para empresas',
            'problem' => 'Quanto mais colaboradores, terceiros e setores, maior o risco de perder prazo, duplicar arquivo ou liberar alguem sem documento valido.',
            'outcomes' => [
                'Visao da conformidade por colaborador e documento.',
                'Controle de ASO, certificados e autorizacoes no mesmo fluxo.',
                'Acesso rapido para gestores, RH e seguranca do trabalho.',
            ],
            'features' => [
                ['Operacao organizada', 'Tenha uma rotina clara para cadastro, renovacao e acompanhamento.'],
                ['Menos urgencia', 'Alertas ajudam a agir antes do vencimento virar problema.'],
                ['Base para auditoria', 'Historico e validacao reduzem improviso em fiscalizacoes e contratos.'],
            ],
            'faq' => [
                ['E indicado para empresa pequena?', 'Sim. O ganho aparece quando ha documentos recorrentes e risco de vencimento, mesmo em equipes menores.'],
                ['Funciona para terceirizados?', 'Sim. O controle pode ser usado para trabalhadores proprios, prestadores e equipes mobilizadas.'],
            ],
        ],
        'gestao-conformidade-sst' => [
            'title' => 'Gestao de conformidade SST com ASO, certificados e documentos | QRCertify',
            'description' => 'Veja como organizar a gestao de conformidade SST com controle de ASOs, certificados, documentos, vencimentos, colaboradores e relatorios em uma unica plataforma.',
            'eyebrow' => 'Gestao de conformidade SST',
            'h1' => 'Gestao de conformidade SST sem depender de planilhas espalhadas',
            'lead' => 'A QRCertify ajuda empresas, RH, SESMT e consultorias SST a controlar ASOs, certificados, documentos, treinamentos, colaboradores, areas, setores e vencimentos em um fluxo unico, com QR Code, alertas e relatorios.',
            'primary' => 'Comecar gratis agora',
            'problem' => 'Conformidade nao falha apenas por falta de documento. Ela falha quando ASO, certificado, treinamento, area, setor, colaborador e vencimento ficam em lugares diferentes e ninguem enxerga o risco antes da auditoria.',
            'outcomes' => [
                'Visao unica de documentos validos, vencendo e vencidos.',
                'Rastreabilidade para entender historico, alteracoes e pendencias.',
                'Rotina mais previsivel para RH, SESMT, diretoria e consultorias.',
            ],
            'features' => [
                ['Controle por colaborador', 'Centralize ASOs, certificados, treinamentos e documentos no cadastro do trabalhador.'],
                ['Vencimentos priorizados', 'Veja o que exige acao agora, o que vence em breve e o que ainda esta regular.'],
                ['Relatorio de conformidade', 'Acompanhe indicadores para auditoria, gestao interna e tomada de decisao.'],
            ],
            'longform' => [
                'title' => 'O que uma boa gestao de conformidade SST precisa controlar?',
                'intro' => 'Uma operacao minimamente organizada precisa responder rapido: quem esta apto, qual documento vence primeiro, onde esta o comprovante, qual setor esta mais exposto e o que precisa ser renovado antes de virar urgencia.',
                'items' => [
                    ['ASOs e exames ocupacionais', 'Controle admissionais, periodicos, retorno ao trabalho, mudanca de risco e demissionais com validade por colaborador.'],
                    ['Certificados e treinamentos', 'Organize NRs, treinamentos internos, certificados externos e autorizacoes necessarias para executar atividades.'],
                    ['Documentos e evidencias', 'Mantenha arquivos importantes em um lugar consultavel, reduzindo busca em e-mails, pastas e computadores.'],
                    ['Areas, setores e responsaveis', 'Enxergue a conformidade por estrutura da empresa, nao apenas por lista solta de nomes.'],
                    ['Auditoria e historico', 'Registre alteracoes relevantes para ter confianca nos dados e menos improviso em fiscalizacoes.'],
                    ['Relatorios e exportacoes', 'Transforme a rotina documental em informacao para gestao, contratos e reunioes de acompanhamento.'],
                ],
            ],
            'comparison' => [
                ['Planilha manual', 'Depende de alguem lembrar, atualizar e conferir celula por celula.'],
                ['Pastas e e-mails', 'Arquivos existem, mas ficam dificeis de encontrar no momento de pressao.'],
                ['QRCertify', 'Centraliza documentos, status, alertas, QR Code e relatorios em um fluxo unico.'],
            ],
            'checklist' => [
                'Mapear colaboradores, areas e setores.',
                'Cadastrar ASOs, certificados, treinamentos e documentos recorrentes.',
                'Definir rotina de renovacao antes do vencimento.',
                'Acompanhar pendencias por status e prioridade.',
                'Gerar relatorios para auditoria, diretoria e clientes.',
            ],
            'faq' => [
                ['Essa pagina e diferente da landing principal?', 'Sim. Ela e uma pagina SEO educativa para quem pesquisa gestao de conformidade SST, enquanto a landing principal vende o produto de forma mais direta.'],
                ['Posso comecar sem falar com vendas?', 'Sim. O fluxo principal e comecar gratis pelo checkout e testar a plataforma antes de escalar o uso.'],
                ['Serve para empresa e consultoria SST?', 'Sim. Empresas usam para controle interno; consultorias podem organizar a gestao documental de clientes e reduzir retrabalho.'],
            ],
        ],
        'controle-documentos-vencimento' => [
            'title' => 'Controle de documentos com vencimento | QRCertify',
            'description' => 'Controle documentos com vencimento, receba alertas e acompanhe ASOs e certificados antes que expirem.',
            'eyebrow' => 'Vencimentos',
            'h1' => 'Controle de documentos com vencimento antes que virem risco',
            'lead' => 'Veja o que esta valido, vencendo ou vencido, priorize renovacoes e reduza dependencia de lembretes manuais em planilhas.',
            'primary' => 'Controlar vencimentos',
            'problem' => 'Documento vencido raramente e falta de cuidado; quase sempre e falta de um sistema que avisa no momento certo.',
            'outcomes' => [
                'Lista de documentos vencendo em breve.',
                'Priorizacao de renovacoes por prazo e status.',
                'Menos risco operacional em auditorias e mobilizacoes.',
            ],
            'features' => [
                ['Alertas inteligentes', 'Acompanhe vencimentos com antecedencia e reduza correria.'],
                ['Status simples', 'Validos, vencendo e vencidos ficam separados para acao rapida.'],
                ['Uso recorrente', 'Ideal para ASO, NR, treinamentos, autorizacoes e documentos internos.'],
            ],
            'faq' => [
                ['O sistema avisa antes de vencer?', 'Sim. A proposta do QRCertify e destacar pendencias antes do prazo critico.'],
                ['Serve para mais de um tipo de documento?', 'Sim. Ele foi pensado para ASOs, certificados e controles ocupacionais relacionados.'],
            ],
        ],
    ];
}

function renderSeoPage(string $slug): void {
    $pages = seoPages();
    if (!isset($pages[$slug])) {
        http_response_code(404);
        renderLandingPage();
        return;
    }

    $page = $pages[$slug];
    $base = rtrim(BASE_URL, '/');
    $url = $base . '/' . $slug . '/';
    $logo = $base . '/public/assets/logo-qrcertify.svg';
    $checkout = $base . '/checkout.php';
    $whatsapp = 'https://wa.me/5524999327549?text=' . rawurlencode('Ola, quero conhecer o QRCertify.');
    $plans = getPlansCatalog();
    $pricing = seoPlansPricing();

    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<?php renderGoogleTagManagerHead(); ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($page['title']) ?></title>
<meta name="description" content="<?= e($page['description']) ?>">
<link rel="canonical" href="<?= e($url) ?>">
<meta property="og:title" content="<?= e($page['title']) ?>">
<meta property="og:description" content="<?= e($page['description']) ?>">
<meta property="og:url" content="<?= e($url) ?>">
<meta property="og:site_name" content="QRCertify">
<meta property="og:type" content="website">
<meta property="og:image" content="<?= e($logo) ?>">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= e($page['title']) ?>">
<meta name="twitter:description" content="<?= e($page['description']) ?>">
<meta name="twitter:image" content="<?= e($logo) ?>">
<link rel="icon" type="image/svg+xml" href="<?= e($logo) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = { theme: { extend: { colors: { 'industrial-blue': '#1e3a8a', 'industrial-yellow': '#facc15' }, fontFamily: { display: ['Bricolage Grotesque','sans-serif'], body: ['IBM Plex Sans','sans-serif'] } } } }
</script>
<style>
*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body { font-family: 'IBM Plex Sans', sans-serif; color: #0a0a0a; background: #fff; }
h1,h2,h3,h4 { font-family: 'Bricolage Grotesque', sans-serif; letter-spacing: 0; }
a { text-decoration: none; }
.grid-bg { background-image: linear-gradient(rgba(30,58,138,.04) 1px, transparent 1px), linear-gradient(90deg, rgba(30,58,138,.04) 1px, transparent 1px); background-size: 40px 40px; }
.qrc-wordmark { font-family: Arial, Helvetica, sans-serif; font-weight: 700; letter-spacing: 0; line-height: 1; }
.nav-link { font-size:.9rem; font-weight:500; color:#374151; transition:color .15s; }
.nav-link:hover { color:#1e3a8a; }
.metric { border-left: 3px solid #facc15; }
</style>
<?php renderSeoJsonLd($page, $base, $url, $logo); ?>
</head>
<body>
<?php renderGoogleTagManagerBody(); ?>
<header class="sticky top-0 z-50 bg-white/95 backdrop-blur border-b border-gray-100">
  <div class="max-w-6xl mx-auto px-5 h-16 flex items-center justify-between">
    <a href="<?= e($base) ?>/" class="flex items-center gap-2.5">
      <img src="<?= e($logo) ?>" alt="QRCertify" class="w-10 h-10 flex-shrink-0">
      <span class="qrc-wordmark text-gray-900 text-base">QRCertify</span>
    </a>
    <nav class="hidden md:flex items-center gap-8">
      <a href="<?= e($base) ?>/#recursos" class="nav-link">Recursos</a>
      <a href="<?= e($base) ?>/#precos" class="nav-link">Pre&ccedil;os</a>
      <a href="<?= e($base) ?>/#faq" class="nav-link">FAQ</a>
      <a href="<?= e($base) ?>/#contato" class="nav-link">Contato</a>
    </nav>
    <div class="hidden md:flex items-center gap-4">
      <a href="<?= e($base) ?>/login.php" class="nav-link">Entrar</a>
      <a href="<?= e($checkout) ?>" class="bg-industrial-blue text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-blue-900 transition-colors">Come&ccedil;ar gr&aacute;tis</a>
    </div>
  </div>
</header>

<main>
  <section class="grid-bg py-20 md:py-28 overflow-hidden">
    <div class="max-w-6xl mx-auto px-5">
      <div class="max-w-3xl">
        <?php renderSeoBreadcrumb($base, $page['eyebrow']); ?>
        <p class="inline-flex items-center gap-2 bg-yellow-50 border border-yellow-200 text-yellow-800 text-xs font-semibold px-3 py-1.5 rounded-full mb-6"><?= e($page['eyebrow']) ?></p>
        <h1 class="font-display text-4xl md:text-6xl font-extrabold leading-[1.05] tracking-tight mb-6"><?= e($page['h1']) ?></h1>
        <p class="text-gray-600 text-lg md:text-xl leading-relaxed mb-8"><?= e($page['lead']) ?></p>
        <div class="flex flex-col sm:flex-row gap-3">
          <a href="<?= e($checkout) ?>" class="bg-industrial-blue text-white font-semibold px-6 py-3.5 rounded-xl hover:bg-blue-900 transition-colors text-center text-sm"><?= e($page['primary']) ?></a>
          <a href="<?= e($whatsapp) ?>" target="_blank" rel="noopener" class="text-gray-700 font-medium px-6 py-3.5 rounded-xl border border-gray-200 hover:bg-gray-50 transition-colors text-center text-sm">Falar com vendas</a>
        </div>
      </div>
    </div>
  </section>

  <section class="py-20">
    <div class="max-w-6xl mx-auto px-5 grid lg:grid-cols-[.9fr_1.1fr] gap-12 items-start">
      <div>
        <p class="text-xs font-semibold uppercase tracking-widest text-industrial-blue mb-3">Por que importa</p>
        <h2 class="font-display text-3xl md:text-4xl font-extrabold tracking-tight mb-5">Menos correria, mais previsibilidade operacional.</h2>
        <p class="text-gray-600 leading-relaxed"><?= e($page['problem']) ?></p>
      </div>
      <div class="grid sm:grid-cols-3 gap-4">
        <?php foreach ($page['outcomes'] as $outcome): ?>
          <div class="metric bg-gray-50 p-5 rounded-lg">
            <p class="text-sm leading-relaxed text-gray-700"><?= e($outcome) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="py-20 bg-gray-50">
    <div class="max-w-6xl mx-auto px-5">
      <div class="max-w-2xl mb-10">
        <p class="text-xs font-semibold uppercase tracking-widest text-industrial-blue mb-3">Recursos</p>
        <h2 class="font-display text-3xl md:text-4xl font-extrabold tracking-tight">O controle fica simples para quem opera todos os dias.</h2>
      </div>
      <div class="grid md:grid-cols-3 gap-5">
        <?php foreach ($page['features'] as $feature): ?>
          <article class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <h3 class="font-display text-xl font-bold mb-3"><?= e($feature[0]) ?></h3>
            <p class="text-sm text-gray-600 leading-relaxed"><?= e($feature[1]) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <?php if (!empty($page['longform'])): ?>
  <section class="py-20">
    <div class="max-w-6xl mx-auto px-5 grid lg:grid-cols-[.85fr_1.15fr] gap-12 items-start">
      <div>
        <p class="text-xs font-semibold uppercase tracking-widest text-industrial-blue mb-3">Guia pratico</p>
        <h2 class="font-display text-3xl md:text-4xl font-extrabold tracking-tight mb-5"><?= e($page['longform']['title']) ?></h2>
        <p class="text-gray-600 leading-relaxed"><?= e($page['longform']['intro']) ?></p>
      </div>
      <div class="grid sm:grid-cols-2 gap-4">
        <?php foreach ($page['longform']['items'] as $item): ?>
          <article class="bg-white border border-gray-200 rounded-lg p-5 shadow-sm">
            <h3 class="font-display text-lg font-bold mb-2"><?= e($item[0]) ?></h3>
            <p class="text-sm text-gray-600 leading-relaxed"><?= e($item[1]) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($page['comparison']) || !empty($page['checklist'])): ?>
  <section class="py-20 bg-gray-50">
    <div class="max-w-6xl mx-auto px-5 grid lg:grid-cols-2 gap-8">
      <?php if (!empty($page['comparison'])): ?>
      <div class="bg-white border border-gray-200 rounded-lg p-7 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-widest text-industrial-blue mb-3">Comparativo</p>
        <h2 class="font-display text-3xl font-extrabold tracking-tight mb-6">Por que sair do controle manual?</h2>
        <div class="space-y-4">
          <?php foreach ($page['comparison'] as $row): ?>
            <div class="border-t border-gray-100 pt-4">
              <h3 class="font-display text-lg font-bold mb-1"><?= e($row[0]) ?></h3>
              <p class="text-sm text-gray-600 leading-relaxed"><?= e($row[1]) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if (!empty($page['checklist'])): ?>
      <div class="bg-industrial-blue text-white rounded-lg p-7 shadow-xl">
        <p class="text-xs font-semibold uppercase tracking-widest text-industrial-yellow mb-3">Checklist</p>
        <h2 class="font-display text-3xl font-extrabold tracking-tight mb-6">Primeiros passos para organizar a conformidade</h2>
        <ul class="space-y-4">
          <?php foreach ($page['checklist'] as $item): ?>
            <li class="flex gap-3 text-blue-50"><span class="text-industrial-yellow font-bold">&#10003;</span><span><?= e($item) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <a href="<?= e($checkout) ?>" class="inline-block mt-8 bg-industrial-yellow text-gray-900 font-bold px-6 py-3 rounded-xl hover:bg-yellow-300 transition-colors">Come&ccedil;ar gr&aacute;tis</a>
      </div>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="py-20">
    <div class="max-w-6xl mx-auto px-5">
      <div class="max-w-2xl mb-10">
        <p class="text-xs font-semibold uppercase tracking-widest text-industrial-blue mb-3">Planos</p>
        <h2 class="font-display text-3xl md:text-4xl font-extrabold tracking-tight">Comece pequeno e aumente conforme a opera&ccedil;&atilde;o cresce.</h2>
        <p class="text-gray-500 mt-4">Todos os planos com 30 dias gr&aacute;tis. Cancele quando quiser.</p>
      </div>
      <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-5">
        <?php foreach (['starter', 'pro', 'business', 'enterprise'] as $planKey): ?>
          <?php
            $plan = $plans[$planKey];
            $isFeatured = $planKey === 'pro';
            $priceLabel = $planKey === 'enterprise'
                ? 'A partir de R$ ' . number_format($plan['price'], 0, ',', '.')
                : 'R$ ' . number_format($plan['price'], 0, ',', '.');
            $href = $planKey === 'enterprise'
                ? 'https://wa.me/5524999327549?text=' . rawurlencode('Ola, quero falar sobre o QRCertify Enterprise.')
                : $checkout . '?plan=' . $planKey;
          ?>
          <article class="<?= $isFeatured ? 'bg-industrial-blue text-white border-industrial-blue shadow-xl' : 'bg-white border-gray-200' ?> border rounded-lg p-6">
            <?php if ($isFeatured): ?>
              <p class="inline-block bg-industrial-yellow text-gray-900 text-xs font-bold px-2.5 py-1 rounded-full mb-4">Mais escolhido</p>
            <?php else: ?>
              <div class="h-8 mb-4"></div>
            <?php endif; ?>
            <h3 class="font-display text-xl font-bold mb-2"><?= e($plan['name']) ?></h3>
            <p class="<?= $isFeatured ? 'text-blue-100' : 'text-gray-500' ?> text-sm leading-relaxed min-h-[56px]"><?= e($pricing[$planKey]['summary']) ?></p>
            <div class="mt-6 mb-5">
              <span class="font-display text-3xl font-extrabold"><?= e($priceLabel) ?></span>
              <span class="<?= $isFeatured ? 'text-blue-200' : 'text-gray-400' ?> text-sm">/m&ecirc;s</span>
            </div>
            <ul class="space-y-2.5 mb-6 text-sm">
              <?php foreach ($pricing[$planKey]['items'] as $item): ?>
                <li class="flex gap-2"><span class="<?= $isFeatured ? 'text-industrial-yellow' : 'text-industrial-blue' ?> font-bold">✓</span><span><?= e($item) ?></span></li>
              <?php endforeach; ?>
            </ul>
            <a href="<?= e($href) ?>" <?= $planKey === 'enterprise' ? 'target="_blank" rel="noopener"' : '' ?> class="block text-center <?= $isFeatured ? 'bg-industrial-yellow text-gray-900 hover:bg-yellow-300' : 'bg-industrial-blue text-white hover:bg-blue-900' ?> font-bold py-3 rounded-xl transition-colors text-sm">
              <?= $planKey === 'enterprise' ? 'Falar com vendas' : 'Come&ccedil;ar 30 dias gr&aacute;tis' ?>
            </a>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="py-20">
    <div class="max-w-6xl mx-auto px-5 grid lg:grid-cols-2 gap-12">
      <div>
        <p class="text-xs font-semibold uppercase tracking-widest text-industrial-blue mb-3">Perguntas comuns</p>
        <h2 class="font-display text-3xl md:text-4xl font-extrabold tracking-tight">D&uacute;vidas antes de organizar os documentos.</h2>
      </div>
      <div class="space-y-4">
        <?php foreach ($page['faq'] as $item): ?>
          <div class="border border-gray-200 rounded-lg p-5">
            <h3 class="font-display text-lg font-bold mb-2"><?= e($item[0]) ?></h3>
            <p class="text-sm text-gray-600 leading-relaxed"><?= e($item[1]) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="bg-industrial-blue py-20">
    <div class="max-w-4xl mx-auto px-5 text-center">
      <h2 class="font-display text-4xl md:text-5xl font-extrabold text-white tracking-tight mb-6">Pronto para controlar vencimentos com menos improviso?</h2>
      <p class="text-blue-200 text-lg mb-8">Teste o QRCertify e veja como ASOs, certificados e carteiras digitais ficam em um unico fluxo.</p>
      <a href="<?= e($checkout) ?>" class="inline-block bg-industrial-yellow text-gray-900 font-bold px-8 py-4 rounded-xl hover:bg-yellow-300 transition-colors">Come&ccedil;ar gr&aacute;tis</a>
    </div>
  </section>
</main>

<footer class="bg-gray-950 text-gray-400 py-14">
  <div class="max-w-6xl mx-auto px-5 grid md:grid-cols-[1.2fr_1fr_1fr] gap-10">
    <div>
      <a href="<?= e($base) ?>/" class="flex items-center gap-2.5 mb-4"><img src="<?= e($logo) ?>" alt="QRCertify" class="w-9 h-9 flex-shrink-0"><span class="qrc-wordmark text-white text-sm">QRCertify</span></a>
      <p class="text-sm leading-relaxed">Gest&atilde;o digital de certificados de NR, ASOs e carteiras digitais com QR Code.</p>
    </div>
    <div>
      <h4 class="text-white text-sm mb-4 font-bold">Solu&ccedil;&otilde;es</h4>
      <ul class="space-y-2.5 text-sm">
        <?php renderSeoFooterSolutions($pages, $base, $slug); ?>
      </ul>
    </div>
    <div>
      <h4 class="text-white text-sm mb-4 font-bold">Contato</h4>
      <ul class="space-y-2.5 text-sm">
        <li><a href="mailto:qrcertify@gmail.com" class="hover:text-white transition-colors">qrcertify@gmail.com</a></li>
        <li><a href="tel:+5524999327549" class="hover:text-white transition-colors">(24) 99932-7549</a></li>
        <li><a href="<?= e($whatsapp) ?>" target="_blank" rel="noopener" class="hover:text-white transition-colors">Falar pelo WhatsApp</a></li>
      </ul>
    </div>
  </div>
</footer>
</body>
</html>
<?php
}
