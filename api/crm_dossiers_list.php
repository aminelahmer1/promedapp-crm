<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$q       = trim((string)($_GET['q']       ?? ''));
$status  = trim((string)($_GET['status']  ?? ''));
$project = strtoupper(trim((string)($_GET['project'] ?? '')));
$limit   = min((int)($_GET['limit']  ?? 40), 200);
$offset  = (int)($_GET['offset'] ?? 0);

try {
    $where  = ['1=1'];
    $params = [];

    if ($q !== '') {
        $like = "%$q%";
        $where[] = '(d.dossier_number LIKE ? OR c.nom LIKE ? OR c.tel LIKE ? OR d.unit_code LIKE ?)';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
    if ($status !== '') {
        $where[] = 'd.status = ?';
        $params[] = $status;
    }
    if ($project !== '') {
        $where[] = 'UPPER(pj.code) = ?';
        $params[] = $project;
    }

    $wSql = implode(' AND ', $where);

    $sql = "
        SELECT
            d.id, d.dossier_number, d.status, d.unit_code, d.budget,
            d.interest_level, d.source, d.created_at, d.reservation_date,
            d.reservation_expires_at, d.sold_date,
            c.id AS client_id, c.nom AS client_nom, c.tel AS client_tel,
            pj.id AS project_id, pj.code AS project_code, pj.nom AS project_nom,
            u.full_name AS assigned_nom,
            p.id AS promise_id, p.promise_number, p.status AS promise_status,
            p.sale_price,
            ps.paid_amount, ps.remaining_amount, ps.progress_percent,
            ni.due_date AS next_due, ni.expected_amount AS next_amount
        FROM crm_dossiers d
        LEFT JOIN clients c ON c.id = d.client_id
        LEFT JOIN projects pj ON pj.id = d.project_id
        LEFT JOIN users u ON u.id = d.assigned_to
        LEFT JOIN promises p ON p.dossier_id = d.id
        LEFT JOIN payment_schedules ps ON ps.promise_id = p.id
        LEFT JOIN payment_installments ni ON ni.promise_id = p.id
            AND ni.status IN ('pending','partially_paid')
            AND ni.due_date = (
                SELECT MIN(ii.due_date)
                FROM payment_installments ii
                WHERE ii.promise_id = p.id AND ii.status IN ('pending','partially_paid')
            )
        WHERE $wSql
        ORDER BY d.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $dossiers = $stmt->fetchAll();

    $cntSql = "
        SELECT COUNT(DISTINCT d.id)
        FROM crm_dossiers d
        LEFT JOIN clients c ON c.id = d.client_id
        LEFT JOIN projects pj ON pj.id = d.project_id
        WHERE $wSql
    ";
    $cntStmt = $pdo->prepare($cntSql);
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    echo json_encode(['dossiers' => $dossiers, 'total' => $total], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
