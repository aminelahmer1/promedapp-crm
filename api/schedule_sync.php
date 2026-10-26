<?php
ini_set('display_errors', '0');
error_reporting(0);
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}
header('Content-Type: application/json; charset=utf-8');

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$promise_id = (int)($body['promise_id'] ?? 0);

if (!$promise_id) {
    http_response_code(400);
    echo json_encode(['error' => 'promise_id requis']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Recalculer paid_amount / remaining / status de chaque échéance
    //    à partir des paiements valides réels
    $insts = $pdo->prepare("
        SELECT pi.id, pi.expected_amount
        FROM payment_installments pi
        JOIN payment_schedules ps ON ps.id = pi.schedule_id
        WHERE ps.promise_id = ?
        ORDER BY pi.id
    ");
    $insts->execute([$promise_id]);
    $installments = $insts->fetchAll();

    foreach ($installments as $inst) {
        $iid      = (int)$inst['id'];
        $expected = (float)$inst['expected_amount'];

        // Somme des paiements valides pour cette échéance
        $sumStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM payments
            WHERE installment_id = ?
              AND (status IS NULL OR status NOT IN ('cancelled','pending_review'))
        ");
        $sumStmt->execute([$iid]);
        $paid = (float)$sumStmt->fetchColumn();

        $remaining = max(0, $expected - $paid);

        if ($paid <= 0) {
            $status = 'pending';
        } elseif ($remaining <= 0.005) {
            $status = 'paid';
        } else {
            $status = 'partially_paid';
        }

        // Vérifier overdue : date dépassée et pas encore payé
        if (in_array($status, ['pending', 'partially_paid'])) {
            $dueDateStmt = $pdo->prepare("SELECT due_date FROM payment_installments WHERE id = ?");
            $dueDateStmt->execute([$iid]);
            $dueDate = $dueDateStmt->fetchColumn();
            if ($dueDate && strtotime($dueDate) < strtotime('today')) {
                $status = 'overdue';
            }
        }

        $pdo->prepare("
            UPDATE payment_installments
            SET paid_amount = ?, remaining_amount = ?, status = ?
            WHERE id = ?
        ")->execute([$paid, $remaining, $status, $iid]);
    }

    // 2. Recalculer les totaux du payment_schedule
    $sched = $pdo->prepare("SELECT id, total_amount FROM payment_schedules WHERE promise_id = ?");
    $sched->execute([$promise_id]);
    $schedule = $sched->fetch();

    if ($schedule) {
        $sid = (int)$schedule['id'];

        // Total payé = somme des paiements valides sur la promesse
        $totalPaidStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM payments
            WHERE promise_id = ?
              AND (status IS NULL OR status NOT IN ('cancelled','pending_review'))
        ");
        $totalPaidStmt->execute([$promise_id]);
        $totalPaid = (float)$totalPaidStmt->fetchColumn();

        $totalAmount = (float)$schedule['total_amount'];
        $remaining   = max(0, $totalAmount - $totalPaid);
        $progress    = $totalAmount > 0 ? min(100, round($totalPaid / $totalAmount * 100, 2)) : 0;

        if ($totalPaid <= 0) {
            $schedStatus = 'pending';
        } elseif ($remaining <= 0.005) {
            $schedStatus = 'paid';
        } else {
            // 'partial' n'existe pas dans la base : même valeur que payment_add.php
            $schedStatus = 'in_progress';
        }
        // Garder « en retard » si une échéance est en retard (comme payment_add.php)
        if ($schedStatus !== 'paid') {
            $od = $pdo->prepare("SELECT COUNT(*) FROM payment_installments WHERE schedule_id = ? AND status = 'overdue'");
            $od->execute([$sid]);
            if ((int)$od->fetchColumn() > 0) $schedStatus = 'overdue';
        }

        $pdo->prepare("
            UPDATE payment_schedules
            SET paid_amount = ?, remaining_amount = ?, progress_percent = ?, status = ?
            WHERE id = ?
        ")->execute([$totalPaid, $remaining, $progress, $schedStatus, $sid]);
    }

    $pdo->commit();

    echo json_encode([
        'success'          => true,
        'installments_fixed' => count($installments),
        'total_paid'       => $totalPaid ?? 0,
        'remaining'        => $remaining ?? 0,
        'progress'         => $progress  ?? 0,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[schedule_sync] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
