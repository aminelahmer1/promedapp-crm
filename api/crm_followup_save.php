<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) { http_response_code(400); echo json_encode(['error'=>'JSON invalide']); exit; }

$clientId   = (int)($data['client_id']   ?? 0);
$dossierId  = (int)($data['dossier_id']  ?? 0);
$actType    = trim((string)($data['activity_type'] ?? $data['type'] ?? 'note'));
$subject    = trim((string)($data['subject']    ?? ''));
$description= trim((string)($data['description']?? ''));
$result     = trim((string)($data['result']     ?? ''));
$nextAction = trim((string)($data['next_action']?? ''));
$actDate    = trim((string)($data['followup_date'] ?? $data['activity_date'] ?? date('Y-m-d H:i:s')));
$dueDate    = trim((string)($data['due_date']   ?? ''));

if (!$clientId) { http_response_code(400); echo json_encode(['error'=>'client_id obligatoire']); exit; }
if (!$subject && !$description) { http_response_code(400); echo json_encode(['error'=>'Objet ou description obligatoire']); exit; }

$validTypes = ['call','whatsapp','email','meeting','visit','note','task','reminder','negotiation','brochure','plan'];
if (!in_array($actType, $validTypes)) $actType = 'note';

try {
    // Detect available columns
    $hasCrBy = false; $hasUid = false; $hasDue = false; $hasSubj = false; $hasRes = false; $hasNext = false; $hasCli = false; $hasActDate = false;
    try { $pdo->query("SELECT created_by   FROM crm_activities LIMIT 0"); $hasCrBy    = true; } catch(Throwable $e){}
    try { $pdo->query("SELECT user_id      FROM crm_activities LIMIT 0"); $hasUid     = true; } catch(Throwable $e){}
    try { $pdo->query("SELECT due_date     FROM crm_activities LIMIT 0"); $hasDue     = true; } catch(Throwable $e){}
    try { $pdo->query("SELECT subject      FROM crm_activities LIMIT 0"); $hasSubj    = true; } catch(Throwable $e){}
    try { $pdo->query("SELECT result       FROM crm_activities LIMIT 0"); $hasRes     = true; } catch(Throwable $e){}
    try { $pdo->query("SELECT next_action  FROM crm_activities LIMIT 0"); $hasNext    = true; } catch(Throwable $e){}
    try { $pdo->query("SELECT client_id    FROM crm_activities LIMIT 0"); $hasCli     = true; } catch(Throwable $e){}
    try { $pdo->query("SELECT activity_date FROM crm_activities LIMIT 0"); $hasActDate = true; } catch(Throwable $e){}

    $userCol = $hasCrBy ? 'created_by' : ($hasUid ? 'user_id' : null);
    $cols   = ['dossier_id','activity_type','description'];
    $params = [$dossierId ?: null, $actType, $description ?: $subject];
    if ($userCol) { $cols[] = $userCol; $params[] = $_SESSION['user_id']; }
    if ($hasActDate) { $cols[] = 'activity_date'; $params[] = $actDate; }

    if ($hasCli)  { $cols[] = 'client_id';   $params[] = $clientId; }
    if ($hasSubj) { $cols[] = 'subject';      $params[] = $subject; }
    if ($hasRes)  { $cols[] = 'result';       $params[] = $result ?: null; }
    if ($hasNext) { $cols[] = 'next_action';  $params[] = $nextAction ?: null; }
    if ($hasDue && $dueDate) { $cols[] = 'due_date'; $params[] = $dueDate; }

    $ph = implode(',', array_fill(0, count($params), '?'));
    $pdo->prepare("INSERT INTO crm_activities (" . implode(',', $cols) . ") VALUES ($ph)")->execute($params);
    $actId = (int)$pdo->lastInsertId();

    // Update client last_contact_at (column may not exist yet)
    try { $pdo->prepare("UPDATE clients SET last_contact_at=?, updated_at=NOW() WHERE id=?")->execute([$actDate, $clientId]); } catch(Throwable $e){}

    // Update next_followup_at if due_date provided
    if ($dueDate) {
        try {
            $pdo->prepare("UPDATE clients SET next_followup_at=? WHERE id=?")->execute([$dueDate, $clientId]);
        } catch(Throwable $e){}
    }

    echo json_encode(['success'=>true,'id'=>$actId], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()]);
}
