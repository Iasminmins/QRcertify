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
            'price'     => 97,
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
            'price'     => 197,
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
            'price'     => 397,
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
            'price'       => 997,
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
    $limit   = getPlanEmployeeLimit($plan);
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

    $ok = ($current + $additional) <= $limit;

    return [
        'ok'      => $ok,
        'plan'    => $plan,
        'limit'   => $limit,
        'current' => $current,
        'message' => $ok
            ? ''
            : "Limite do plano {$plan} atingido ({$current}/{$limit} funcionários). Faça upgrade para continuar.",
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
