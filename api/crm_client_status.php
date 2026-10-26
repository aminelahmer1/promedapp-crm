<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
$id     = (int)($data['id']     ?? 0);
$status = trim((string)($data['status'] ?? ''));
$lostReason = trim((string)($data['lost_reason'] ?? ''));

$allowed = ['new','contacted','interested','visit','negotiation','reserved','lost','sold'];
if (!$id || !in_array($status, $allowed)) {
    http_response_code(400); echo json_encode(['error'=>'Paramètres invalides']); exit;
}

try {
    // Probe columns before UPDATE
    $hasCrmSt  = false; $hasUpdAt3 = false;
    try { $pdo->query("SELECT crm_status  FROM clients LIMIT 0"); $hasCrmSt  = true; } catch(Throwable $e){}
    try { $pdo->query("SELECT updated_at  FROM clients LIMIT 0"); $hasUpdAt3 = true; } catch(Throwable $e){}

    if (!$hasCrmSt) {
        http_response_code(400);
        echo json_encode(['error'=>'Migration CRM non appliquée — colonne crm_status manquante. Lancez run_crm_fakedata.php']);
        exit;
    }
    $updAtSql = $hasUpdAt3 ? ', updated_at=NOW()' : '';
    $pdo->prepare("UPDATE clients SET crm_status=?$updAtSql WHERE id=?")->execute([$status, $id]);

    // Log activity for status change
    $note = 'Statut changé vers : ' . $status . ($lostReason ? ' — Raison : ' . $lostReason : '');
    $hasCli = false;
    try { $pdo->query("SELECT client_id FROM crm_activities LIMIT 0"); $hasCli = true; } catch(Throwable $e){}

    $hasUid2 = false;
    try { $pdo->query("SELECT user_id    FROM crm_activities LIMIT 0"); $hasUid2  = true; } catch(Throwable $e){}
    $hasCrBy2 = false;
    try { $pdo->query("SELECT created_by FROM crm_activities LIMIT 0"); $hasCrBy2 = true; } catch(Throwable $e){}
    $userCol = $hasCrBy2 ? 'created_by' : ($hasUid2 ? 'user_id' : null);

    $hasSubj = false;
    try { $pdo->query("SELECT subject FROM crm_activities LIMIT 0"); $hasSubj = true; } catch(Throwable $e){}

    if ($hasCli) {
        $hasActDate = false;
        try { $pdo->query("SELECT activity_date FROM crm_activities LIMIT 0"); $hasActDate = true; } catch(Throwable $e){}
        $actCols   = ['client_id','activity_type','description'];
        $actVals   = ['?','?','?'];
        $actParams = [$id,'note',$note];
        if ($userCol) { $actCols[] = $userCol; $actVals[] = '?'; $actParams[] = $_SESSION['user_id']; }
        if ($hasActDate) { $actCols[] = 'activity_date'; $actVals[] = 'NOW()'; }
        if ($hasSubj)    { $actCols[] = 'subject';       $actVals[] = '?'; $actParams[] = 'Changement statut → '.$status; }
        $sql = "INSERT INTO crm_activities (".implode(',',$actCols).") VALUES (".implode(',',$actVals).")";
        $pdo->prepare($sql)->execute($actParams);
    }

    // If lost, save reason in private_notes
    if ($status === 'lost' && $lostReason) {
        try {
            $pdo->prepare("UPDATE clients SET private_notes=CONCAT(COALESCE(private_notes,''),'[PERDU] ',?) WHERE id=?")->execute([$lostReason, $id]);
        } catch(Throwable $e){}
    }

    // Audit
    try {
        $pdo->prepare("INSERT INTO audit_logs(user_id,entity_type,entity_id,action,new_value) VALUES(?,?,?,?,?)")
            ->execute([$_SESSION['user_id'],'client',$id,'status_change',json_encode(['status'=>$status,'reason'=>$lostReason])]);
    } catch(Throwable $e){}

    echo json_encode(['success'=>true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()]);
}
