<?php
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/price_lib.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Non authentifie']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function resolve_existing_path(string $relative): string {
    $relative = trim($relative);
    if ($relative === '') return '';

    $root = realpath(__DIR__ . '/..');
    if (!$root) return $relative;

    $full = $root . '/' . ltrim(str_replace('\\', '/', $relative), '/');
    if (file_exists($full)) return $relative;

    $dir = dirname($full);
    $base = pathinfo($full, PATHINFO_FILENAME);
    if (!is_dir($dir)) return $relative;

    foreach (scandir($dir) as $file) {
        if ($file === '.' || $file === '..') continue;
        if (strcasecmp(pathinfo($file, PATHINFO_FILENAME), $base) === 0) {
            $relDir = dirname($relative);
            return rtrim(str_replace('\\', '/', $relDir), '/') . '/' . $file;
        }
    }

    return $relative;
}

function flava_canonical_unit_code(string $code): string {
    $value = strtoupper(trim($code));
    if ($value === '') return '';

    if (preg_match('/^A-RDC-LC0?(\d{1,2})$/', $value, $m)) return 'LC ' . (int)$m[1];
    if ($value === 'A-MEZZ-M01') return 'M01';
    if (preg_match('/^([AB])-([123])-(\d{1,2})$/', $value, $m)) return $m[1] . $m[2] . '-' . (int)$m[3];
    if (preg_match('/^D-1-(\d{1,2})$/', $value, $m)) return 'D1-' . (int)$m[1];
    if (preg_match('/^B-(?:RDC|MEZZ)-D0?(\d{1,2})$/', $value, $m)) return 'D0-' . (int)$m[1];

    return trim($code);
}

function flava_plan_fallback(string $code): string {
    $canonical = strtoupper(trim(flava_canonical_unit_code($code)));

    if (preg_match('/^D0-(\d{1,2})$/', $canonical, $m))
        return 'uploads/plans/units/flava/D0-' . (int)$m[1] . '.jpg';
    if (preg_match('/^D1-(\d{1,2})$/', $canonical, $m))
        return 'uploads/plans/units/flava/D1-' . (int)$m[1] . '.jpg';
    if (preg_match('/^LC (\d{1,2})$/', $canonical, $m))
        return 'uploads/plans/units/flava/Boutique ' . str_pad((int)$m[1], 2, '0', STR_PAD_LEFT) . '.jpg';
    if ($canonical === 'M01')
        return 'uploads/plans/units/flava/M01.jpg';
    if (preg_match('/^[AB][123]-\d{1,2}$/', $canonical))
        return 'uploads/plans/units/flava/' . $canonical . '.jpg';

    return '';
}

function fmt_status(?string $status): string {
    $value = strtoupper(trim((string)$status));
    if ($value === 'R') return 'Reserve';
    if ($value === 'V') return 'Vendu';
    return 'Disponible';
}

function code_from_label(string $status): string {
    $value = strtolower(trim($status));
    if ($value === 'reserve' || $value === 'reservee' || $value === 'reserver' || $value === 'réservé') return 'R';
    if ($value === 'vendu') return 'V';
    return 'D';
}

$requestedCode = trim((string)($_GET['code'] ?? ''));
$kind = strtoupper(trim((string)($_GET['kind'] ?? 'UNIT')));
if ($requestedCode === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Code manquant']);
    exit;
}

$queryCode = $kind === 'ACCESSORY' ? $requestedCode : flava_canonical_unit_code($requestedCode);

try {
    if ($kind === 'ACCESSORY') {
        $sql = "SELECT a.id, a.code, a.accessory_type AS type, a.block_name, a.floor_name, a.surface,
                       a.price_ht_sale, a.price_ttc_sale, a.price_m2_ht_sale, a.price_m2_ttc_sale, a.status_code,
                       a.operation_mode, a.plan_image
                FROM unit_accessoires a
                JOIN projects p ON p.id = a.project_id
                WHERE p.code = 'FLAVA' AND a.code = ?
                LIMIT 1";
    } else {
        $sql = "SELECT u.id, u.ref_code AS code, u.type, u.designation, u.orientation, u.block_name, u.floor_name, u.surface,
                       u.price_ht_sale, u.price_ttc_sale, u.price_m2_ht_sale, u.price_m2_ttc_sale, u.status_code,
                       u.operation_mode, u.plan_image
                FROM units u
                JOIN projects p ON p.id = u.project_id
                WHERE p.code = 'FLAVA' AND u.ref_code = ?
                LIMIT 1";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$queryCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Introuvable']);
        exit;
    }

    // Prix du mois, comme la Liste des prix
    $pr = project_row_price(project_month_prices($pdo, 'FLAVA'), $row + ['accessory_type' => $row['type'] ?? ''], $kind === 'ACCESSORY');
    $row['price_ht_sale']     = $pr['price_ht'];
    $row['price_ttc_sale']    = $pr['price_ttc'];
    $row['price_m2_ht_sale']  = $pr['price_m2_ht'];
    $row['price_m2_ttc_sale'] = $pr['price_m2_ttc'];

    $plan = resolve_existing_path((string)($row['plan_image'] ?? ''));
    if ($plan === '' && $kind !== 'ACCESSORY') {
        $plan = resolve_existing_path(flava_plan_fallback((string)($row['code'] ?? $queryCode)));
    }

    $status = fmt_status($row['status_code'] ?? 'D');

    echo json_encode([
        'id' => (int)$row['id'],
        'kind' => $kind,
        'code' => $requestedCode,
        'db_code' => $row['code'],
        'type' => $row['type'] ?? '',
        'designation' => $row['designation'] ?? '',
        'orientation' => $row['orientation'] ?? '',
        'block' => $row['block_name'] ?? '',
        'floor' => $row['floor_name'] ?? '',
        'surface' => isset($row['surface']) ? (float)$row['surface'] : 0,
        'price_ht' => isset($row['price_ht_sale']) ? (float)$row['price_ht_sale'] : 0,
        'price_ttc' => isset($row['price_ttc_sale']) ? (float)$row['price_ttc_sale'] : 0,
        'price_m2_ht' => isset($row['price_m2_ht_sale']) ? (float)$row['price_m2_ht_sale'] : 0,
        'price_m2_ttc' => isset($row['price_m2_ttc_sale']) ? (float)$row['price_m2_ttc_sale'] : 0,
        'status' => $status,
        'status_code' => code_from_label($status),
        'plan_image' => $plan,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur', 'message' => $e->getMessage()]);
}
