<?php
// api/status_save.php
require __DIR__ . '/../config.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Payload JSON invalide']);
    exit;
}

$valid_status = ['Disponible', 'Réservé', 'Vendu', 'Bloqué'];

$sql = "INSERT INTO unit_statuses (unit_code, status, updated_by)
        VALUES (:code, :status, :uid)
        ON DUPLICATE KEY UPDATE
          status = VALUES(status),
          updated_by = VALUES(updated_by)";
$stmt = $pdo->prepare($sql);

foreach ($data as $code => $status) {
    if (!in_array($status, $valid_status, true)) {
        continue;
    }
    $stmt->execute([
        ':code' => $code,
        ':status' => $status,
        ':uid' => $_SESSION['user_id']
    ]);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true]);
