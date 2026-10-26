<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$q          = trim((string)($_GET['q']          ?? ''));
$status     = trim((string)($_GET['status']     ?? ''));  // crm_status
$dossStatus = trim((string)($_GET['doss_status']?? ''));  // crm_dossiers.status
$project    = trim((string)($_GET['project']    ?? ''));
$commercial = (int)($_GET['commercial'] ?? 0);
$priority   = trim((string)($_GET['priority']   ?? ''));
$ptype      = trim((string)($_GET['property_type']?? ''));
$limit      = min((int)($_GET['limit']  ?? 40), 200);
$offset     = (int)($_GET['offset'] ?? 0);

// Detect new columns
$hasCrmStatus = false; $hasInterestCol = false;
try { $pdo->query("SELECT crm_status FROM clients LIMIT 0");    $hasCrmStatus   = true; } catch(Throwable $e){}
try { $pdo->query("SELECT interest_level FROM clients LIMIT 0");$hasInterestCol = true; } catch(Throwable $e){}

// Detect crm_activities column names
$actDateCol = 'created_at';
foreach (['activity_date','date','created_at'] as $c) {
    try { $pdo->query("SELECT `$c` FROM crm_activities LIMIT 0"); $actDateCol = $c; break; } catch(Throwable $e){}
}
$hasActClientId = false;
try { $pdo->query("SELECT client_id FROM crm_activities LIMIT 0"); $hasActClientId = true; } catch(Throwable $e){}

