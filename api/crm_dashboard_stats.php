<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

function tryFetch(PDO $pdo, string $sql, array $p = []): array {
    try { $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
    catch(Throwable $e){ return []; }
}
function tryScalar(PDO $pdo, string $sql, array $p = [], $def = 0) {
    try { $s=$pdo->prepare($sql); $s->execute($p); $v=$s->fetchColumn(); return $v!==false?$v:$def; }
    catch(Throwable $e){ return $def; }
}
function probeCol(PDO $pdo, string $tbl, array $cols): string {
    foreach($cols as $c){ try{ $pdo->query("SELECT `$c` FROM `$tbl` LIMIT 0"); return $c; }catch(Throwable $e){} }
    return $cols[0];
}

// ── 1. Pipeline statuts ──────────────────────────────────────────────────────
$byStatus=[]; $totalActive=0;
foreach(tryFetch($pdo,"SELECT status,COUNT(*) AS cnt FROM crm_dossiers GROUP BY status") as $r){
    $byStatus[$r['status']]=(int)$r['cnt'];
    if(!in_array($r['status'],['cancelled','closed'])) $totalActive+=(int)$r['cnt'];
}

// ── 2. Promesses signées ce mois ────────────────────────────────────────────
$signedThisMonth=(int)tryScalar($pdo,"
    SELECT COUNT(*) FROM promises
    WHERE status='signed' AND YEAR(signed_date)=YEAR(CURDATE()) AND MONTH(signed_date)=MONTH(CURDATE())
");

// ── 3. Pipeline valeur ───────────────────────────────────────────────────────
$priceCol = probeCol($pdo,'promises',['sale_price','total_ttc']);
$pipelineValue=(float)tryScalar($pdo,"SELECT COALESCE(SUM(`$priceCol`),0) FROM promises WHERE status NOT IN ('cancelled')");

// ── 4. Financier ─────────────────────────────────────────────────────────────
$payCol  = probeCol($pdo,'payments',['montant_paye','amount']);
$datCol  = probeCol($pdo,'payments',['date_paiement','payment_date']);
$remCol  = probeCol($pdo,'payment_installments',['remaining_amount','amount']);
$expCol  = probeCol($pdo,'payment_installments',['expected_amount','amount']);

$monthPaid = (float)tryScalar($pdo,"SELECT COALESCE(SUM(`$payCol`),0) FROM payments WHERE YEAR(`$datCol`)=YEAR(CURDATE()) AND MONTH(`$datCol`)=MONTH(CURDATE())");
$monthDue  = (float)tryScalar($pdo,"SELECT COALESCE(SUM(`$expCol`),0) FROM payment_installments WHERE YEAR(due_date)=YEAR(CURDATE()) AND MONTH(due_date)=MONTH(CURDATE())");

$overdueRows = tryFetch($pdo,"SELECT COUNT(*) AS cnt, COALESCE(SUM(`$remCol`),0) AS amt FROM payment_installments WHERE status IN ('pending','partially_paid') AND due_date < CURDATE()");
$overdueCount  = (int)($overdueRows[0]['cnt'] ?? 0);
$overdueAmt    = (float)($overdueRows[0]['amt'] ?? 0);
$totalRemainder= (float)tryScalar($pdo,"SELECT COALESCE(SUM(remaining_amount),0) FROM payment_schedules WHERE status NOT IN ('paid','cancelled')");

// ── 5. À faire aujourd'hui ───────────────────────────────────────────────────
$todo = ['calls'=>0,'whatsapp'=>0,'meetings'=>0,'emails'=>0,'tasks'=>0,'total'=>0,'items'=>[]];
$actMap = ['call'=>'calls','whatsapp'=>'whatsapp','meeting'=>'meetings','email'=>'emails','task'=>'tasks'];
foreach(tryFetch($pdo,"SELECT activity_type,COUNT(*) AS cnt FROM crm_activities WHERE DATE(due_date)=CURDATE() AND completed_at IS NULL GROUP BY activity_type") as $r){
    $k = $actMap[$r['activity_type']] ?? null;
    if($k) $todo[$k]=(int)$r['cnt'];
    $todo['total']+=(int)$r['cnt'];
}
$todo['items'] = tryFetch($pdo,"
    SELECT a.id, a.activity_type, a.subject, a.due_date,
           c.nom AS client_nom, c.tel AS client_tel,
           d.dossier_number, d.id AS dossier_id
    FROM crm_activities a
    LEFT JOIN crm_dossiers d ON d.id=a.dossier_id
    LEFT JOIN clients c ON c.id=COALESCE(d.client_id, a.client_id)
    WHERE DATE(a.due_date)=CURDATE() AND a.completed_at IS NULL
    ORDER BY a.due_date ASC LIMIT 8
");

// ── 6. Alertes commerciales ──────────────────────────────────────────────────
$alerts = ['expiring'=>[],'draft_promises'=>0,'signed_unlocked'=>0,'sold_no_schedule'=>0,'hot_no_followup'=>0,'total'=>0];

$alerts['expiring'] = tryFetch($pdo,"
    SELECT d.id, d.dossier_number, c.nom AS client_nom, c.tel AS client_tel,
           d.reservation_expires_at,
           DATEDIFF(d.reservation_expires_at, CURDATE()) AS days_left
    FROM crm_dossiers d
    LEFT JOIN clients c ON c.id=d.client_id
    WHERE d.status='reserved'
      AND d.reservation_expires_at IS NOT NULL
      AND d.reservation_expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ORDER BY d.reservation_expires_at ASC LIMIT 5
");
$alerts['draft_promises']  = (int)tryScalar($pdo,"SELECT COUNT(*) FROM promises WHERE status='draft' AND created_at < DATE_SUB(NOW(),INTERVAL 7 DAY)");
$alerts['signed_unlocked'] = (int)tryScalar($pdo,"SELECT COUNT(*) FROM promises WHERE status='signed' AND is_locked=0");
$alerts['sold_no_schedule']= (int)tryScalar($pdo,"
    SELECT COUNT(*) FROM crm_dossiers d
    LEFT JOIN payment_schedules ps ON ps.dossier_id=d.id
    WHERE d.status='sold' AND ps.id IS NULL
");
$alerts['hot_no_followup'] = (int)tryScalar($pdo,"
    SELECT COUNT(*) FROM crm_dossiers d
    LEFT JOIN crm_activities a ON a.dossier_id=d.id
        AND a.created_at > DATE_SUB(NOW(),INTERVAL 7 DAY)
    WHERE d.interest_level='hot'
      AND d.status NOT IN ('sold','paid','closed','cancelled')
      AND a.id IS NULL
");
$alerts['total'] = count($alerts['expiring']) + $alerts['draft_promises']
                 + $alerts['signed_unlocked'] + $alerts['sold_no_schedule']
                 + $alerts['hot_no_followup'];

// ── 7. Dossiers récents enrichis ─────────────────────────────────────────────
$recent = tryFetch($pdo,"
    SELECT d.id, d.dossier_number, d.status, d.interest_level, d.created_at,
           c.nom AS client_nom, c.tel AS client_tel,
           pj.code AS project_code, d.unit_code,
           p.`$priceCol` AS total_price,
           ps.paid_amount, ps.remaining_amount,
           u.full_name AS assigned_nom
    FROM crm_dossiers d
    LEFT JOIN clients c   ON c.id  = d.client_id
    LEFT JOIN projects pj ON pj.id = d.project_id
    LEFT JOIN users u     ON u.id  = d.assigned_to
    LEFT JOIN (
        SELECT dossier_id, MAX(id) AS max_id
        FROM promises WHERE status != 'cancelled'
        GROUP BY dossier_id
    ) pm ON pm.dossier_id = d.id
    LEFT JOIN promises p          ON p.id  = pm.max_id
    LEFT JOIN payment_schedules ps ON ps.promise_id = p.id
    ORDER BY d.created_at DESC LIMIT 20
");

// ── Hot clients ──────────────────────────────────────────────────────────────
$hotClients = tryFetch($pdo, "
    SELECT c.id, c.nom, c.tel,
           COALESCE(c.interest_level, d.interest_level, 'medium') AS interest_level
    FROM clients c
    LEFT JOIN crm_dossiers d ON d.id = (SELECT MAX(d2.id) FROM crm_dossiers d2 WHERE d2.client_id = c.id)
    WHERE COALESCE(c.interest_level, d.interest_level) IN ('hot','vip','high')
      AND COALESCE(c.crm_status, d.status) NOT IN ('lost','cancelled','closed','sold')
    ORDER BY FIELD(COALESCE(c.interest_level, d.interest_level),'vip','hot','high') ASC,
             c.last_contact_at IS NULL DESC
    LIMIT 10
");

// ── Visits today ─────────────────────────────────────────────────────────────
$visitsToday = tryFetch($pdo, "
    SELECT v.*, c.nom, c.tel, u.full_name AS commercial_nom,
           pj.code AS project_code
    FROM crm_visits v
    JOIN clients c ON c.id = v.client_id
    LEFT JOIN users u ON u.id = v.commercial_id
    LEFT JOIN projects pj ON pj.id = v.project_id
    WHERE v.visit_date = CURDATE() AND v.status = 'planned'
    ORDER BY v.visit_time ASC
    LIMIT 10
");

// ── Top projects ──────────────────────────────────────────────────────────────
$topProjects = tryFetch($pdo, "
    SELECT pj.code AS project_code, pj.nom AS project_nom, COUNT(DISTINCT c.id) AS cnt
    FROM clients c
    JOIN crm_client_needs n ON n.client_id = c.id
    JOIN projects pj ON pj.id = n.project_id
    GROUP BY pj.id
    ORDER BY cnt DESC
    LIMIT 5
");
if (!$topProjects) {
    $topProjects = tryFetch($pdo, "
        SELECT pj.code AS project_code, pj.nom AS project_nom, COUNT(DISTINCT d.client_id) AS cnt
        FROM crm_dossiers d
        JOIN projects pj ON pj.id = d.project_id
        GROUP BY pj.id ORDER BY cnt DESC LIMIT 5
    ");
}

echo json_encode([
    'by_status'         => $byStatus,
    'total_active'      => $totalActive,
    'signed_this_month' => $signedThisMonth,
    'pipeline_value'    => $pipelineValue,
    'financial' => [
        'month_paid'      => $monthPaid,
        'month_due'       => $monthDue,
        'overdue_count'   => $overdueCount,
        'overdue_amount'  => $overdueAmt,
        'total_remaining' => $totalRemainder,
    ],
    'todo'   => $todo,
    'alerts' => $alerts,
    'recent' => $recent,
    'hot_clients'  => $hotClients,
    'visits_today' => $visitsToday,
    'top_projects' => $topProjects,
], JSON_UNESCAPED_UNICODE);
