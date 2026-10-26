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

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    ob_clean(); http_response_code(400);
    echo json_encode(['error' => 'JSON invalide']); exit;
}

// ── Validation champs obligatoires ──────────────────────────────────────
$unit_code  = trim((string)($data['unit_code'] ?? ''));
$option_date = trim((string)($data['option_date'] ?? ''));
$valid_until = trim((string)($data['valid_until'] ?? ''));

if (!$unit_code || !$option_date || !$valid_until) {
    ob_clean(); http_response_code(400);
    echo json_encode(['error' => 'unit_code, option_date et valid_until sont obligatoires']); exit;
}

// ── Résolution client si absent (mode direct : lookup par CIN) ───────────
$promise_id = (int)($data['promise_id'] ?? 0);
$client_id  = (int)($data['client_id']  ?? 0);
$project_id = isset($data['project_id']) && $data['project_id'] ? (int)$data['project_id'] : null;

if (!$client_id && !empty($data['client_cin'])) {
    try {
        $s = $pdo->prepare("SELECT id FROM clients WHERE cin = ? LIMIT 1");
        $s->execute([trim($data['client_cin'])]);
        $row = $s->fetch();
        if ($row) $client_id = (int)$row['id'];
    } catch (Throwable $e) { /* silent */ }
}

// ── Génération du numéro d'option (idempotent : réutilise si fourni) ─────
$option_number = trim((string)($data['option_number'] ?? ''));

try {
    if ($option_number !== '') {
        // Vérifier que ce numéro n'est pas déjà pris par une AUTRE option
        $s = $pdo->prepare("SELECT id FROM option_ventes WHERE option_number = ? LIMIT 1");
        $s->execute([$option_number]);
        $existing = $s->fetch();
        if ($existing) {
            // Numéro déjà pris → on met à jour cet enregistrement
            $existing_id = (int)$existing['id'];
        }
    } else {
        // Générer OPT-YYYYMM-XXXX en utilisant MAX pour éviter les collisions
        $s = $pdo->query("
            SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(option_number, '-', -1) AS UNSIGNED)), 0) + 1
            FROM option_ventes
            WHERE option_number LIKE 'OPT-" . date('Ym') . "-%'
        ");
        $seq = (int)$s->fetchColumn();
        $option_number = 'OPT-' . date('Ym') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
} catch (Throwable $e) {
    $seq = 1;
    $option_number = $option_number ?: ('OPT-' . date('Ym') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT));
}

// ── Champs métier ────────────────────────────────────────────────────────
$reservation_amount = round((float)($data['reservation_amount'] ?? 0), 3);
$currency           = trim((string)($data['currency'] ?? 'TND'));
$payment_status     = in_array($data['payment_status'] ?? '', ['paid','deferred','none'])
                      ? $data['payment_status'] : 'none';
$payment_method     = trim((string)($data['payment_method']    ?? '')) ?: null;
$payment_reference  = trim((string)($data['payment_reference'] ?? '')) ?: null;
$status             = in_array($data['status'] ?? '', ['active','expired','converted','cancelled'])
                      ? $data['status'] : 'active';
$details_json       = isset($data['details_json']) && is_array($data['details_json'])
                      ? json_encode($data['details_json'], JSON_UNESCAPED_UNICODE)
                      : null;

try {
    if (!empty($existing_id)) {
        // ── UPDATE (même numéro) ────────────────────────────────────────
        $pdo->prepare("
            UPDATE option_ventes SET
                promise_id=?, client_id=?, project_id=?, unit_code=?,
                reservation_amount=?, currency=?, option_date=?, valid_until=?,
                payment_status=?, payment_method=?, payment_reference=?,
                status=?, details_json=?, updated_at=NOW()
            WHERE id=?
        ")->execute([
            $promise_id ?: null, $client_id ?: null, $project_id,
            $unit_code, $reservation_amount, $currency,
            $option_date, $valid_until,
            $payment_status, $payment_method, $payment_reference,
            $status, $details_json,
            $existing_id,
        ]);
        ob_clean();
        echo json_encode([
            'success'       => true,
            'id'            => $existing_id,
            'option_number' => $option_number,
            'action'        => 'updated',
        ], JSON_UNESCAPED_UNICODE);

    } else {
        // ── INSERT ──────────────────────────────────────────────────────
        $pdo->prepare("
            INSERT INTO option_ventes
              (option_number, promise_id, client_id, project_id, unit_code,
               reservation_amount, currency, option_date, valid_until,
               payment_status, payment_method, payment_reference,
               status, details_json, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $option_number,
            $promise_id ?: null, $client_id ?: null, $project_id,
            $unit_code, $reservation_amount, $currency,
            $option_date, $valid_until,
            $payment_status, $payment_method, $payment_reference,
            $status, $details_json,
            $_SESSION['user_id'],
        ]);
        $new_id = (int)$pdo->lastInsertId();
        ob_clean();
        echo json_encode([
            'success'       => true,
            'id'            => $new_id,
            'option_number' => $option_number,
            'action'        => 'created',
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
