<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) { http_response_code(400); echo json_encode(['error'=>'JSON invalide']); exit; }

$clientId     = (int)($data['client_id']     ?? 0);
$projectId    = (int)($data['project_id']    ?? 0);
$unitCode     = trim((string)($data['unit_code']     ?? ''));
$interestLevel= trim((string)($data['interest_level']?? 'medium'));
$isFavorite   = (int)($data['is_favorite']   ?? 0);
$notes        = trim((string)($data['notes'] ?? ''));

if (!$clientId || !$unitCode) {
    http_response_code(400); echo json_encode(['error'=>'client_id et unit_code obligatoires']); exit;
}

try {
    // Check duplicate
    $exists = (int)$pdo->prepare("SELECT id FROM crm_client_units WHERE client_id=? AND unit_code=? LIMIT 1")
        ->execute([$clientId, $unitCode]) ?
        (int)$pdo->query("SELECT id FROM crm_client_units WHERE client_id=$clientId AND unit_code='".addslashes($unitCode)."' LIMIT 1")->fetchColumn() : 0;

    if ($exists) {
        $pdo->prepare("UPDATE crm_client_units SET interest_level=?,is_favorite=?,updated_at=NOW() WHERE id=?")
            ->execute([$interestLevel, $isFavorite, $exists]);
        echo json_encode(['success'=>true,'id'=>$exists,'updated'=>true]); exit;
    }

    $pdo->prepare("INSERT INTO crm_client_units (client_id,project_id,unit_code,interest_level,is_favorite,notes) VALUES (?,?,?,?,?,?)")
        ->execute([$clientId, $projectId ?: null, $unitCode, $interestLevel, $isFavorite, $notes ?: null]);

    $newId = (int)$pdo->lastInsertId();
    echo json_encode(['success'=>true,'id'=>$newId], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()]);
}
