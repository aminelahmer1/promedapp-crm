<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$q       = trim((string)($_GET['q']       ?? ''));
$project = trim((string)($_GET['project'] ?? ''));
$limit   = min((int)($_GET['limit'] ?? 200), 500);

try {
    // Probe which CRM columns exist on clients
    $cCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM clients")->fetchAll() as $r) $cCols[] = $r['Field'];
    $has = fn($c) => in_array($c, $cCols);

    $hasCrmStatus  = $has('crm_status');
    $hasInterest   = $has('interest_level');
    $hasBudgetMin  = $has('budget_min');
    $hasBudgetMax  = $has('budget_max');
    $hasLastCon    = $has('last_contact_at');
    $hasNextFu     = $has('next_followup_at');

    // Build SELECT extras
    $selExtras = '';
    if ($hasCrmStatus) $selExtras .= ', c.crm_status AS status';
    else               $selExtras .= ", 'new' AS status";
    if ($hasInterest)  $selExtras .= ', c.interest_level';
    else               $selExtras .= ", 'medium' AS interest_level";
    if ($hasBudgetMin) $selExtras .= ', c.budget_min';
    if ($hasBudgetMax) $selExtras .= ', c.budget_max';
    if ($hasLastCon)   $selExtras .= ', c.last_contact_at';
    if ($hasNextFu)    $selExtras .= ', c.next_followup_at';

    // Build WHERE
    $where  = ['1=1'];
    $params = [];

    if ($q !== '') {
        $like = "%$q%";
        $where[] = '(c.nom LIKE ? OR c.tel LIKE ? OR c.email LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if ($project !== '') {
        $where[] = 'pj.code = ?';
        $params[] = $project;
    }
    if ($hasCrmStatus) {
        $where[] = "c.crm_status NOT IN ('sold')";
    }

    $wSql    = implode(' AND ', $where);
    $orderBy = $hasLastCon ? 'c.last_contact_at IS NULL ASC, c.last_contact_at DESC' : 'c.id DESC';

    $sql = "
        SELECT c.id, c.nom, c.tel, c.email
               $selExtras,
               n.property_type, n.surface_min, n.surface_max,
               pj.code AS project_code,
               u.full_name AS commercial,
               d.id AS dossier_id, d.dossier_number, d.unit_code
        FROM clients c
        LEFT JOIN crm_client_needs n  ON n.client_id = c.id
            AND n.id = (SELECT MAX(n2.id) FROM crm_client_needs n2 WHERE n2.client_id = c.id)
        LEFT JOIN projects pj ON pj.id = n.project_id
        LEFT JOIN users u     ON u.id  = c.assigned_to
        LEFT JOIN crm_dossiers d ON d.id = (SELECT MAX(d2.id) FROM crm_dossiers d2 WHERE d2.client_id = c.id)
        WHERE $wSql
        ORDER BY $orderBy
        LIMIT $limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clients = $stmt->fetchAll();

    echo json_encode(['clients' => $clients, 'total' => count($clients)], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
