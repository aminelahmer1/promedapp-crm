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
$project = strtoupper(trim((string)($_GET['project'] ?? '')));
if ($project === '') {
    echo json_encode(['accessories' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$sql = "SELECT a.code, a.accessory_type, a.surface, a.status_code, a.plan_image,
               a.block_name, a.floor_name, a.operation_mode, a.price_m2_ht_sale, a.price_m2_ttc_sale, a.price_ht_sale, a.price_ttc_sale
        FROM unit_accessoires a
        JOIN projects p ON p.id = a.project_id
        WHERE UPPER(p.code) = ? AND a.accessory_type IN ('PARKING','CELLIER')
        ORDER BY a.accessory_type, a.code";
$stmt = $pdo->prepare($sql);
$stmt->execute([$project]);

$monthPrices = project_month_prices($pdo, $project);
$out = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ($monthPrices) {
        // Prix du mois, comme la Liste des prix
        $pr = project_row_price($monthPrices, $r, true);
        $r['price_m2_ht_sale'] = $pr['price_m2_ht'];
        $r['price_ht_sale']    = $pr['price_ht'];
        $r['price_ttc_sale']   = $pr['price_ttc'];
    }
    $status = strtoupper(trim((string)($r['status_code'] ?? 'D')));
    $out[] = [
        'code' => $r['code'],
        'type' => $r['accessory_type'],
        'surface' => (float)($r['surface'] ?? 0),
        'status' => $status === 'V' ? 'Vendu' : ($status === 'R' ? 'Réservé' : 'Disponible'),
        'plan_image' => $r['plan_image'] ?? '',
        'block' => $r['block_name'] ?: 'AVA',
        'floor' => 'SOUS-SOL',
        'price_m2_base' => (float)($r['price_m2_ht_sale'] ?? 0),
        'price_ht' => (float)($r['price_ht_sale'] ?? 0),
        'price_ttc' => (float)($r['price_ttc_sale'] ?? 0)
    ];
}

echo json_encode(['accessories' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