try {
    $where  = ['1=1'];
    $params = [];

    if ($q !== '') {
        $like = "%$q%";
        $where[] = '(c.nom LIKE ? OR c.tel LIKE ? OR c.email LIKE ? OR c.cin LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    // CRM status filter (client-level)
    if ($status !== '' && $hasCrmStatus) {
        $where[] = 'c.crm_status = ?';
        $params[] = $status;
    }
    // Dossier status filter
    if ($dossStatus !== '') {
        $where[] = 'd.status = ?';
        $params[] = $dossStatus;
    }
    if ($project !== '') {
        $where[] = '(pj.code = ? OR pjn.code = ?)';
        array_push($params, $project, $project);
    }
    if ($commercial > 0) {
        $where[] = '(c.assigned_to = ? OR d.assigned_to = ?)';
        array_push($params, $commercial, $commercial);
    }
    if ($priority !== '') {
        $where[] = $hasInterestCol ? '(c.interest_level = ? OR d.interest_level = ?)' : 'd.interest_level = ?';
        if ($hasInterestCol) array_push($params, $priority, $priority);
        else $params[] = $priority;
    }
    if ($ptype !== '') {
        $where[] = 'n.property_type = ?';
        $params[] = $ptype;
    }

    $wSql = implode(' AND ', $where);

    $crmStatusSel  = $hasCrmStatus   ? 'c.crm_status'   : "'' AS crm_status";
    $interestSel   = $hasInterestCol ? 'COALESCE(c.interest_level, d.interest_level)' : 'd.interest_level';
    $waCol         = '';
    try { $pdo->query("SELECT whatsapp FROM clients LIMIT 0"); $waCol = ', c.whatsapp'; } catch(Throwable $e){}
    $nfCol = '';
    try { $pdo->query("SELECT next_followup_at FROM clients LIMIT 0"); $nfCol = ', c.next_followup_at'; } catch(Throwable $e){}

    $sql = "
        SELECT
            c.id, c.nom, c.tel, c.email, c.cin, c.source
            $waCol $nfCol,
            $crmStatusSel,
            $interestSel AS interest_level,
            d.id AS dossier_id, d.dossier_number, d.status AS dossier_status, d.unit_code,
            pj.code AS project_code,
            u.full_name AS commercial,
            n.property_type,
            act.last_contact,
            DATEDIFF(CURDATE(), act.last_contact) AS days_since_contact,
            act.last_type AS last_activity_type
        FROM clients c
        LEFT JOIN crm_dossiers d ON d.id = (
            SELECT MAX(d2.id) FROM crm_dossiers d2 WHERE d2.client_id = c.id
        )
        LEFT JOIN projects pj ON pj.id = d.project_id
        LEFT JOIN users u ON u.id = COALESCE(c.assigned_to, d.assigned_to)
        LEFT JOIN crm_client_needs n ON n.client_id = c.id AND n.id = (
            SELECT MAX(n2.id) FROM crm_client_needs n2 WHERE n2.client_id = c.id
        )
        LEFT JOIN projects pjn ON pjn.id = n.project_id
        LEFT JOIN (
            SELECT dossier_id,
                   MAX(`$actDateCol`) AS last_contact,
                   SUBSTRING_INDEX(GROUP_CONCAT(activity_type ORDER BY `$actDateCol` DESC), ',', 1) AS last_type
            FROM crm_activities
            WHERE dossier_id IS NOT NULL
            GROUP BY dossier_id
        ) act ON act.dossier_id = d.id
        WHERE $wSql
        ORDER BY act.last_contact IS NULL ASC, act.last_contact DESC, c.id DESC
        LIMIT $limit OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clients = $stmt->fetchAll();

    // Count
    $cntSql = "
        SELECT COUNT(DISTINCT c.id)
        FROM clients c
        LEFT JOIN crm_dossiers d ON d.id = (SELECT MAX(d2.id) FROM crm_dossiers d2 WHERE d2.client_id = c.id)
        LEFT JOIN projects pj ON pj.id = d.project_id
        LEFT JOIN crm_client_needs n ON n.client_id = c.id AND n.id = (SELECT MAX(n2.id) FROM crm_client_needs n2 WHERE n2.client_id = c.id)
        LEFT JOIN projects pjn ON pjn.id = n.project_id
        WHERE $wSql
    ";
    $cntStmt = $pdo->prepare($cntSql);
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    // Stats
    $hot = 0; $reserved = 0; $no_contact = 0; $visits_today = 0;
    try {
        $icol = $hasInterestCol ? "COALESCE(c.interest_level, d.interest_level)" : "d.interest_level";
        $hot = (int)$pdo->query("SELECT COUNT(DISTINCT c.id) FROM clients c LEFT JOIN crm_dossiers d ON d.id=(SELECT MAX(d2.id) FROM crm_dossiers d2 WHERE d2.client_id=c.id) WHERE $icol IN ('hot','vip')")->fetchColumn();
    } catch(Throwable $e){}
    try {
        $reserved = (int)$pdo->query("SELECT COUNT(*) FROM crm_dossiers WHERE status IN ('reserved','promise_signed','promise_draft')")->fetchColumn();
    } catch(Throwable $e){}
    try {
        $no_contact = (int)$pdo->query("
            SELECT COUNT(*) FROM clients c
            LEFT JOIN crm_dossiers d ON d.id=(SELECT MAX(d2.id) FROM crm_dossiers d2 WHERE d2.client_id=c.id)
            LEFT JOIN (SELECT dossier_id,MAX(`$actDateCol`) AS lc FROM crm_activities GROUP BY dossier_id) a ON a.dossier_id=d.id
            WHERE (a.lc IS NULL OR a.lc < DATE_SUB(NOW(), INTERVAL 14 DAY))
              AND (d.status IS NULL OR d.status NOT IN ('cancelled','closed','paid'))
        ")->fetchColumn();
    } catch(Throwable $e){}
    try {
        $visits_today = (int)$pdo->query("SELECT COUNT(*) FROM crm_visits WHERE visit_date=CURDATE() AND status='planned'")->fetchColumn();
    } catch(Throwable $e){}

    echo json_encode([
        'clients' => $clients,
        'total'   => $total,
        'stats'   => ['hot'=>$hot,'reserved'=>$reserved,'no_contact'=>$no_contact,'visits_today'=>$visits_today],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
