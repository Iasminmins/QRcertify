<?php
/**
 * Catálogo de planos e helpers (limites + features).
 */

define('MIN_PASSWORD_LENGTH', 8);

/**
 * @return array<string, array{name: string, price: int, employees: int, features: array<string, bool>}>
 */
function getPlansCatalog(): array {
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }

    $catalog = [
        'starter' => [
            'name'      => 'Starter',
            'price'     => 147,
            'employees' => 20,
            'features'  => [
                'qr'              => true,
                'dashboard_basic' => true,
                'email_alerts'    => false,
                'csv_import'      => false,
                'pdf_upload'      => false,
                'export_reports'  => false,
            ],
        ],
        'pro' => [
            'name'      => 'Pro',
            'price'     => 397,
            'employees' => 100,
            'features'  => [
                'qr'              => true,
                'dashboard_basic' => true,
                'email_alerts'    => true,
                'csv_import'      => true,
                'pdf_upload'      => true,
                'export_reports'  => true,
            ],
        ],
        'business' => [
            'name'      => 'Business',
            'price'     => 997,
            'employees' => 300,
            'features'  => [
                'qr'              => true,
                'dashboard_basic' => true,
                'email_alerts'    => true,
                'csv_import'      => true,
                'pdf_upload'      => true,
                'export_reports'  => true,
            ],
        ],
        'enterprise' => [
            'name'        => 'Enterprise',
            'price'       => 1997,
            'setup_fee'   => 2000,
            'employees'   => 800,
            'features'    => [
                'qr'              => true,
                'dashboard_basic' => true,
                'email_alerts'    => true,
                'csv_import'      => true,
                'pdf_upload'      => true,
                'export_reports'  => true,
            ],
        ],
        'custom' => [
            'name'      => 'Personalizado',
            'price'     => 0,   // definido em contrato
            'employees' => 0,   // 0 = ilimitado (ou usa plan_employee_limit da empresa)
            'features'  => [
                'qr'              => true,
                'dashboard_basic' => true,
                'email_alerts'    => true,
                'csv_import'      => true,
                'pdf_upload'      => true,
                'export_reports'  => true,
            ],
        ],
    ];

    return $catalog;
}

function normalizePlanKey(?string $plan): string {
    $plan = strtolower(trim((string) $plan));
    $catalog = getPlansCatalog();
    return isset($catalog[$plan]) ? $plan : 'starter';
}

function getPlanDefinition(?string $plan): array {
    $key = normalizePlanKey($plan);
    return getPlansCatalog()[$key];
}

function getPlanEmployeeLimit(?string $plan): int {
    return getPlanDefinition($plan)['employees'];
}

/**
 * Retorna o limite real de colaboradores da empresa.
 * Se o plano for 'custom' ou tiver plan_employee_limit no banco, usa esse valor.
 * 0 = ilimitado.
 */
function getCompanyEmployeeLimit(int $companyId): int {
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT plan, plan_employee_limit FROM companies WHERE id = ? LIMIT 1');
        $stmt->execute([$companyId]);
        $row  = $stmt->fetch();
        if (!$row) return 20;

        $plan = normalizePlanKey($row['plan']);

        // Plano custom ou limite personalizado definido na empresa
        if (!empty($row['plan_employee_limit']) && (int)$row['plan_employee_limit'] > 0) {
            return (int)$row['plan_employee_limit'];
        }

        $catalogLimit = getPlanEmployeeLimit($plan);
        // 0 no catálogo = ilimitado
        return $catalogLimit;
    } catch (\Exception $e) {
        return 20;
    }
}

function planHasFeature(?string $plan, string $feature): bool {
    $def = getPlanDefinition($plan);
    return !empty($def['features'][$feature]);
}

function getCompanyPlanKey(int $companyId): string {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT plan FROM companies WHERE id = ? LIMIT 1');
        $stmt->execute([$companyId]);
        return normalizePlanKey($stmt->fetchColumn() ?: 'starter');
    } catch (\Exception $e) {
        return 'starter';
    }
}

function companyHasFeature(int $companyId, string $feature): bool {
    return planHasFeature(getCompanyPlanKey($companyId), $feature);
}

/**
 * @return array{ok: bool, plan: string, limit: int, current: int, message: string}
 */
