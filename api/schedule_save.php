<?php
ini_set('display_errors', '0');
error_reporting(0);
ob_start();
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { ob_clean(); http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) { http_response_code(400); echo json_encode(['error'=>'JSON invalide']); exit; }

$promise_id = (int)($data['promise_id'] ?? 0);
if (!$promise_id) { http_response_code(400); echo json_encode(['error'=>'promise_id requis']); exit; }

function audit_log(PDO $pdo, int $userId, string $entity, int $entityId, string $action, $old = null, $new = null): void {
    try {
        $pdo->prepare("INSERT INTO audit_logs (user_id,entity_type,entity_id,action,old_value,new_value) VALUES (?,?,?,?,?,?)")
            ->execute([$userId, $entity, $entityId, $action,
                $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
                $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null]);
    } catch (Throwable $e) { /* silent */ }
}

try {
    // Ensure required columns exist
    try { $pdo->exec("ALTER TABLE payment_installments ADD COLUMN sort_order INT NOT NULL DEFAULT 0"); } catch(Throwable $_) {}
    try { $pdo->exec("ALTER TABLE payment_installments ADD COLUMN notes TEXT NULL"); } catch(Throwable $_) {}

    $pdo->beginTransaction();

    // Verify promise exists and not locked
    $stmt = $pdo->prepare("SELECT * FROM promises WHERE id = ?");
    $stmt->execute([$promise_id]);
    $promise = $stmt->fetch();
    if (!$promise) { throw new RuntimeException('Promesse introuvable'); }
    if ($promise['is_locked']) { throw new RuntimeException('Cette promesse est verrouillée.'); }

    $installments = $data['installments'] ?? [];
    $total_amount = 0;
    foreach ($installments as $inst) {
        $total_amount += (float)($inst['expected_amount'] ?? 0);
    }

    // Upsert schedule
    $stmt = $pdo->prepare("SELECT id, paid_amount FROM payment_schedules WHERE promise_id = ?");
    $stmt->execute([$promise_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $schedule_id  = (int)$existing['id'];
        $paid_amount  = (float)$existing['paid_amount'];
        $remaining    = max(0, $total_amount - $paid_amount);
        $progress     = $total_amount > 0 ? round($paid_amount / $total_amount * 100, 2) : 0;
        $status       = $data['status'] ?? ($paid_amount >= $total_amount ? 'paid' : ($paid_amount > 0 ? 'in_progress' : 'pending'));

        $pdo->prepare("UPDATE payment_schedules SET total_amount=?, remaining_amount=?, progress_percent=?, status=?, updated_at=NOW() WHERE id=?")
            ->execute([$total_amount, $remaining, $progress, $status, $schedule_id]);
    } else {
        $status = $data['status'] ?? 'pending';
        $pdo->prepare("INSERT INTO payment_schedules (promise_id,client_id,unit_code,total_amount,paid_amount,remaining_amount,progress_percent,status) VALUES (?,?,?,?,0,?,0,?)")
            ->execute([$promise_id, $promise['client_id'], $promise['unit_code'], $total_amount, $total_amount, $status]);
        $schedule_id = (int)$pdo->lastInsertId();
    }

    // Identifier les échéances qui ont déjà des paiements valides → intouchables
    $stmt = $pdo->prepare("
        SELECT DISTINCT pi.id, pi.sort_order
        FROM payment_installments pi
        JOIN payments pay ON pay.installment_id = pi.id
        WHERE pi.schedule_id = ?
          AND (pay.status IS NULL OR pay.status != 'cancelled')
    ");
    $stmt->execute([$schedule_id]);
    $lockedRows       = $stmt->fetchAll();
    $lockedInstIds    = array_flip(array_column($lockedRows, 'id'));
    // Map sort_order → installment_id pour les tranches verrouillées
    $lockedBySortOrder = [];
    foreach ($lockedRows as $lr) {
        $lockedBySortOrder[(int)$lr['sort_order']] = (int)$lr['id'];
    }

    if (count($lockedInstIds) > 0) {
        // Des échéances ont des paiements → on ne supprime que celles sans paiement
        $pdo->prepare("
            DELETE FROM payment_installments
            WHERE schedule_id = ?
              AND id NOT IN (SELECT id FROM (
                  SELECT DISTINCT pi2.id
                  FROM payment_installments pi2
                  JOIN payments pay2 ON pay2.installment_id = pi2.id
                  WHERE pi2.schedule_id = ?
                    AND (pay2.status IS NULL OR pay2.status != 'cancelled')
              ) AS paid_insts)
        ")->execute([$schedule_id, $schedule_id]);
    } else {
        // Aucun paiement → suppression normale de toutes les échéances
        $pdo->prepare("DELETE FROM payment_installments WHERE schedule_id = ?")->execute([$schedule_id]);
    }

    $sort = 0;
    foreach ($installments as $inst) {
        $expected = (float)($inst['expected_amount'] ?? 0);
        $inst_id  = isset($inst['id']) ? (int)$inst['id'] : 0;

        // Résoudre l'ID verrouillé : par ID explicite OU par position (sort_order)
        $locked_id = 0;
        if ($inst_id && isset($lockedInstIds[$inst_id])) {
            $locked_id = $inst_id;
        } elseif (isset($lockedBySortOrder[$sort])) {
            $locked_id = $lockedBySortOrder[$sort];
        }

        if ($locked_id) {
            // Tranche déjà payée : mettre à jour label/date/montant sans toucher aux paiements
            $pdo->prepare("
                UPDATE payment_installments
                SET label=?, due_date=?, expected_amount=?, notes=?, sort_order=?, updated_at=NOW()
                WHERE id=?
            ")->execute([
                $inst['label'] ?? null,
                !empty($inst['due_date']) ? $inst['due_date'] : null,
                $expected,
                $inst['notes'] ?? null,
                $sort++,
                $locked_id,
            ]);
            continue;
        }

        // Nouvelle tranche ou tranche sans paiement → INSERT
        $paid      = (float)($inst['paid_amount'] ?? 0);
        $remaining = max(0, $expected - $paid);
        $istatus   = 'pending';
        if ($paid >= $expected && $expected > 0)   $istatus = 'paid';
        elseif ($paid > 0)                         $istatus = 'partially_paid';
        elseif (!empty($inst['due_date']) && $inst['due_date'] < date('Y-m-d')) $istatus = 'overdue';

        $pdo->prepare("
            INSERT INTO payment_installments
              (schedule_id, promise_id, label, due_date, expected_amount,
               paid_amount, remaining_amount, status, notes, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $schedule_id,
            $promise_id,
            $inst['label'] ?? null,
            !empty($inst['due_date']) ? $inst['due_date'] : null,
            $expected,
            $paid,
            $remaining,
            $istatus,
            $inst['notes'] ?? null,
            $sort++,
        ]);
    }

    audit_log($pdo, $_SESSION['user_id'], 'promise', $promise_id, 'schedule_updated', null, ['schedule_id' => $schedule_id, 'total' => $total_amount, 'installments' => count($installments)]);
    $pdo->commit();

    ob_clean();
    echo json_encode(['success' => true, 'schedule_id' => $schedule_id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
