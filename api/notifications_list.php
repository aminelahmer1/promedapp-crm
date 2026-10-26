<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$limit   = min((int)($_GET['limit'] ?? 50), 200);
$unread  = isset($_GET['unread']) && $_GET['unread'] === '1';

$where = $unread ? 'WHERE is_read = 0' : '';
try {
    $rows = $pdo->query("SELECT * FROM notifications $where ORDER BY created_at DESC LIMIT $limit")->fetchAll();
    $count_unread = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
    echo json_encode(['notifications' => $rows, 'unread_count' => $count_unread], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
}
