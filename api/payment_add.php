<?php
ini_set('display_errors', '0');
error_reporting(0);
ob_start();
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) {
    ob_clean(); http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']); exit;
}
header('Content-Type: application/json; charset=utf-8');

/* ── Auto-réparation colonnes payments ──────────────────────────────────── */
function ensure_payment_columns(PDO $pdo): void {
    $needed = [
        'promise_id'       => 'INT DEFAULT NULL',
        'installment_id'   => 'INT DEFAULT NULL',
        'client_id'        => 'INT DEFAULT NULL',
        'unit_code'        => 'VARCHAR(64) DEFAULT NULL',
        'payment_date'     => 'DATE DEFAULT NULL',
        'amount'           => 'DECIMAL(14,3) DEFAULT NULL',
        'payment_method'   => 'VARCHAR(50) DEFAULT NULL',
        'bank_name'        => 'VARCHAR(100) DEFAULT NULL',
        'reference_number' => 'VARCHAR(100) DEFAULT NULL',
        'receipt_number'   => 'VARCHAR(100) DEFAULT NULL',
        'notes'            => 'TEXT DEFAULT NULL',
        'created_by'       => 'INT DEFAULT NULL',
        'status'           => "ENUM('valid','cancelled','corrected','pending_review') NOT NULL DEFAULT 'valid'",
        'updated_at'       => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ];
    // Étendre l'ENUM si pending_review manque (colonne existe déjà mais sans la valeur)
    try {
        $r = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND COLUMN_NAME='status'");
        $colType = $r ? (string)$r->fetchColumn() : '';
        if ($colType && strpos($colType, 'pending_review') === false) {
            $pdo->exec("ALTER TABLE payments MODIFY COLUMN status ENUM('valid','cancelled','corrected','pending_review') NOT NULL DEFAULT 'valid'");
        }
    } catch (Throwable $_) { /* non bloquant */ }
    $s = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments'");
    $existing = array_flip($s->fetchAll(PDO::FETCH_COLUMN));
    foreach ($needed as $col => $def) {
        if (!isset($existing[$col])) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN `$col` $def");
        }
    }
}

try { ensure_payment_columns($pdo); }
catch (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['error' => 'Impossible de préparer la table payments : ' . $e->getMessage()]); exit;
}

/* ── Lecture et validation des entrées ──────────────────────────────────── */
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    ob_clean(); http_response_code(400);
    echo json_encode(['error' => 'JSON invalide']); exit;
}

$promise_id     = (int)($data['promise_id']     ?? 0);
$amount         = round((float)($data['amount'] ?? 0), 3);
$payment_date   = trim((string)($data['payment_date']  ?? ''));
$payment_method = trim((string)($data['payment_method'] ?? 'bank_transfer'));
$installment_id = isset($data['installment_id']) && $data['installment_id'] ? (int)$data['installment_id'] : null;

if (!$promise_id || $amount <= 0 || !$payment_date) {
    ob_clean(); http_response_code(400);
    echo json_encode(['error' => 'promise_id, amount (> 0) et payment_date sont obligatoires']); exit;
}

$allowed_methods = ['cash', 'cheque', 'bank_transfer', 'traite'];
if (!in_array($payment_method, $allowed_methods, true)) {
    ob_clean(); http_response_code(400);
    echo json_encode(['error' => 'Méthode de paiement invalide. Valeurs acceptées : ' . implode(', ', $allowed_methods)]); exit;
}

/* ── Helpers ────────────────────────────────────────────────────────────── */
function audit_log(PDO $pdo, int $userId, string $entity, int $entityId, string $action, $old = null, $new = null): void {
    try {
        $pdo->prepare("INSERT INTO audit_logs (user_id,entity_type,entity_id,action,old_value,new_value) VALUES (?,?,?,?,?,?)")
            ->execute([$userId, $entity, $entityId, $action,
                $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
                $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null]);
    } catch (Throwable $e) { /* silent */ }
}

