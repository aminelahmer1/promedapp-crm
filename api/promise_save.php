<?php
ini_set('display_errors', '0');
error_reporting(0);
ob_start();
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/reservation_lib.php';
if (empty($_SESSION['user_id'])) { ob_clean(); http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) { http_response_code(400); echo json_encode(['error'=>'JSON invalide']); exit; }

$promise_id  = isset($data['id']) ? (int)$data['id'] : 0;
$client_id   = (int)($data['client_id'] ?? 0);
$unit_code   = trim((string)($data['unit_code'] ?? ''));
$sale_price  = (float)($data['sale_price'] ?? 0);

if (!$client_id || !$unit_code || $sale_price <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'client_id, unit_code et sale_price sont obligatoires']);
    exit;
}

function audit_log(PDO $pdo, int $userId, string $entity, int $entityId, string $action, $old = null, $new = null): void {
    try {
        $pdo->prepare("INSERT INTO audit_logs (user_id,entity_type,entity_id,action,old_value,new_value) VALUES (?,?,?,?,?,?)")
            ->execute([$userId, $entity, $entityId, $action,
                $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
                $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null]);
    } catch (Throwable $e) { /* silent */ }
}

function syncAccessoryPrices(PDO $pdo, ?int $projectId, array $detailsJson, float $vatRate): void {
    if (!$projectId) return;

    $mult = 1 + $vatRate / 100;
    $upd = $pdo->prepare("UPDATE unit_accessoires SET price_ht_sale = ?, price_ttc_sale = ? WHERE project_id = ? AND code = ?");

    $allPrices = array_merge(
        (array)($detailsJson['parkingPrices'] ?? []),
        (array)($detailsJson['cellierPrices'] ?? [])
    );
    foreach ($allPrices as $code => $priceHt) {
        $priceHt = (float)$priceHt;
        if ($priceHt <= 0 || trim($code) === '') continue;
        $upd->execute([round($priceHt, 3), round($priceHt * $mult, 3), $projectId, trim($code)]);
    }
}

// Parse a comma-separated list of accessory codes
function parseCodes($str): array {
    return array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$str)))));
}

