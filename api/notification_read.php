<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];
$id   = (int)($data['id'] ?? 0);

try {
    if ($id) {
        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=?")->execute([$id]);
    } else {
        // Mark all as read
        $pdo->exec("UPDATE notifications SET is_read=1 WHERE is_read=0");
    }
    $count = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn();
    echo json_encode(['success' => true, 'unread_count' => $count]);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
}
