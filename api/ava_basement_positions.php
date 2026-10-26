<?php
require __DIR__ . '/../config.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$sql = "SELECT a.code, app.x, app.y, app.w, app.h
        FROM asset_plan_positions app
        JOIN floor_plans fp ON fp.id = app.floor_plan_id
        JOIN projects p ON p.id = fp.project_id
        JOIN unit_accessoires a ON a.id = app.accessoire_id
        WHERE UPPER(p.code)='AVA' AND fp.floor_name='Sous-sol' AND app.asset_type='ACCESSORY'
        ORDER BY a.code";
try {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['x'] = (float)$r['x'];
        $r['y'] = (float)$r['y'];
        $r['w'] = (float)$r['w'];
        $r['h'] = (float)$r['h'];
    }
    echo json_encode(['positions' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur', 'message' => $e->getMessage()]);
}