function checkEmployeeCapacity(int $companyId, int $additional = 1): array {
    $plan    = getCompanyPlanKey($companyId);
    $limit   = getCompanyEmployeeLimit($companyId);
    $current = 0;

    try {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM employees WHERE company_id = ? AND status = "active"'
        );
        $stmt->execute([$companyId]);
        $current = (int) $stmt->fetchColumn();
    } catch (\Exception $e) {
        return [
            'ok'      => false,
            'plan'    => $plan,
            'limit'   => $limit,
            'current' => 0,
            'message' => 'Não foi possível verificar o limite de funcionários.',
        ];
    }

    // 0 = ilimitado (plano custom sem teto definido)
    $ok = ($limit === 0) || ($current + $additional) <= $limit;

    return [
        'ok'      => $ok,
        'plan'    => $plan,
        'limit'   => $limit,
        'current' => $current,
        'message' => $ok
            ? ''
            : "Limite atingido ({$current}/{$limit} colaboradores). Entre em contato para ampliar seu plano.",
    ];
}

function plansRequiringEmailAlerts(): array {
    $keys = [];
    foreach (getPlansCatalog() as $key => $def) {
        if (!empty($def['features']['email_alerts'])) {
            $keys[] = $key;
        }
    }
    return $keys;
}


/**
 * Régua de carência (grace period) da assinatura.
 *
 * A partir de overdue_since (data em que a cobrança venceu), calcula a fase:
 *   'ok'       -> não está vencida
 *   'grace'    -> vencida, dentro da carência (aviso normal)
 *   'critical' -> vencida, últimos dias da carência (aviso urgente)
 *   'blocked'  -> carência estourou (acesso suspenso; dados nunca apagados)
 *
 * @return array{phase: string, days_left: int, days_overdue: int}
 */
function GRACE_TOTAL_DAYS(): int { return 10; } // dias de tolerância após vencer
function GRACE_CRITICAL_DAYS(): int { return 3; } // últimos dias com aviso urgente

function subscriptionGraceState(?string $overdueSince): array {
    if (empty($overdueSince)) {
        return ['phase' => 'ok', 'days_left' => 0, 'days_overdue' => 0];
    }
    try {
        $start = new DateTimeImmutable($overdueSince);
    } catch (\Exception $e) {
        return ['phase' => 'ok', 'days_left' => 0, 'days_overdue' => 0];
    }
    $today   = new DateTimeImmutable('today');
    $overdue = (int) $start->diff($today)->days;
    $total   = GRACE_TOTAL_DAYS();
    $crit    = GRACE_CRITICAL_DAYS();
    $toBlock = $total - $overdue;

    if ($overdue > $total) {
        return ['phase' => 'blocked', 'days_left' => 0, 'days_overdue' => $overdue];
    }
    if ($toBlock <= $crit) {
        return ['phase' => 'critical', 'days_left' => max(0, $toBlock), 'days_overdue' => $overdue];
    }
    return ['phase' => 'grace', 'days_left' => max(0, $toBlock), 'days_overdue' => $overdue];
}


/**
 * Estado de aviso de assinatura para exibir na interface (faixa no topo).
 * Considera tanto o fim do trial quanto o vencimento (overdue), aplicando
 * a mesma régua de carência. Retorna null quando não há nada a avisar.
 *
 * @return array{phase: string, days_left: int, kind: string}|null
 *   kind: 'trial' | 'overdue'
 */
function subscriptionNoticeForCompany(int $companyId): ?array {
    try {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT subscription_status, overdue_since, created_at FROM companies WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$companyId]);
        $row = $stmt->fetch();
    } catch (\Exception $e) {
        return null;
    }
    if (!$row) return null;

    $status = $row['subscription_status'] ?? null;

    // Caso 1: vencido (cliente pagante atrasou)
    if ($status === 'overdue' && !empty($row['overdue_since'])) {
        $g = subscriptionGraceState($row['overdue_since']);
        if (in_array($g['phase'], ['grace', 'critical'], true)) {
            return ['phase' => $g['phase'], 'days_left' => $g['days_left'], 'kind' => 'overdue'];
        }
    }

    // Caso 2: trial terminou e está na carência
    if ($status === 'trialing' && !empty($row['created_at'])) {
        try {
            $trialEnds = (new DateTimeImmutable($row['created_at']))->modify('+30 days');
            if (new DateTimeImmutable('today') > $trialEnds) {
                $g = subscriptionGraceState($trialEnds->format('Y-m-d'));
                if (in_array($g['phase'], ['grace', 'critical'], true)) {
                    return ['phase' => $g['phase'], 'days_left' => $g['days_left'], 'kind' => 'trial'];
                }
            }
        } catch (\Exception $e) { /* ignora */ }
    }

    return null;
}
