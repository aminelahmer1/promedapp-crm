<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
$id     = (int)($data['id']     ?? 0);
$status = trim((string)($data['status'] ?? ''));

$allowed = ['interested','reserved','promise_draft','promise_signed','sold','payment_in_progress','paid','closed','cancelled'];
if (!$id || !in_array($status, $allowed)) {
    http_response_code(400);
    echo json_encode(['error'=>'Paramètres invalides']);
    exit;
}

try {
    $pdo->prepare("UPDATE crm_dossiers SET status=?, updated_at=NOW() WHERE id=?")->execute([$status, $id]);
    // Audit
    try {
        $pdo->prepare("INSERT INTO audit_logs(user_id,entity_type,entity_id,action,new_value) VALUES(?,?,?,?,?)")
            ->execute([$_SESSION['user_id'],'crm_dossier',$id,'status_change',json_encode(['status'=>$status])]);
    } catch(Throwable $e){}
    echo json_encode(['success'=>true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()]);
}
