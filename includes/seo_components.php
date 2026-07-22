<?php

function seoSolutionOrder(): array {
    return [
        'sistema-controle-aso',
        'gestao-certificados-nr',
        'carteira-digital-trabalhador',
        'sistema-sst-empresas',
        'gestao-conformidade-sst',
        'controle-documentos-vencimento',
    ];
}

function seoRelatedSlugs(array $pages, string $currentSlug, int $limit = 4): array {
    $ordered = array_values(array_filter(
        seoSolutionOrder(),
        static fn(string $slug): bool => $slug !== $currentSlug && isset($pages[$slug])
    ));

    return array_slice($ordered, 0, $limit);
}

function seoPlansPricing(): array {
    return [
        'starter' => [
            'summary' => 'Para empresas pequenas que querem sair da planilha.',
            'items' => ['Ate 20 colaboradores', 'Carteira digital com QR Code', 'Dashboard essencial'],
        ],
        'pro' => [
            'summary' => 'Para operacoes que precisam de alertas, importacao e relatorios.',
            'items' => ['Ate 100 colaboradores', 'Alertas de vencimento', 'Importacao CSV e exportacoes'],
        ],
        'business' => [
            'summary' => 'Para empresas com mais setores, equipes e documentos recorrentes.',
            'items' => ['Ate 300 colaboradores', 'Recursos avancados do Pro', 'Mais escala para SST e RH'],
        ],
        'enterprise' => [
            'summary' => 'Para operacoes maiores com implantacao dedicada.',
            'items' => ['Ate 800 colaboradores', 'Implantacao dedicada', 'Suporte para operacao complexa'],
        ],
    ];
}

function renderSeoBreadcrumb(string $base, string $label): void {
    ?>
    <nav class="mb-7 text-sm text-gray-500" aria-label="Breadcrumb">
      <ol class="flex flex-wrap items-center gap-2">
        <li><a href="<?= e($base) ?>/" class="hover:text-industrial-blue">Inicio</a></li>
        <li aria-hidden="true">/</li>
        <li><a href="<?= e($base) ?>/#solucoes" class="hover:text-industrial-blue">Solucoes</a></li>
        <li aria-hidden="true">/</li>
        <li class="text-gray-900 font-semibold" aria-current="page"><?= e($label) ?></li>
      </ol>
    </nav>
    <?php
}

function renderSeoJsonLd(array $page, string $base, string $url, string $logo): void {
    $graph = [
        [
            '@type' => 'Organization',
            '@id' => $base . '/#organization',
            'name' => 'QRCertify',
            'url' => $base . '/',
            'logo' => $logo,
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'telephone' => '+55-24-99932-7549',
                'contactType' => 'sales',
                'areaServed' => 'BR',
                'availableLanguage' => 'Portuguese',
            ],
        ],
        [
            '@type' => 'SoftwareApplication',
            '@id' => $url . '#software',
            'name' => 'QRCertify',
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'url' => $url,
            'description' => $page['description'],
            'publisher' => ['@id' => $base . '/#organization'],
        ],
        [
            '@type' => 'BreadcrumbList',
            '@id' => $url . '#breadcrumb',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Inicio',
                    'item' => $base . '/',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => 'Solucoes',
                    'item' => $base . '/#solucoes',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 3,
                    'name' => $page['eyebrow'],
                    'item' => $url,
                ],
            ],
        ],
    ];

    if (!empty($page['faq'])) {
        $graph[] = [
            '@type' => 'FAQPage',
            '@id' => $url . '#faq',
            'mainEntity' => array_map(
                static fn(array $item): array => [
                    '@type' => 'Question',
                    'name' => $item[0],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item[1],
                    ],
                ],
                $page['faq']
            ),
        ];
    }

    echo '<script type="application/ld+json">' . json_encode([
        '@context' => 'https://schema.org',
        '@graph' => $graph,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
}

function renderSeoFooterSolutions(array $pages, string $base, ?string $activeSlug = null): void {
    foreach (seoSolutionOrder() as $slug) {
        if (!isset($pages[$slug]) || $slug === $activeSlug) {
            continue;
        }
        echo '<li><a href="' . e($base . '/' . $slug . '/') . '" class="hover:text-white transition-colors">' . e($pages[$slug]['eyebrow']) . '</a></li>';
    }
}
