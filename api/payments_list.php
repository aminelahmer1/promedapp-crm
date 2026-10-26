<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$promise_id = (int)($_GET['promise_id'] ?? 0);
$client_id  = (int)($_GET['client_id'] ?? 0);

if (!$promise_id && !$client_id) {
    http_response_code(400);
    echo json_encode(['error' => 'promise_id ou client_id requis']);
    exit;
}

try {
    $where  = [];
    $params = [];

    if ($promise_id) { $where[] = 'py.promise_id = ?'; $params[] = $promise_id; }
    if ($client_id)  { $where[] = 'py.client_id = ?';  $params[] = $client_id; }

    $sql = "
        SELECT
            py.*,
            pi.label   AS installment_label,
            pi.due_date AS installment_due_date,
            u.username  AS created_by_name
        FROM payments py
        LEFT JOIN payment_installments pi ON pi.id = py.installment_id
        LEFT JOIN users u ON u.id = py.created_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY py.payment_date DESC, py.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll();

    $total = array_sum(array_column($payments, 'amount'));

    echo json_encode(['payments' => $payments, 'total_paid' => $total], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