// Reserve (R) or sell (V) the accessories of an active promise,
// and free (D) the ones removed from it or from a cancelled promise.
function syncAccessoryStatus(PDO $pdo, int $promiseId, ?int $projectId, string $unitCode, array $newCodes, array $oldCodes, string $promiseStatus): void {
    if (!$projectId) return;
    $stmt = $pdo->prepare("SELECT status_code FROM units WHERE ref_code = ? AND project_id = ? LIMIT 1");
    $stmt->execute([$unitCode, $projectId]);
    $unitRow = $stmt->fetch();
    if (!$unitRow) return;

    $active = in_array($promiseStatus, ['draft', 'signed', 'converted_to_sale'], true);
    $target = ($unitRow['status_code'] === 'V' || $promiseStatus === 'converted_to_sale') ? 'V' : 'R';

    // 1) Reserve / sell accessories of the promise (never touch sold or rented ones)
    if ($active && $newCodes) {
        $upd = $pdo->prepare("UPDATE unit_accessoires SET status_code = ?
                              WHERE project_id = ? AND code = ? AND IFNULL(status_code,'') NOT IN ('V','L')");
        foreach ($newCodes as $code) {
            $upd->execute([$target, $projectId, $code]);
        }
    }

    // 2) Free accessories no longer in this promise (all of them if cancelled)
    $toFree = $active ? array_diff($oldCodes, $newCodes) : array_unique(array_merge($oldCodes, $newCodes));
    if (!$toFree) return;

    // Parking retiré ou promesse annulée : Disponible (même s'il était Vendu par cette promesse),
    // sauf s'il est dans une autre promesse en cours ou vendu avec un autre appartement
    $free = $pdo->prepare("UPDATE unit_accessoires SET status_code = 'D', reserved_at = NULL
                           WHERE project_id = ? AND code = ? AND status_code IN ('R','V')");
    foreach ($toFree as $code) {
        if (findActivePromiseForAccessory($pdo, $projectId, $code, $promiseId)) continue;
        if (accessorySoldWithOtherUnit($pdo, $projectId, $code, $unitCode)) continue;
        $free->execute([$projectId, $code]);
    }
}

// Promesse convertie en vente : l'appartement passe en Vendu (V)
function markUnitSold(PDO $pdo, string $unitCode, ?int $projectId): void {
    if (!$projectId) return;
    $pdo->prepare("UPDATE units SET status_code = 'V', reserved_at = NULL WHERE ref_code = ? AND project_id = ?")
        ->execute([$unitCode, $projectId]);
}

// Promesse annulée : l'appartement redevient Disponible,
// sauf s'il a une autre promesse en cours ou une option de vente valable
function freeUnitOnCancel(PDO $pdo, int $promiseId, string $unitCode, ?int $projectId): void {
    if (!$projectId) return;
    if (findActivePromiseForUnit($pdo, $projectId, $unitCode, $promiseId)) return;
    if (hasValidOption($pdo, $projectId, $unitCode)) return;
    $pdo->prepare("UPDATE units SET status_code = 'D', reserved_at = NULL
                   WHERE ref_code = ? AND project_id = ? AND status_code IN ('R','V')")
        ->execute([$unitCode, $projectId]);
}

// Refuse une 2e promesse en cours sur le même appartement (double vente)
function assertNoOtherActivePromise(PDO $pdo, int $promiseId, string $unitCode, ?int $projectId): void {
    if (!$projectId) return;
    $other = findActivePromiseForUnit($pdo, $projectId, $unitCode, $promiseId);
    if ($other) {
        throw new RuntimeException("L'appartement $unitCode a déjà une promesse en cours ("
            . ($other['promise_number'] ?: '#' . $other['id']) . "). Annulez-la d'abord.");
    }
}

// Projet de la promesse : envoyé par la page, sinon projectName de la fiche, sinon code unique
function resolvePromiseProject(PDO $pdo, array $data, ?array $existing, string $unitCode): ?int {
    if (!empty($data['project_id'])) return (int)$data['project_id'];
    if ($existing && !empty($existing['project_id'])) return (int)$existing['project_id'];
    $dj = (isset($data['details_json']) && is_array($data['details_json'])) ? $data['details_json'] : [];
    if (empty($dj['projectName']) && $existing) {
        $dj = json_decode((string)($existing['details_json'] ?? ''), true) ?: [];
    }
    if (!empty($dj['projectName'])) {
        $id = projectIdFromCode($pdo, (string)$dj['projectName']);
        if ($id) return $id;
    }
    return uniqueUnitProjectId($pdo, $unitCode);
}

// Garde l'ancienne valeur quand la page n'envoie pas le champ (ex. « Convertir en vente »)
function keepOr(array $data, array $existing, string $key, ?callable $cast = null) {
    if (!array_key_exists($key, $data)) return $existing[$key];
    return $cast ? $cast($data[$key]) : $data[$key];
}

try {
    $pdo->beginTransaction();

    if ($promise_id > 0) {
        // ── UPDATE ──────────────────────────────────────────
        $stmt = $pdo->prepare("SELECT * FROM promises WHERE id = ?");
        $stmt->execute([$promise_id]);
        $existing = $stmt->fetch();
        if (!$existing) { throw new RuntimeException('Promesse introuvable'); }
        if ($existing['is_locked']) { throw new RuntimeException('Cette promesse est verrouillée et ne peut pas être modifiée.'); }

        $fields = [
            'client_id'        => $client_id,
            'unit_code'        => $unit_code,
            'project_id'       => resolvePromiseProject($pdo, $data, $existing, $unit_code),
            // Keep the existing codes when the page does not send them (e.g. "Convertir en vente")
            'parking_codes'    => array_key_exists('parking_codes', $data) ? $data['parking_codes'] : $existing['parking_codes'],
            'cellier_codes'    => array_key_exists('cellier_codes', $data) ? $data['cellier_codes'] : $existing['cellier_codes'],
            'sale_price'       => $sale_price,
            'advance_amount'   => keepOr($data, $existing, 'advance_amount', fn($v) => (float)$v),
            'extra_works'      => keepOr($data, $existing, 'extra_works', fn($v) => (float)$v),
            'vat_rate'         => keepOr($data, $existing, 'vat_rate', fn($v) => (float)($v ?? 19)),
            'payment_plan_type'=> keepOr($data, $existing, 'payment_plan_type'),
            'promise_date'     => keepOr($data, $existing, 'promise_date', fn($v) => $v ?: null),
            'signed_date'      => keepOr($data, $existing, 'signed_date', fn($v) => $v ?: null),
            'status'           => $data['status'] ?? $existing['status'],
            'details_json'     => (function() use ($data, $existing) {
                $base = [];
                if (!empty($existing['details_json'])) {
                    $base = json_decode($existing['details_json'], true) ?: [];
                }
                if (isset($data['details_json']) && is_array($data['details_json'])) {
                    $base = array_merge($base, $data['details_json']);
                }
                return json_encode($base, JSON_UNESCAPED_UNICODE);
            })(),
        ];

        // N1 : pas de 2e promesse en cours sur le même appartement
        $isActive  = in_array($fields['status'], ['draft','signed','converted_to_sale'], true);
        $wasActive = in_array($existing['status'], ['draft','signed','converted_to_sale'], true);
        $sameUnit  = strtoupper(trim((string)$existing['unit_code'])) === strtoupper(trim($unit_code))
                     && (int)$existing['project_id'] === (int)$fields['project_id'];
        if ($isActive && (!$wasActive || !$sameUnit)) {
            assertNoOtherActivePromise($pdo, $promise_id, $unit_code, $fields['project_id']);
        }

        // Auto-lock when converted to sale
        if ($fields['status'] === 'converted_to_sale') {
            $fields['is_locked'] = 1;
        }

        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $vals = array_values($fields);
        $vals[] = $promise_id;
        $pdo->prepare("UPDATE promises SET $set WHERE id = ?")->execute($vals);

        audit_log($pdo, $_SESSION['user_id'], 'promise', $promise_id, 'update', $existing, $fields);
        $djArr = isset($data['details_json']) && is_array($data['details_json']) ? $data['details_json'] : [];
        syncAccessoryPrices($pdo, $fields['project_id'], $djArr, (float)($data['vat_rate'] ?? 19));
        syncAccessoryStatus($pdo, $promise_id, $fields['project_id'], $unit_code,
            array_merge(parseCodes($fields['parking_codes']), parseCodes($fields['cellier_codes'])),
            array_merge(parseCodes($existing['parking_codes']), parseCodes($existing['cellier_codes'])),
            (string)$fields['status']);
        if ($fields['status'] === 'converted_to_sale') {
            markUnitSold($pdo, $unit_code, $fields['project_id']);
        }
        if ($fields['status'] === 'cancelled' && $wasActive) {
            freeUnitOnCancel($pdo, $promise_id, $unit_code, $fields['project_id']);
        }
        $pdo->commit();
        ob_clean();
        echo json_encode(['success' => true, 'id' => $promise_id], JSON_UNESCAPED_UNICODE);

    } else {
        // ── INSERT ──────────────────────────────────────────
        // Generate promise number: PV-YYYYMM-XXXX
        $stmt = $pdo->query("SELECT COUNT(*)+1 FROM promises WHERE YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW())");
        $seq  = (int)$stmt->fetchColumn();
        $number = 'PV-' . date('Ym') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

        $status = $data['status'] ?? 'draft';
        $is_locked = ($status === 'converted_to_sale') ? 1 : 0;
        $projectId = resolvePromiseProject($pdo, $data, null, $unit_code);
        if (in_array($status, ['draft','signed','converted_to_sale'], true)) {
            assertNoOtherActivePromise($pdo, 0, $unit_code, $projectId);
        }

        $stmt = $pdo->prepare("
            INSERT INTO promises
              (promise_number, client_id, project_id, unit_code, parking_codes, cellier_codes,
               sale_price, advance_amount, extra_works, vat_rate, payment_plan_type,
               promise_date, signed_date, status, is_locked, details_json, vente_id, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $number,
            $client_id,
            $projectId,
            $unit_code,
            $data['parking_codes'] ?? null,
            $data['cellier_codes'] ?? null,
            $sale_price,
            (float)($data['advance_amount'] ?? 0),
            (float)($data['extra_works'] ?? 0),
            (float)($data['vat_rate'] ?? 19),
            $data['payment_plan_type'] ?? null,
            $data['promise_date'] ?: null,
            $data['signed_date'] ?: null,
            $status,
            $is_locked,
            isset($data['details_json']) && is_array($data['details_json']) ? json_encode($data['details_json'], JSON_UNESCAPED_UNICODE) : null,
            $data['vente_id'] ?? null,
            $_SESSION['user_id'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        audit_log($pdo, $_SESSION['user_id'], 'promise', $newId, 'create', null, ['promise_number' => $number, 'unit_code' => $unit_code]);
        $djArr = isset($data['details_json']) && is_array($data['details_json']) ? $data['details_json'] : [];
        syncAccessoryPrices($pdo, $projectId, $djArr, (float)($data['vat_rate'] ?? 19));
        syncAccessoryStatus($pdo, $newId, $projectId, $unit_code,
            array_merge(parseCodes($data['parking_codes'] ?? ''), parseCodes($data['cellier_codes'] ?? '')),
            [], (string)$status);
        if ($status === 'converted_to_sale') {
            markUnitSold($pdo, $unit_code, $projectId);
        }
        $pdo->commit();
        ob_clean();
        echo json_encode(['success' => true, 'id' => $newId, 'promise_number' => $number], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
