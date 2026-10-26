<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$id        = (int)($_GET['id'] ?? 0);
$unit_code = trim((string)($_GET['unit_code'] ?? ''));

try {
    if ($id > 0) {
        $stmt = $pdo->prepare("
            SELECT p.*, c.nom AS client_nom, c.tel AS client_tel, c.email AS client_email,
                   c.cin AS client_cin, c.address AS client_address,
                   pj.code AS project_code, pj.nom AS project_nom
            FROM promises p
            LEFT JOIN clients c ON c.id = p.client_id
            LEFT JOIN projects pj ON pj.id = p.project_id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
    } elseif ($unit_code !== '') {
        $stmt = $pdo->prepare("
            SELECT p.*, c.nom AS client_nom, c.tel AS client_tel, c.email AS client_email,
                   c.cin AS client_cin, c.address AS client_address,
                   pj.code AS project_code, pj.nom AS project_nom
            FROM promises p
            LEFT JOIN clients c ON c.id = p.client_id
            LEFT JOIN projects pj ON pj.id = p.project_id
            WHERE UPPER(p.unit_code) = UPPER(?)
            ORDER BY p.id DESC LIMIT 1
        ");
        $stmt->execute([$unit_code]);
    } else {
        http_response_code(400); echo json_encode(['error' => 'id ou unit_code requis']); exit;
    }

    $promise = $stmt->fetch();
    if (!$promise) { echo json_encode(['error' => 'not_found']); exit; }

    $pid = (int)$promise['id'];

    // Schedule
    $stmt2 = $pdo->prepare("SELECT * FROM payment_schedules WHERE promise_id = ?");
    $stmt2->execute([$pid]);
    $schedule = $stmt2->fetch() ?: null;

    // Installments
    $installments = [];
    if ($schedule) {
        $stmt3 = $pdo->prepare("SELECT * FROM payment_installments WHERE schedule_id = ? ORDER BY sort_order, due_date");
        $stmt3->execute([$schedule['id']]);
        $installments = $stmt3->fetchAll();
    }

    // Payments
    $stmt4 = $pdo->prepare("SELECT * FROM payments WHERE promise_id = ? ORDER BY payment_date DESC");
    $stmt4->execute([$pid]);
    $payments = $stmt4->fetchAll();

    // Audit log
    $stmt5 = $pdo->prepare("SELECT al.*, u.username FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id WHERE al.entity_type = 'promise' AND al.entity_id = ? ORDER BY al.created_at DESC LIMIT 20");
    $stmt5->execute([$pid]);
    $audit = $stmt5->fetchAll();

    echo json_encode([
        'promise'      => $promise,
        'schedule'     => $schedule,
        'installments' => $installments,
        'payments'     => $payments,
        'audit'        => $audit,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
