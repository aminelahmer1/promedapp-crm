<?php
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/price_lib.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Non authentifié']);
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
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        if (strcasecmp(pathinfo($f, PATHINFO_FILENAME), $base) === 0) {
            $relDir = dirname($relative);
            return rtrim(str_replace('\\', '/', $relDir), '/') . '/' . $f;
        }
    }
    return $relative;
}

function fmt_status(?string $s): string {
    $s = strtoupper(trim((string)$s));
    return match($s) {
        'R' => 'Réservé',
        'V' => 'Vendu',
        default => 'Disponible',
    };
}

function code_from_label(string $s): string {
    $s = trim(mb_strtolower($s));
    return match($s) {
        'réservé', 'reserve' => 'R',
        'vendu' => 'V',
        default => 'D',
    };
}

$code = trim((string)($_GET['code'] ?? ''));
$kind = strtoupper(trim((string)($_GET['kind'] ?? 'UNIT')));
if ($code === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Code manquant']);
    exit;
}

try {
    if ($kind === 'ACCESSORY') {
        $sql = "SELECT a.id, a.code, a.accessory_type AS type, a.block_name AS block_name, a.floor_name, a.surface,
                       a.price_ht_sale, a.price_ttc_sale, a.price_m2_ht_sale, a.price_m2_ttc_sale, a.status_code,
                       a.operation_mode, a.plan_image, p.code AS project_code
                FROM unit_accessoires a
                LEFT JOIN projects p ON p.id = a.project_id
                WHERE p.code = 'AVA' AND a.code = ?
                LIMIT 1";
    } else {
        $sql = "SELECT u.id, u.ref_code AS code, u.type, u.designation, u.orientation, u.block_name, u.floor_name, u.surface,
                       u.price_ht_sale, u.price_ttc_sale, u.price_m2_ht_sale, u.price_m2_ttc_sale, u.status_code,
                       u.operation_mode, u.plan_image, p.code AS project_code
                FROM units u
                LEFT JOIN projects p ON p.id = u.project_id
                WHERE p.code = 'AVA' AND u.ref_code = ?
                LIMIT 1";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Introuvable']);
        exit;
    }

    // Prix du mois, comme la Liste des prix (vendu = prix enregistré)
    $pr = project_row_price(project_month_prices($pdo, 'AVA'), $row + ['accessory_type' => $row['type'] ?? ''], $kind === 'ACCESSORY');
    $row['price_ht_sale']     = $pr['price_ht'];
    $row['price_ttc_sale']    = $pr['price_ttc'];
    $row['price_m2_ht_sale']  = $pr['price_m2_ht'];
    $row['price_m2_ttc_sale'] = $pr['price_m2_ttc'];

    $plan = resolve_existing_path((string)($row['plan_image'] ?? ''));
    echo json_encode([
        'id' => (int)$row['id'],
        'kind' => $kind,
        'code' => $row['code'],
        'type' => $row['type'] ?? '',
        'designation' => $row['designation'] ?? '',
        'orientation' => $row['orientation'] ?? '',
        'block' => $row['block_name'] ?? 'AVA',
        'floor' => $row['floor_name'] ?? '',
        'surface' => isset($row['surface']) ? (float)$row['surface'] : 0,
        'price_ht' => isset($row['price_ht_sale']) ? (float)$row['price_ht_sale'] : 0,
        'price_ttc' => isset($row['price_ttc_sale']) ? (float)$row['price_ttc_sale'] : 0,
        'price_m2_ht' => isset($row['price_m2_ht_sale']) ? (float)$row['price_m2_ht_sale'] : 0,
        'price_m2_ttc' => isset($row['price_m2_ttc_sale']) ? (float)$row['price_m2_ttc_sale'] : 0,
        'status' => fmt_status($row['status_code'] ?? 'D'),
        'status_code' => code_from_label(fmt_status($row['status_code'] ?? 'D')),
        'plan_image' => $plan,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur', 'message' => $e->getMessage()]);
}
