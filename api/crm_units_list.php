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

/*
 * Mapping colonnes réelles (table units) :
 *   ref_code       → unit_code
 *   type           → property_type
 *   price_ttc_sale → price  (fallback price_ht_sale)
 *   status_code    → status
 *   floor_name     → floor
 */

$project  = trim((string)($_GET['project']   ?? ''));
$status   = trim((string)($_GET['status']    ?? ''));
$type     = trim((string)($_GET['type']      ?? ''));
$q        = trim((string)($_GET['q']         ?? ''));
$unitCode = trim((string)($_GET['unit_code'] ?? ''));

try {
    /* ── Filtres ─────────────────────────────────────────────────────── */
    $where  = ['u.operation_mode = ?'];
    $params = ['sale'];

    if ($project !== '') { $where[] = 'pj.code = ?';        $params[] = $project; }
    if ($status  !== '') { $where[] = 'u.status_code = ?';  $params[] = $status; }
    if ($type    !== '') { $where[] = 'u.type = ?';         $params[] = $type; }
    if ($q       !== '') {
        $like    = "%$q%";
        $where[] = '(u.ref_code LIKE ? OR pj.code LIKE ? OR pj.nom LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $wSql = implode(' AND ', $where);

    /* ── Requête principale ──────────────────────────────────────────── */
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.ref_code                                      AS unit_code,
            u.type                                          AS property_type,
            u.floor_name                                    AS floor,
            u.block_name,
            u.surface,
            u.total_surface,
            COALESCE(u.price_ttc_sale, u.price_ht_sale, 0) AS price,
            u.price_ht_sale,
            u.price_ttc_sale,
            u.status_code                                   AS status,
            u.project_id,
            pj.code                                         AS project_code,
            pj.nom                                          AS project_name,
            COUNT(DISTINCT cu.client_id)                    AS interested_count,
            COUNT(DISTINCT d.id)                            AS dossier_count
        FROM units u
        LEFT JOIN projects pj        ON pj.id       = u.project_id
        LEFT JOIN crm_client_units cu ON cu.unit_code = u.ref_code COLLATE utf8mb4_0900_ai_ci
        LEFT JOIN crm_dossiers d      ON d.unit_code  = u.ref_code COLLATE utf8mb4_0900_ai_ci
                                     AND d.status NOT IN ('cancelled','closed')
        WHERE $wSql
        GROUP BY u.id,
                 u.ref_code, u.type, u.floor_name, u.block_name,
                 u.surface, u.total_surface,
                 u.price_ht_sale, u.price_ttc_sale, u.status_code,
                 u.project_id, pj.code, pj.nom
        ORDER BY pj.code, u.floor_name, u.ref_code
        LIMIT 500
    ");
    $stmt->execute($params);
    $units = $stmt->fetchAll();

    /* ── Clients intéressés pour une unité précise ───────────────────── */
    $clientsForUnit = [];
    if ($unitCode !== '') {
        $cStmt = $pdo->prepare("
            SELECT
                c.id,
                c.nom,
                c.tel,
                c.crm_status        AS status,
                c.interest_level,
                c.last_contact_date,
                cu.interest_level   AS unit_interest,
                cu.is_favorite,
                cu.notes            AS unit_notes
            FROM crm_client_units cu
            JOIN clients c ON c.id = cu.client_id
            WHERE cu.unit_code = ?
            ORDER BY cu.is_favorite DESC, c.last_contact_date DESC
        ");
        $cStmt->execute([$unitCode]);
        $clientsForUnit = $cStmt->fetchAll();
    }

    ob_clean();
    echo json_encode([
        'units'            => $units,
        'clients_for_unit' => $clientsForUnit,
        'total'            => count($units),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
