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
