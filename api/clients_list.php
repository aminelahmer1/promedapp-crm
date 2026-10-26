<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$search  = trim((string)($_GET['q'] ?? ''));
$project = strtoupper(trim((string)($_GET['project'] ?? '')));
$limit   = min((int)($_GET['limit'] ?? 100), 500);
$offset  = (int)($_GET['offset'] ?? 0);

try {
    $where = ['1=1'];
    $params = [];

    if ($search !== '') {
        $where[] = '(c.nom LIKE ? OR c.tel LIKE ? OR c.email LIKE ? OR c.cin LIKE ?)';
        $like = "%$search%";
        $params = array_merge($params, [$like, $like, $like, $like]);
    }

    $projectJoin = '';
    if ($project !== '') {
        $projectJoin = 'JOIN promises pm ON pm.client_id = c.id JOIN projects pj ON pj.id = pm.project_id AND UPPER(pj.code) = ?';
        $params[] = $project;
    }

    $sql = "
        SELECT
            c.id,
            c.nom,
            c.tel,
            c.email,
            c.cin,
            c.address,
            c.nationality,
            c.source,
            c.assigned_to,
            c.created_at,
            COUNT(DISTINCT pm2.id)  AS promise_count,
            MAX(pm2.status)         AS last_promise_status,
            SUM(ps.paid_amount)     AS total_paid,
            SUM(ps.remaining_amount) AS total_remaining
        FROM clients c
        $projectJoin
        LEFT JOIN promises pm2 ON pm2.client_id = c.id
        LEFT JOIN payment_schedules ps ON ps.client_id = c.id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY c.id
        ORDER BY c.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clients = $stmt->fetchAll();

    // total count
    $countSql = "SELECT COUNT(DISTINCT c.id) FROM clients c $projectJoin WHERE " . implode(' AND ', $where);
    $countParams = $params;
    $stmt2 = $pdo->prepare($countSql);
    $stmt2->execute($countParams);
    $total = (int)$stmt2->fetchColumn();

    echo json_encode(['clients' => $clients, 'total' => $total], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
