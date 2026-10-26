<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo json_encode(['error'=>'id manquant']); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT d.*, c.nom AS client_nom, c.tel AS client_tel, c.email AS client_email,
               c.cin AS client_cin, c.address AS client_address,
               pj.code AS project_code, pj.nom AS project_nom,
               u.full_name AS assigned_nom
        FROM crm_dossiers d
        LEFT JOIN clients c ON c.id = d.client_id
        LEFT JOIN projects pj ON pj.id = d.project_id
        LEFT JOIN users u ON u.id = d.assigned_to
        WHERE d.id = ?
    ");
    $stmt->execute([$id]);
    $dossier = $stmt->fetch();
    if (!$dossier) { http_response_code(404); echo json_encode(['error'=>'Dossier introuvable']); exit; }

    // Promise linked to this dossier
    $pStmt = $pdo->prepare("
        SELECT p.*, ps.paid_amount, ps.remaining_amount, ps.progress_percent, ps.status AS sched_status,
               ps.id AS schedule_id
        FROM promises p
        LEFT JOIN payment_schedules ps ON ps.promise_id = p.id
        WHERE p.dossier_id = ?
        ORDER BY p.created_at DESC
        LIMIT 1
    ");
    $pStmt->execute([$id]);
    $promise = $pStmt->fetch();

    // Activities
    $acts = $pdo->prepare("
        SELECT a.*, u.full_name AS user_nom
        FROM crm_activities a
        LEFT JOIN users u ON u.id = a.created_by
        WHERE a.dossier_id = ?
        ORDER BY a.created_at DESC
        LIMIT 50
    ");
    $acts->execute([$id]);
    $activities = $acts->fetchAll();

    // Audit logs
    $aStmt = $pdo->prepare("
        SELECT al.*, u.full_name AS user_nom
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE al.entity_type = 'crm_dossier' AND al.entity_id = ?
        ORDER BY al.created_at DESC
        LIMIT 50
    ");
    $aStmt->execute([$id]);
    $audits = $aStmt->fetchAll();

    echo json_encode([
        'dossier'    => $dossier,
        'promise'    => $promise ?: null,
        'activities' => $activities,
        'audits'     => $audits,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
