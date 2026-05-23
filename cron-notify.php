<?php
/**
 * CRON — Notificações automáticas de vencimento de certificados
 *
 * Configurar no cPanel/Hostinger:
 *   php /home/user/public_html/cron-notify.php >> /home/user/logs/cron.log 2>&1
 * Frequência: diária (00:05)
 *
 * Ou via URL protegida (adicione CRON_SECRET no config.php):
 *   curl -s "https://qrcertify.com.br/cron-notify.php?secret=SEU_SECRET"
 */

define('RUNNING_AS_CRON', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/mailer.php';

// ── Proteção: só roda via CLI ou com secret correto ──────────
$isCli    = (php_sapi_name() === 'cli');
$secret   = defined('CRON_SECRET') ? CRON_SECRET : null;
$provided = $_GET['secret'] ?? '';

if (!$isCli && (!$secret || $provided !== $secret)) {
    http_response_code(403);
    exit('Forbidden');
}

$db   = getDB();
$now  = date('Y-m-d H:i:s');
$log  = [];

$log[] = "=== Cron iniciado: {$now} ===";

// ── Atualiza status de todos os certificados ─────────────────
$db->query(
    'UPDATE certificates c
     JOIN employees e ON e.id = c.employee_id
     SET c.status = CASE
        WHEN c.expiry_date < CURDATE() THEN "expired"
        WHEN c.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "expiring"
        ELSE "valid"
     END'
);
$log[] = "Status de certificados atualizado.";

// ── Busca certificados vencendo nos próximos 30, 15 ou 7 dias ─
$alertDays = [30, 15, 7];
$sent = 0;
$skipped = 0;

$plansWithAlerts = plansRequiringEmailAlerts();
$planInSql       = implode(',', array_fill(0, count($plansWithAlerts), '?'));

foreach ($alertDays as $days) {
    $targetDate = date('Y-m-d', strtotime("+{$days} days"));

    $stmt = $db->prepare(
        "SELECT c.id, c.title, c.type, c.expiry_date, c.status,
                e.id AS emp_id, e.name AS emp_name, e.email AS emp_email,
                co.id AS company_id, co.name AS company_name
         FROM certificates c
         JOIN employees e  ON e.id  = c.employee_id
         JOIN companies co ON co.id = e.company_id
         WHERE e.status    = 'active'
           AND co.status   = 'active'
           AND co.subscription_status != 'canceled'
           AND co.plan IN ({$planInSql})
           AND c.expiry_date = ?
           AND e.email IS NOT NULL AND e.email != ''"
    );
    $stmt->execute([...$plansWithAlerts, $targetDate]);
    $certs = $stmt->fetchAll();

    foreach ($certs as $cert) {
        // Evita reenvio: checa se já notificou hoje
        $chk = $db->prepare(
            'SELECT id FROM audit_log
             WHERE action = "notify.auto"
               AND target = ?
               AND detail LIKE ?
               AND created_at >= CURDATE()'
        );
        $chk->execute(["cert#{$cert['id']}", "%days_{$days}%"]);
        if ($chk->fetch()) { $skipped++; continue; }

        $html = buildCertAlertHtml($cert['emp_name'], [[
            'title'       => $cert['title'],
            'type'        => $cert['type'],
            'expiry_date' => $cert['expiry_date'],
            'status'      => $cert['status'],
        ]]);

        $subject = "[QRCertify] Certificado vence em {$days} dias — {$cert['type']}";
        $ok = sendMail($cert['emp_email'], $subject, $html);

        if ($ok) {
            $sent++;
            // Registra no audit_log
            $db->prepare(
                'INSERT INTO audit_log (company_id, action, target, detail, ip)
                 VALUES (?, "notify.auto", ?, ?, "cron")'
            )->execute([
                $cert['company_id'],
                "cert#{$cert['id']}",
                json_encode(['days_' . $days => true, 'email' => $cert['emp_email']]),
            ]);
            $log[] = "  ✓ [{$days}d] {$cert['emp_name']} <{$cert['emp_email']}> — {$cert['type']}";
        } else {
            $log[] = "  ✗ FALHA [{$days}d] {$cert['emp_name']} <{$cert['emp_email']}>";
        }
    }
}

// ── Notifica admins sobre certificados VENCIDOS hoje ─────────
$stmtExp = $db->prepare(
    'SELECT co.id AS company_id, u.email AS admin_email, u.name AS admin_name,
            COUNT(c.id) AS total_expired
     FROM certificates c
     JOIN employees e   ON e.id   = c.employee_id
     JOIN companies co  ON co.id  = e.company_id
     JOIN users u       ON u.company_id = co.id AND u.role = "admin" AND u.status = "active"
     WHERE c.expiry_date = CURDATE()
       AND e.status  = "active"
       AND co.status = "active"
     GROUP BY co.id, u.id'
);
$stmtExp->execute();
$expiredToday = $stmtExp->fetchAll();

foreach ($expiredToday as $row) {
    $html = '<h2>Atenção: certificados vencidos hoje</h2>'
          . '<p>Olá, <strong>' . htmlspecialchars($row['admin_name']) . '</strong>!</p>'
          . '<p><strong>' . $row['total_expired'] . ' certificado(s)</strong> venceram hoje na sua empresa.</p>'
          . '<p>Acesse o sistema para renovar e manter a conformidade.</p>'
          . '<a href="' . BASE_URL . '/admin/certificates.php" style="background:#1e3a8a;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;margin-top:16px">Ver certificados</a>';

    $ok = sendMail($row['admin_email'], '[QRCertify] Certificados vencidos hoje — ação necessária', $html);
    if ($ok) {
        $sent++;
        $log[] = "  ✓ [expirado] Admin {$row['admin_email']} — {$row['total_expired']} cert(s)";
    }
}

$log[] = "=== Concluído: {$sent} enviados, {$skipped} já notificados ===";
echo implode("\n", $log) . "\n";
