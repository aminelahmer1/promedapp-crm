<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$promise_id = (int)($_GET['promise_id'] ?? 0);
$payment_id = (int)($_GET['payment_id'] ?? 0);

if (!$promise_id && !$payment_id) {
    http_response_code(400); echo json_encode(['error' => 'promise_id ou payment_id requis']); exit;
}

$where = $promise_id ? 'promise_id = ?' : 'payment_id = ?';
$param = $promise_id ?: $payment_id;

try {
    $stmt = $pdo->prepare("SELECT id, payment_id, original_name, file_path, file_size, mime_type, created_at FROM payment_documents WHERE $where ORDER BY created_at DESC");
    $stmt->execute([$param]);
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
}
