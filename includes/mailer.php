<?php
/**
 * Mailer simples usando mail() nativo do PHP.
 * Compatível com Hostinger sem dependências externas.
 *
 * Uso:
 *   sendMail('dest@email.com', 'Assunto', '<p>HTML</p>');
 */

function sendMail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
    $from     = defined('MAIL_FROM')      ? MAIL_FROM      : 'noreply@localhost';
    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Sistema';
    $siteName = defined('SITE_NAME')      ? SITE_NAME      : 'Sistema';
    $baseUrl  = defined('BASE_URL')       ? BASE_URL       : '';

    // Fallback texto puro
    if ($textBody === '') {
        $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
    }

    // Envelope HTML completo
    $html = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
      body{margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif}
      .wrap{max-width:600px;margin:32px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)}
      .head{background:#1e3a8a;padding:24px 32px}
      .head h1{color:#fff;margin:0;font-size:18px;font-weight:700}
      .head p{color:#93c5fd;margin:4px 0 0;font-size:13px}
      .body{padding:28px 32px;color:#374151;font-size:15px;line-height:1.6}
      .body h2{font-size:17px;color:#1e3a8a;margin:0 0 12px}
      .btn{display:inline-block;background:#1e3a8a;color:#fff!important;padding:10px 22px;border-radius:6px;text-decoration:none;font-size:14px;font-weight:600;margin-top:16px}
      .cert-row{border:1px solid #e5e7eb;border-radius:6px;padding:12px 16px;margin-bottom:10px}
      .cert-row .tipo{font-size:12px;font-weight:700;color:#1e3a8a}
      .cert-row .titulo{font-size:14px;color:#111827;margin:2px 0 4px}
      .cert-row .val{font-size:12px;color:#6b7280}
      .cert-row.expiring .val{color:#d97706;font-weight:600}
      .cert-row.expired  .val{color:#dc2626;font-weight:600}
      .foot{background:#f9fafb;padding:16px 32px;font-size:12px;color:#9ca3af;border-top:1px solid #e5e7eb}
    </style></head><body>
    <div class="wrap">
      <div class="head">
        <h1>' . htmlspecialchars($siteName) . '</h1>
        <p>Notificação automática de certificados</p>
      </div>
      <div class="body">' . $htmlBody . '</div>
      <div class="foot">
        Este e-mail foi enviado automaticamente por ' . htmlspecialchars($siteName) . '.
        Acesse o sistema em <a href="' . $baseUrl . '" style="color:#6b7280">' . $baseUrl . '</a>
      </div>
    </div></body></html>';

    $boundary = '==BOUNDARY_' . md5(uniqid('', true)) . '==';

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($textBody)) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($html)) . "\r\n";
    $body .= "--{$boundary}--";

    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    return @mail($to, $encSubject, $body, $headers);
}

/**
 * E-mail para o COLABORADOR — avisa para ir ao RH renovar
 */
function buildCertAlertHtmlEmployee(string $employeeName, array $certs, string $companyName = ''): string {
    $baseUrl   = defined('BASE_URL') ? BASE_URL : '';
    $siteName  = defined('SITE_NAME') ? SITE_NAME : 'Sistema';
    $hasExpired  = count(array_filter($certs, fn($c) => $c['status'] === 'expired'))  > 0;
    $hasExpiring = count(array_filter($certs, fn($c) => $c['status'] === 'expiring')) > 0;

    $urgencia = $hasExpired
        ? '<div style="background:#fef2f2;border-left:4px solid #dc2626;border-radius:4px;padding:12px 16px;margin-bottom:16px;color:#991b1b;font-size:14px;">
               <strong>⚠️ Ação urgente necessária!</strong> Você possui certificado(s) <strong>expirado(s)</strong>.
               Regularize o quanto antes para evitar restrições ao trabalho.
           </div>'
        : '<div style="background:#fffbeb;border-left:4px solid #d97706;border-radius:4px;padding:12px 16px;margin-bottom:16px;color:#92400e;font-size:14px;">
               <strong>📅 Atenção ao prazo!</strong> Você possui certificado(s) com vencimento próximo.
           </div>';

    $rows = '';
    foreach ($certs as $c) {
        $isExp  = $c['status'] === 'expired';
        $color  = $isExp ? '#dc2626' : '#d97706';
        $label  = $isExp ? '🔴 EXPIRADO' : '🟡 VENCENDO';
        $bg     = $isExp ? '#fef2f2' : '#fffbeb';
        $border = $isExp ? '#fecaca' : '#fde68a';
        $rows  .= '<div style="background:' . $bg . ';border:1px solid ' . $border . ';border-radius:8px;padding:14px 16px;margin-bottom:10px;">'
                . '<div style="font-size:11px;font-weight:700;color:' . $color . ';text-transform:uppercase;letter-spacing:.05em;">' . $label . '</div>'
                . '<div style="font-size:15px;font-weight:600;color:#111827;margin:4px 0 2px;">' . htmlspecialchars($c['title']) . '</div>'
                . '<div style="font-size:12px;color:#6b7280;">Tipo: ' . htmlspecialchars($c['type']) . '</div>'
                . '<div style="font-size:13px;color:' . $color . ';font-weight:600;margin-top:4px;">Vencimento: ' . date('d/m/Y', strtotime($c['expiry_date'])) . '</div>'
                . '</div>';
    }

    $passos = '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:16px;margin:20px 0;">'
            . '<p style="margin:0 0 10px;font-weight:600;color:#0369a1;font-size:14px;">📋 O que você precisa fazer:</p>'
            . '<ol style="margin:0;padding-left:20px;color:#374151;font-size:14px;line-height:2;">'
            . '<li>Dirija-se ao <strong>setor de RH</strong> ou fale com seu <strong>gestor</strong></li>'
            . '<li>Informe sobre este aviso de vencimento</li>'
            . '<li>Agende o treinamento ou exame necessário para a renovação</li>'
            . '<li>Após concluir, o RH atualizará seu registro no sistema</li>'
            . '</ol>'
            . '</div>';

    return '<h2 style="font-size:18px;color:#1e3a8a;margin:0 0 6px;">Olá, ' . htmlspecialchars($employeeName) . '!</h2>'
         . '<p style="color:#6b7280;font-size:14px;margin:0 0 20px;">' . ($companyName ? htmlspecialchars($companyName) . ' — ' : '') . 'Sistema de Gestão de Certificados</p>'
         . $urgencia
         . '<p style="font-size:15px;color:#374151;margin-bottom:14px;">Os seguintes certificados requerem sua atenção:</p>'
         . $rows
         . $passos
         . '<p style="font-size:13px;color:#6b7280;margin-top:16px;">Em caso de dúvidas, entre em contato diretamente com o RH da empresa.</p>'
         . '<a style="display:inline-block;background:#1e3a8a;color:#fff;padding:11px 24px;border-radius:6px;text-decoration:none;font-size:14px;font-weight:600;margin-top:8px;" href="' . $baseUrl . '/employee/certificates.php">Ver meus certificados</a>';
}

/**
 * E-mail para o GESTOR — resumo dos colaboradores com pendências
 */
function buildCertAlertHtmlManager(string $managerName, array $employees, string $companyName = ''): string {
    $baseUrl  = defined('BASE_URL') ? BASE_URL : '';
    $total    = count($employees);
    $expired  = 0;
    $expiring = 0;
    foreach ($employees as $emp) {
        foreach ($emp['certs'] as $c) {
            if ($c['status'] === 'expired')  $expired++;
            else                             $expiring++;
        }
    }

    $resumo = '<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">'
            . '<div style="flex:1;min-width:120px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px;text-align:center;">'
            . '<div style="font-size:28px;font-weight:800;color:#dc2626;">' . $expired . '</div>'
            . '<div style="font-size:12px;color:#991b1b;font-weight:600;">Expirado(s)</div>'
            . '</div>'
            . '<div style="flex:1;min-width:120px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px;text-align:center;">'
            . '<div style="font-size:28px;font-weight:800;color:#d97706;">' . $expiring . '</div>'
            . '<div style="font-size:12px;color:#92400e;font-weight:600;">A vencer</div>'
            . '</div>'
            . '<div style="flex:1;min-width:120px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px;text-align:center;">'
            . '<div style="font-size:28px;font-weight:800;color:#1e3a8a;">' . $total . '</div>'
            . '<div style="font-size:12px;color:#1e40af;font-weight:600;">Colaborador(es)</div>'
            . '</div>'
            . '</div>';

    $rows = '';
    foreach ($employees as $emp) {
        $hasExp = count(array_filter($emp['certs'], fn($c) => $c['status'] === 'expired')) > 0;
        $border = $hasExp ? '#fecaca' : '#fde68a';
        $bg     = $hasExp ? '#fef2f2' : '#fffbeb';
        $dot    = $hasExp ? '🔴' : '🟡';

        $certs = '';
        foreach ($emp['certs'] as $c) {
            $cLabel = $c['status'] === 'expired' ? 'Expirado' : 'Vencendo';
            $cColor = $c['status'] === 'expired' ? '#dc2626' : '#d97706';
            $certs .= '<span style="display:inline-block;font-size:11px;background:#fff;border:1px solid ' . $cColor . ';color:' . $cColor . ';border-radius:20px;padding:2px 10px;margin:2px;">'
                    . htmlspecialchars($c['type']) . ' — ' . $cLabel . ' · ' . date('d/m/Y', strtotime($c['expiry_date']))
                    . '</span>';
        }

        $rows .= '<div style="background:' . $bg . ';border:1px solid ' . $border . ';border-radius:8px;padding:14px 16px;margin-bottom:10px;">'
               . '<div style="font-weight:600;color:#111827;font-size:15px;">' . $dot . ' ' . htmlspecialchars($emp['name']) . '</div>'
               . '<div style="font-size:12px;color:#6b7280;margin-bottom:8px;">' . htmlspecialchars($emp['email']) . (!empty($emp['dept']) ? ' · ' . htmlspecialchars($emp['dept']) : '') . '</div>'
               . '<div>' . $certs . '</div>'
               . '</div>';
    }

    return '<h2 style="font-size:18px;color:#1e3a8a;margin:0 0 6px;">Olá, ' . htmlspecialchars($managerName) . '!</h2>'
         . '<p style="color:#6b7280;font-size:14px;margin:0 0 20px;">' . ($companyName ? htmlspecialchars($companyName) . ' — ' : '') . 'Relatório de Certificados da sua Equipe</p>'
         . '<p style="font-size:15px;color:#374151;margin-bottom:16px;">Os colaboradores abaixo da sua área possuem certificados vencidos ou próximos do vencimento:</p>'
         . $resumo
         . $rows
         . '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;margin-top:16px;">'
         . '<p style="margin:0;font-size:14px;color:#166534;"><strong>📋 Próximo passo:</strong> Oriente os colaboradores listados a procurarem o RH para regularizar a situação. Certificados expirados podem gerar restrições operacionais e riscos de autuação.</p>'
         . '</div>'
         . '<a style="display:inline-block;background:#1e3a8a;color:#fff;padding:11px 24px;border-radius:6px;text-decoration:none;font-size:14px;font-weight:600;margin-top:16px;" href="' . $baseUrl . '/admin/notify-expiring.php">Ver no sistema</a>';
}
