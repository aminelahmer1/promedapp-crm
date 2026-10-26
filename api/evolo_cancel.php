<?php
// EVOLO – Liste des prix : « Disponible » sur un appartement réservé ou vendu.
// Annule la promesse en cours (statut « cancelled »), libère ses parkings / celliers
// et remet l'appartement en « D ». Une promesse déjà convertie en vente est refusée.
ini_set('display_errors', '0');
error_reporting(0);
ob_start();
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/reservation_lib.php';
header('Content-Type: application/json; charset=utf-8');

function out(int $code, array $body): void {
    ob_clean();
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) out(401, ['error' => 'Non authentifié']);

$raw  = json_decode(file_get_contents('php://input'), true);
$code = trim((string)($raw['code'] ?? ''));
if ($code === '') out(400, ['error' => 'Référence manquante']);

$projectId = projectIdFromCode($pdo, 'EVOLO');
if (!$projectId) out(400, ['error' => 'Projet EVOLO introuvable']);

function parseCodes($str): array {
    return array_values(array_filter(array_map(fn($c) => strtoupper(str_replace(' ', '', $c)), explode(',', (string)$str))));
}

try {
    $pdo->beginTransaction();

    $u = $pdo->prepare("SELECT id, status_code FROM units WHERE project_id = ? AND UPPER(TRIM(ref_code)) = UPPER(?) LIMIT 1");
    $u->execute([$projectId, $code]);
    $unit = $u->fetch(PDO::FETCH_ASSOC);
    if (!$unit) throw new RuntimeException("Appartement $code introuvable dans EVOLO");

    $promise = findActivePromiseForUnit($pdo, $projectId, $code);
    $number = null;
    if ($promise) {
        $full = $pdo->prepare("SELECT * FROM promises WHERE id = ?");
        $full->execute([(int)$promise['id']]);
        $promise = $full->fetch(PDO::FETCH_ASSOC);
        $number = $promise['promise_number'] ?: '#' . $promise['id'];
        if ($promise['status'] === 'converted_to_sale' || !empty($promise['is_locked'])) {
            throw new DomainException("La promesse $number est déjà convertie en vente : elle ne peut pas être annulée depuis la liste des prix.");
        }

        $pdo->prepare("UPDATE promises SET status = 'cancelled' WHERE id = ?")->execute([(int)$promise['id']]);

        // Parkings / celliers de la promesse : libérés sauf s'ils sont dans une autre promesse en cours
        // ou vendus avec un autre appartement
        $acc = $pdo->prepare("UPDATE unit_accessoires SET status_code = 'D', reserved_at = NULL
                              WHERE project_id = ? AND UPPER(REPLACE(code,' ','')) = ? AND status_code IN ('R','V')");
        foreach (array_merge(parseCodes($promise['parking_codes'] ?? ''), parseCodes($promise['cellier_codes'] ?? '')) as $ac) {
            if (findActivePromiseForAccessory($pdo, $projectId, $ac, (int)$promise['id'])) continue;
            if (accessorySoldWithOtherUnit($pdo, $projectId, $ac, $code)) continue;
            $acc->execute([$projectId, $ac]);
        }
    }

    $pdo->prepare("UPDATE units SET status_code = 'D', reserved_at = NULL WHERE id = ?")->execute([(int)$unit['id']]);

    $pdo->commit();
    out(200, ['success' => true, 'promise_number' => $number]);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    out(409, ['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    out(400, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    out(500, ['error' => 'Erreur serveur : ' . $e->getMessage()]);
}