// Somme des paiements valides (exclut 'cancelled') pour une colonne de filtre donnée
function sum_valid_payments(PDO $pdo, string $col, int $id): float {
    $s = $pdo->prepare("
        SELECT COALESCE(SUM(COALESCE(amount, montant_paye, 0)), 0)
        FROM payments
        WHERE `$col` = ? AND (status IS NULL OR status != 'cancelled')
    ");
    $s->execute([$id]);
    return (float)$s->fetchColumn();
}

/* ── Transaction principale ─────────────────────────────────────────────── */
try {
    $pdo->beginTransaction();

    // ── 1. Vérifier que la promesse existe ───────────────────────────────
    $stmt = $pdo->prepare("SELECT * FROM promises WHERE id = ?");
    $stmt->execute([$promise_id]);
    $promise = $stmt->fetch();
    if (!$promise) {
        throw new RuntimeException("Promesse #$promise_id introuvable.");
    }

    // ── 2. Vérifier que l'échéance appartient bien à cette promesse ──────
    $inst = null;
    if ($installment_id) {
        $stmt = $pdo->prepare("
            SELECT pi.*
            FROM payment_installments pi
            JOIN payment_schedules ps ON ps.id = pi.schedule_id
            WHERE pi.id = ? AND ps.promise_id = ?
        ");
        $stmt->execute([$installment_id, $promise_id]);
        $inst = $stmt->fetch();
        if (!$inst) {
            throw new RuntimeException(
                "Échéance #$installment_id introuvable ou n'appartient pas à la promesse #$promise_id."
            );
        }
    }

    // ── 3. Contrôle de surpaiement sur l'échéance ────────────────────────
    if ($inst) {
        // Recalcul depuis la DB (paiements valides uniquement) pour éviter les valeurs stale
        $actual_paid = sum_valid_payments($pdo, 'installment_id', $installment_id);
        $expected    = (float)$inst['expected_amount'];
        $actual_remaining = max(0, $expected - $actual_paid);

        if ($amount > $actual_remaining + 0.005) { // tolérance arrondi
            throw new RuntimeException(sprintf(
                'Montant saisi (%.3f TND) dépasse le reste à payer de cette échéance (%.3f TND).',
                $amount, $actual_remaining
            ));
        }
    }

    // ── 3b. Vérification anti-mélange ────────────────────────────────────
    // Garantit que promise.client_id et promise.unit_code correspondent à ce qui est attendu
    if ($promise['client_id'] != ($data['client_id'] ?? $promise['client_id'])) {
        throw new RuntimeException('Incohérence client_id : le paiement ne correspond pas au client de cette promesse.');
    }
    if (!empty($data['unit_code']) && strtoupper($data['unit_code']) !== strtoupper($promise['unit_code'])) {
        throw new RuntimeException(
            "Incohérence unit_code : attendu {$promise['unit_code']}, reçu {$data['unit_code']}."
        );
    }

    // ── 4. Insérer le paiement ───────────────────────────────────────────
    $vente_id = !empty($promise['vente_id']) ? (int)$promise['vente_id'] : null;

    $pdo->prepare("
        INSERT INTO payments
          (vente_id, promise_id, installment_id, client_id, unit_code,
           payment_date, amount, payment_method, bank_name, reference_number,
           receipt_number, notes, created_by, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'valid')
    ")->execute([
        $vente_id,
        $promise_id,
        $installment_id,
        $promise['client_id'],
        $promise['unit_code'],
        $payment_date,
        $amount,
        $payment_method,
        trim($data['bank_name']       ?? '') ?: null,
        trim($data['reference_number'] ?? '') ?: null,
        trim($data['receipt_number']  ?? '') ?: null,
        trim($data['notes']           ?? '') ?: null,
        $_SESSION['user_id'],
    ]);
    $payment_id = (int)$pdo->lastInsertId();

    // ── 5. Recalculer l'échéance depuis les paiements valides en DB ──────
    if ($inst) {
        $new_inst_paid = sum_valid_payments($pdo, 'installment_id', $installment_id);
        $expected      = (float)$inst['expected_amount'];
        $new_remaining = max(0, $expected - $new_inst_paid);

        $istatus = 'pending';
        if ($new_inst_paid >= $expected && $expected > 0) {
            $istatus = 'paid';
        } elseif ($new_inst_paid > 0) {
            $istatus = 'partially_paid';
        } elseif (!empty($inst['due_date']) && $inst['due_date'] < date('Y-m-d')) {
            $istatus = 'overdue';
        }

        $pdo->prepare("
            UPDATE payment_installments
            SET paid_amount=?, remaining_amount=?, status=?, updated_at=NOW()
            WHERE id=?
        ")->execute([$new_inst_paid, $new_remaining, $istatus, $installment_id]);
    }

    // ── 6. Recalculer le schedule depuis les paiements valides en DB ─────
    $stmt = $pdo->prepare("SELECT id, total_amount FROM payment_schedules WHERE promise_id = ?");
    $stmt->execute([$promise_id]);
    $schedule = $stmt->fetch();

    $total_paid = sum_valid_payments($pdo, 'promise_id', $promise_id);

    if ($schedule) {
        $schedule_id  = (int)$schedule['id'];
        $total_amount = (float)$schedule['total_amount'];
        $remaining    = max(0, $total_amount - $total_paid);
        $progress     = $total_amount > 0 ? round($total_paid / $total_amount * 100, 2) : 0;

        $sstatus = 'pending';
        if ($total_paid >= $total_amount && $total_amount > 0) {
            $sstatus = 'paid';
        } elseif ($total_paid > 0) {
            $sstatus = 'in_progress';
        }
        // Vérifier si une échéance est en retard (uniquement si pas entièrement payé)
        if ($sstatus !== 'paid') {
            $s = $pdo->prepare("SELECT COUNT(*) FROM payment_installments WHERE schedule_id=? AND status='overdue'");
            $s->execute([$schedule_id]);
            if ((int)$s->fetchColumn() > 0) $sstatus = 'overdue';
        }

        $pdo->prepare("
            UPDATE payment_schedules
            SET paid_amount=?, remaining_amount=?, progress_percent=?, status=?, updated_at=NOW()
            WHERE id=?
        ")->execute([$total_paid, $remaining, $progress, $sstatus, $schedule_id]);

    } else {
        // Pas d'échéancier → créer un schedule minimal
        $total_amount = max((float)($promise['sale_price'] ?? 0), $amount);
        $remaining    = max(0, $total_amount - $total_paid);
        $progress     = $total_amount > 0 ? round($total_paid / $total_amount * 100, 2) : 0;
        $sstatus      = $total_paid >= $total_amount ? 'paid' : ($total_paid > 0 ? 'in_progress' : 'pending');

        $pdo->prepare("
            INSERT INTO payment_schedules
              (promise_id, client_id, unit_code, total_amount, paid_amount, remaining_amount, progress_percent, status)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([
            $promise_id, $promise['client_id'], $promise['unit_code'],
            $total_amount, $total_paid, $remaining, $progress, $sstatus,
        ]);
    }

    // ── 7. Audit ────────────────────────────────────────────────────────
    audit_log($pdo, $_SESSION['user_id'], 'promise', $promise_id, 'payment_added', null, [
        'payment_id'     => $payment_id,
        'installment_id' => $installment_id,
        'amount'         => $amount,
        'method'         => $payment_method,
        'date'           => $payment_date,
    ]);

    $pdo->commit();
    ob_clean();
    echo json_encode([
        'success'    => true,
        'payment_id' => $payment_id,
        'total_paid' => $total_paid,
    ], JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_clean();
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
