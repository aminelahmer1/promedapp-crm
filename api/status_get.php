<?php
// api/status_get.php
require __DIR__ . '/../config.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$stmt = $pdo->query("SELECT unit_code, status FROM unit_statuses");
$rows = $stmt->fetchAll();

$map = [];
foreach ($rows as $row) {
    $map[$row['unit_code']] = $row['status'];
}

echo json_encode($map);
