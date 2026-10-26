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

$installment_id = (int)($_GET['installment_id'] ?? 0);
if (!$installment_id) {
    http_response_code(400);
    echo json_encode(['error' => 'installment_id requis']);
    exit;
}

try {
    // Vérifier que l'échéance existe et récupérer son contexte
    $stmt = $pdo->prepare("
        SELECT pi.*, ps.promise_id, ps.total_amount AS schedule_total
        FROM payment_installments pi
        JOIN payment_schedules ps ON ps.id = pi.schedule_id
        WHERE pi.id = ?
    ");
    $stmt->execute([$installment_id]);
    $inst = $stmt->fetch();
    if (!$inst) {
        http_response_code(404);
        echo json_encode(['error' => 'Échéance introuvable']);
        exit;
    }

    // Paiements valides liés à cette échéance
    $stmt2 = $pdo->prepare("
        SELECT p.id, p.amount, p.payment_date, p.payment_method,
               p.bank_name, p.reference_number, p.receipt_number,
               p.notes, p.status, p.created_at,
               u.full_name AS created_by_name
        FROM payments p
        LEFT JOIN users u ON u.id = p.created_by
        WHERE p.installment_id = ?
          AND (p.status IS NULL OR p.status NOT IN ('cancelled','pending_review'))
        ORDER BY p.payment_date ASC, p.id ASC
    ");
    $stmt2->execute([$installment_id]);
    $payments = $stmt2->fetchAll();

    // Documents par paiement
    $payment_ids = array_column($payments, 'id');
    $docs_by_payment = [];

    if ($payment_ids) {
        $placeholders = implode(',', array_fill(0, count($payment_ids), '?'));
        $stmt3 = $pdo->prepare("
            SELECT d.id, d.payment_id, d.original_name, d.stored_name,
                   d.file_path, d.mime_type, d.file_size, d.created_at
            FROM payment_documents d
            WHERE d.payment_id IN ($placeholders)
            ORDER BY d.created_at ASC
        ");
        $stmt3->execute($payment_ids);
        foreach ($stmt3->fetchAll() as $doc) {
            $docs_by_payment[(int)$doc['payment_id']][] = $doc;
        }
    }

    // Documents liés directement à l'échéance (sans payment_id spécifique)
    $stmt4 = $pdo->prepare("
        SELECT d.id, d.payment_id, d.original_name, d.stored_name,
               d.file_path, d.mime_type, d.file_size, d.created_at
        FROM payment_documents d
        WHERE d.installment_id = ? AND (d.payment_id IS NULL OR d.payment_id = 0)
        ORDER BY d.created_at ASC
    ");
    $stmt4->execute([$installment_id]);
    $orphan_docs = $stmt4->fetchAll();

    // Enrichir chaque paiement avec ses documents
    foreach ($payments as &$pay) {
        $pay['documents'] = $docs_by_payment[(int)$pay['id']] ?? [];
        $pay['amount']    = (float)$pay['amount'];
    }
    unset($pay);

    echo json_encode([
        'installment' => [
            'id'              => (int)$inst['id'],
            'label'           => $inst['label'],
            'due_date'        => $inst['due_date'],
            'expected_amount' => (float)$inst['expected_amount'],
            'paid_amount'     => (float)$inst['paid_amount'],
            'remaining_amount'=> (float)$inst['remaining_amount'],
            'status'          => $inst['status'],
        ],
        'payments'     => $payments,
        'orphan_docs'  => $orphan_docs,
        'total_payments' => count($payments),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[schedule_payments_get] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
