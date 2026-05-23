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
 * Monta o corpo HTML para alerta de certificado vencendo/vencido.
 */
function buildCertAlertHtml(string $employeeName, array $certs): string {
    $baseUrl = defined('BASE_URL') ? BASE_URL : '';
    $rows = '';
    foreach ($certs as $c) {
        $cls   = $c['status'] === 'expired' ? 'expired' : 'expiring';
        $label = $c['status'] === 'expired' ? 'EXPIRADO' : 'EXPIRANDO';
        $rows .= '<div class="cert-row ' . $cls . '">'
               . '<div class="tipo">' . htmlspecialchars($c['type']) . ' — ' . $label . '</div>'
               . '<div class="titulo">' . htmlspecialchars($c['title']) . '</div>'
               . '<div class="val">Validade: ' . date('d/m/Y', strtotime($c['expiry_date'])) . '</div>'
               . '</div>';
    }
    return '<h2>Olá, ' . htmlspecialchars($employeeName) . '!</h2>'
         . '<p>Os seguintes certificados precisam de atenção:</p>'
         . $rows
         . '<p>Entre em contato com o RH para providenciar a renovação.</p>'
         . '<a class="btn" href="' . $baseUrl . '/employee/certificates.php">Ver meus certificados</a>';
}
