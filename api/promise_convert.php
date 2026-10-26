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
if (!is_array($data)) {
    ob_clean(); http_response_code(400);
    echo json_encode(['error' => 'JSON invalide']); exit;
}

$promise_id = (int)($data['promise_id'] ?? 0);
if (!$promise_id) {
    ob_clean(); http_response_code(400);
    echo json_encode(['error' => 'promise_id requis']); exit;
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

function column_exists_local(PDO $pdo, string $table, string $col): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$table, $col]);
    return (bool)$s->fetchColumn();
}

/* ── Conversion principale ───────────────────────────────────────────────── */
function convertPromiseToSale(PDO $pdo, int $promiseId, int $userId): array {

    // ── 1. Charger la promesse ───────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT * FROM promises WHERE id = ?");
    $stmt->execute([$promiseId]);
    $promise = $stmt->fetch();

    if (!$promise) {
        throw new RuntimeException("Promesse #$promiseId introuvable.");
    }
    if ($promise['status'] === 'converted_to_sale') {
        throw new RuntimeException("Promesse #$promiseId déjà convertie en vente.");
    }
    if ($promise['is_locked']) {
        throw new RuntimeException("Promesse #$promiseId est verrouillée et ne peut pas être modifiée.");
    }

    $pdo->beginTransaction();

    try {
        $unit_code  = $promise['unit_code'];
        $client_id  = (int)$promise['client_id'];
        $project_id = $promise['project_id'] ? (int)$promise['project_id'] : null;

        // Extraire les prix depuis details_json si disponibles
        $dj = [];
        if (!empty($promise['details_json'])) {
            $dj = json_decode($promise['details_json'], true) ?: [];
        }
        $sale_price_ttc   = (float)($promise['sale_price']        ?? 0);
        $total_ht         = (float)($promise['total_ht']           ?? $dj['totalHt']      ?? 0);
        $parking_ttc      = (float)($promise['parking_price_ht']   ?? $dj['parkingTtc']   ?? 0);
        $cellier_ttc      = (float)($promise['cellier_price_ht']   ?? $dj['cellierTtc']   ?? 0);
        $discount_apt     = (float)($promise['discount_apartment']  ?? $dj['discountApartment'] ?? 0);
        $discount_prk     = (float)($promise['discount_parking']    ?? $dj['discountParking']    ?? 0);
        $discount_cel     = (float)($promise['discount_cellier']    ?? $dj['discountCellier']    ?? 0);
        $extra_works      = (float)($promise['extra_works']         ?? 0);
        $finance_mode     = $promise['finance_mode'] ?? $dj['financeMode'] ?? null;
        $parking_codes    = $promise['parking_codes'] ?? null;
        $cellier_codes    = $promise['cellier_codes'] ?? null;

        // ── 2. Créer ou mettre à jour la vente ───────────────────────────
        $vente_id = $promise['vente_id'] ? (int)$promise['vente_id'] : null;

        $vente_fields = [
            'unit_code'         => $unit_code,
            'client_id'         => $client_id,
            'project_id'        => $project_id,
            'objet'             => "Vente convertie depuis promesse #{$promise['promise_number']}",
            'prix_ttc'          => $sale_price_ttc,
            'prix_total_ttc'    => $sale_price_ttc,
            'parking'           => $parking_codes,
            'cellier'           => $cellier_codes,
            'discount_apartment'=> $discount_apt,
            'discount_parking'  => $discount_prk,
            'discount_cellier'  => $discount_cel,
            'extra_works'       => $extra_works,
            'finance_mode'      => $finance_mode,
            'details_json'      => $promise['details_json'],
        ];
        // Prix HT si la colonne existe
        if (column_exists_local($pdo, 'ventes', 'prix_ht')) {
            $vente_fields['prix_ht'] = $total_ht;
        }

        if ($vente_id) {
            // Mettre à jour la vente existante
            $setClauses = [];
            $setVals    = [];
            foreach ($vente_fields as $col => $val) {
                // Ne mettre à jour que les colonnes qui existent
                if (column_exists_local($pdo, 'ventes', $col)) {
                    $setClauses[] = "`$col` = ?";
                    $setVals[]    = $val;
                }
            }
            if ($setClauses) {
                $setVals[] = $vente_id;
                $pdo->prepare("UPDATE ventes SET " . implode(', ', $setClauses) . " WHERE id = ?")
                    ->execute($setVals);
            }
        } else {
            // Créer une nouvelle vente
            $cols = []; $placeholders = []; $vals = [];
            foreach ($vente_fields as $col => $val) {
                if (column_exists_local($pdo, 'ventes', $col)) {
                    $cols[]         = "`$col`";
                    $placeholders[] = '?';
                    $vals[]         = $val;
                }
            }
            // date_vente si elle existe
            if (column_exists_local($pdo, 'ventes', 'date_vente')) {
                $cols[]         = '`date_vente`';
                $placeholders[] = 'NOW()';
            }
            $pdo->prepare(
                "INSERT INTO ventes (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")"
            )->execute($vals);
            $vente_id = (int)$pdo->lastInsertId();
        }

        // ── 3. Verrouiller la promesse ────────────────────────────────────
        $lockedAtCol = column_exists_local($pdo, 'promises', 'locked_at') ? 'locked_at=NOW(),' : '';
        $pdo->prepare("
            UPDATE promises
            SET status='converted_to_sale', is_locked=1, {$lockedAtCol} vente_id=?, updated_at=NOW()
            WHERE id=?
        ")->execute([$vente_id, $promiseId]);

        // ── 4. Passer l'unité principale à 'Vendu' ───────────────────────
        $stmt = $pdo->prepare("
            UPDATE units u
            JOIN projects pj ON pj.id = u.project_id
            SET u.status_code = 'V'
            WHERE u.ref_code = ?
        ");
        $stmt->execute([$unit_code]);
        $units_updated = $stmt->rowCount();

        // ── 5. Passer les accessoires (parkings + celliers) à 'Vendu' ────
        $accessory_codes = [];
        foreach ([$parking_codes, $cellier_codes] as $raw) {
            if ($raw) {
                foreach (array_filter(array_map('trim', explode(',', $raw))) as $code) {
                    $accessory_codes[] = $code;
                }
            }
        }
        $accessories_updated = 0;
        if ($accessory_codes && $project_id) {
            $in = implode(',', array_fill(0, count($accessory_codes), '?'));
            $params = array_merge($accessory_codes, [$project_id]);
            $s = $pdo->prepare("UPDATE unit_accessoires SET status_code='V' WHERE code IN ($in) AND project_id=?");
            $s->execute($params);
            $accessories_updated = $s->rowCount();
        }

        // ── 6. Audit ──────────────────────────────────────────────────────
        audit_log($pdo, $userId, 'promise', $promiseId, 'converted_to_sale',
            ['status' => $promise['status'], 'is_locked' => $promise['is_locked']],
            ['status' => 'converted_to_sale', 'is_locked' => 1, 'vente_id' => $vente_id]
        );

        $pdo->commit();

        return [
            'success'              => true,
            'promise_id'           => $promiseId,
            'vente_id'             => $vente_id,
            'unit_code'            => $unit_code,
            'units_marked_sold'    => $units_updated,
            'accessories_marked_sold' => $accessories_updated,
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* ── Point d'entrée HTTP ─────────────────────────────────────────────────── */
try {
    $result = convertPromiseToSale($pdo, $promise_id, (int)$_SESSION['user_id']);
    ob_clean();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
