<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) { http_response_code(400); echo json_encode(['error'=>'JSON invalide']); exit; }

$dossierId = isset($data['id']) ? (int)$data['id'] : 0;
$clientId  = (int)($data['client_id'] ?? 0);

if (!$clientId) {
    http_response_code(400);
    echo json_encode(['error' => 'client_id est obligatoire']);
    exit;
}

// Valid status transitions
$TRANSITIONS = [
    'interested'         => ['reserved','cancelled'],
    'reserved'           => ['promise_draft','cancelled'],
    'promise_draft'      => ['promise_signed','cancelled'],
    'promise_signed'     => ['sold','cancelled'],
    'sold'               => ['payment_in_progress','cancelled'],
    'payment_in_progress'=> ['paid','cancelled'],
    'paid'               => ['closed'],
    'closed'             => [],
    'cancelled'          => [],
];

function crm_audit(PDO $pdo, int $userId, int $dossierId, string $action, $old = null, $new = null): void {
    try {
        $pdo->prepare("INSERT INTO audit_logs (user_id,entity_type,entity_id,action,old_value,new_value) VALUES (?,?,?,?,?,?)")
            ->execute([$userId, 'crm_dossier', $dossierId, $action,
                $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
                $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null]);
    } catch (Throwable $e) { /* silent */ }
}

try {
    $pdo->beginTransaction();

    if ($dossierId > 0) {
        // UPDATE
        $stmt = $pdo->prepare("SELECT * FROM crm_dossiers WHERE id = ?");
        $stmt->execute([$dossierId]);
        $existing = $stmt->fetch();
        if (!$existing) { throw new RuntimeException('Dossier introuvable'); }

        $newStatus = $data['status'] ?? $existing['status'];

        // Validate transition if status is changing
        if ($newStatus !== $existing['status']) {
            $allowed = $TRANSITIONS[$existing['status']] ?? [];
            if (!in_array($newStatus, $allowed)) {
                throw new RuntimeException(
                    "Transition non autorisée : {$existing['status']} → {$newStatus}"
                );
            }
        }

        // Build date fields based on status
        $soldDate   = $existing['sold_date'];
        $closedDate = $existing['closed_date'];
        $resDate    = $data['reservation_date'] ?? $existing['reservation_date'];
        $resExp     = $data['reservation_expires_at'] ?? $existing['reservation_expires_at'];

        if ($newStatus === 'sold' && !$soldDate) { $soldDate = date('Y-m-d'); }
        if ($newStatus === 'closed' && !$closedDate) { $closedDate = date('Y-m-d'); }

        $fields = [
            'client_id'              => $clientId,
            'project_id'             => $data['project_id'] ?? $existing['project_id'],
            'unit_id'                => $data['unit_id'] ?? $existing['unit_id'],
            'unit_code'              => $data['unit_code'] ?? $existing['unit_code'],
            'status'                 => $newStatus,
            'source'                 => $data['source'] ?? $existing['source'],
            'assigned_to'            => $data['assigned_to'] ?? $existing['assigned_to'],
            'interest_level'         => $data['interest_level'] ?? $existing['interest_level'],
            'budget'                 => $data['budget'] ?? $existing['budget'],
            'reservation_date'       => $resDate ?: null,
            'reservation_expires_at' => $resExp ?: null,
            'sold_date'              => $soldDate ?: null,
            'closed_date'            => $closedDate ?: null,
            'notes'                  => $data['notes'] ?? $existing['notes'],
        ];

        $setClauses = [];
        foreach (array_keys($fields) as $k) { $setClauses[] = "$k = ?"; }
        $vals = array_values($fields);
        $vals[] = $dossierId;
        $pdo->prepare("UPDATE crm_dossiers SET " . implode(', ', $setClauses) . " WHERE id = ?")
            ->execute($vals);

        crm_audit($pdo, $_SESSION['user_id'], $dossierId, 'update', $existing, $fields);
        $pdo->commit();
        echo json_encode(['success' => true, 'id' => $dossierId], JSON_UNESCAPED_UNICODE);

    } else {
        // INSERT — generate dossier number DOS-YYYYMM-XXXX
        $seq    = (int)$pdo->query("SELECT COUNT(*)+1 FROM crm_dossiers WHERE YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())")->fetchColumn();
        $number = 'DOS-' . date('Ym') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

        $newStatus = $data['status'] ?? 'interested';
        $soldDate  = ($newStatus === 'sold')   ? date('Y-m-d') : null;
        $clDate    = ($newStatus === 'closed')  ? date('Y-m-d') : null;

        $stmt = $pdo->prepare("
            INSERT INTO crm_dossiers
              (dossier_number, client_id, project_id, unit_id, unit_code, status,
               source, assigned_to, interest_level, budget,
               reservation_date, reservation_expires_at, sold_date, closed_date,
               notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $number,
            $clientId,
            $data['project_id'] ?? null,
            $data['unit_id'] ?? null,
            $data['unit_code'] ?? null,
            $newStatus,
            $data['source'] ?? null,
            $data['assigned_to'] ?? null,
            $data['interest_level'] ?? 'medium',
            $data['budget'] ?? null,
            $data['reservation_date'] ?: null,
            $data['reservation_expires_at'] ?: null,
            $soldDate,
            $clDate,
            $data['notes'] ?? null,
            $_SESSION['user_id'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        crm_audit($pdo, $_SESSION['user_id'], $newId, 'create', null, ['dossier_number' => $number, 'status' => $newStatus]);
        $pdo->commit();
        echo json_encode(['success' => true, 'id' => $newId, 'dossier_number' => $number], JSON_UNESCAPED_UNICODE);
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
