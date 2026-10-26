<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) { http_response_code(400); echo json_encode(['error'=>'JSON invalide']); exit; }

$dossierId   = (int)($data['dossier_id'] ?? 0);
$actType     = trim((string)($data['activity_type'] ?? ''));
$description = trim((string)($data['description'] ?? ''));
$subject     = trim((string)($data['subject']      ?? ''));
$actDate     = trim((string)($data['activity_date'] ?? date('Y-m-d H:i:s')));
$dueDate     = trim((string)($data['due_date']      ?? ''));

if (!$dossierId || !$actType || (!$description && !$subject)) {
    http_response_code(400);
    echo json_encode(['error' => 'dossier_id, activity_type et description ou objet sont obligatoires']);
    exit;
}

$validTypes = ['note','call','whatsapp','meeting','email','visit','task'];
if (!in_array($actType, $validTypes)) {
    http_response_code(400);
    echo json_encode(['error' => 'Type d\'activité invalide']);
    exit;
}

// Detect available columns (schema differs between local and production)
$hasSubject  = false;
$hasCreatedBy = false;
$hasDueDate  = false;
try { $pdo->query("SELECT subject FROM crm_activities LIMIT 0");      $hasSubject   = true; } catch(Throwable $e){}
try { $pdo->query("SELECT created_by FROM crm_activities LIMIT 0");   $hasCreatedBy = true; } catch(Throwable $e){}
try { $pdo->query("SELECT due_date FROM crm_activities LIMIT 0");     $hasDueDate   = true; } catch(Throwable $e){}
$hasActDate = false;
try { $pdo->query("SELECT activity_date FROM crm_activities LIMIT 0"); $hasActDate = true; } catch(Throwable $e){}

$hasUserId = false;
try { $pdo->query("SELECT user_id FROM crm_activities LIMIT 0"); $hasUserId = true; } catch(Throwable $e){}
$userCol = $hasCreatedBy ? 'created_by' : ($hasUserId ? 'user_id' : null);

try {
    $cols   = ['dossier_id','activity_type','description'];
    $params = [$dossierId, $actType, $description ?: $subject];
    if ($userCol) { $cols[] = $userCol; $params[] = $_SESSION['user_id']; }
    if ($hasActDate) { $cols[] = 'activity_date'; $params[] = $actDate; }

    if ($hasSubject) { $cols[] = 'subject'; $params[] = $subject; }
    if ($hasDueDate && $dueDate) { $cols[] = 'due_date'; $params[] = $dueDate; }

    $ph = implode(',', array_fill(0, count($params), '?'));
    $pdo->prepare("INSERT INTO crm_activities (" . implode(',', $cols) . ") VALUES ($ph)")->execute($params);

    $newId = (int)$pdo->lastInsertId();
    echo json_encode(['success' => true, 'id' => $newId], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
