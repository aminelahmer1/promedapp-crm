<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo json_encode(['error'=>'id manquant']); exit; }

try {
    // ── Client principal ────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT c.*,
               u.full_name AS commercial_nom
        FROM clients c
        LEFT JOIN users u ON u.id = c.assigned_to
        WHERE c.id = ?
    ");
    $stmt->execute([$id]);
    $client = $stmt->fetch();
    if (!$client) { http_response_code(404); echo json_encode(['error'=>'Client introuvable']); exit; }

    // ── Besoins ─────────────────────────────────────────────
    $needs = $pdo->prepare("
        SELECT n.*, p.code AS project_code, p.nom AS project_nom
        FROM crm_client_needs n
        LEFT JOIN projects p ON p.id = n.project_id
        WHERE n.client_id = ?
        ORDER BY n.id DESC LIMIT 1
    ");
    $needs->execute([$id]);
    $clientNeeds = $needs->fetch() ?: [];

    // ── Unités intéressées ───────────────────────────────────
    $units = $pdo->prepare("
        SELECT cu.*, p.code AS project_code, p.nom AS project_nom
        FROM crm_client_units cu
        LEFT JOIN projects p ON p.id = cu.project_id
        WHERE cu.client_id = ?
        ORDER BY cu.is_favorite DESC, cu.created_at DESC
    ");
    $units->execute([$id]);
    $clientUnits = $units->fetchAll();

    // ── Activités / suivis (client + ses dossiers) ───────────
    $hasUserId    = false; $hasCreatedBy = false;
    try { $pdo->query("SELECT user_id    FROM crm_activities LIMIT 0"); $hasUserId    = true; } catch(Throwable $e){}
    try { $pdo->query("SELECT created_by FROM crm_activities LIMIT 0"); $hasCreatedBy = true; } catch(Throwable $e){}

    if ($hasCreatedBy && $hasUserId)   $userColExpr = 'COALESCE(a.created_by, a.user_id)';
    elseif ($hasCreatedBy)             $userColExpr = 'a.created_by';
    elseif ($hasUserId)                $userColExpr = 'a.user_id';
    else                               $userColExpr = 'NULL';

    $actDateCol = 'a.created_at';
    foreach (['activity_date','date','created_at'] as $c) {
        try { $pdo->query("SELECT `$c` FROM crm_activities LIMIT 0"); $actDateCol = "a.`$c`"; break; } catch(Throwable $e){}
    }
    $hasActClientId = false;
    try { $pdo->query("SELECT client_id FROM crm_activities LIMIT 0"); $hasActClientId = true; } catch(Throwable $e){}

    $actWhere  = $hasActClientId
        ? "a.client_id = ? OR a.dossier_id IN (SELECT id FROM crm_dossiers WHERE client_id = ?)"
        : "a.dossier_id IN (SELECT id FROM crm_dossiers WHERE client_id = ?)";
    $actParams = $hasActClientId ? [$id, $id] : [$id];

    $acts = $pdo->prepare("
        SELECT a.*, u.full_name AS user_nom,
               d.dossier_number
        FROM crm_activities a
        LEFT JOIN users u ON u.id = ($userColExpr)
        LEFT JOIN crm_dossiers d ON d.id = a.dossier_id
        WHERE $actWhere
        ORDER BY $actDateCol DESC, a.created_at DESC
        LIMIT 80
    ");
    $acts->execute($actParams);
    $activities = $acts->fetchAll();

    // ── Dossiers liés ────────────────────────────────────────
    $dos = $pdo->prepare("
        SELECT d.id, d.dossier_number, d.status, d.unit_code, d.interest_level,
               d.budget, d.created_at, d.reservation_date,
               p.code AS project_code, u.full_name AS assigned_nom
        FROM crm_dossiers d
        LEFT JOIN projects p ON p.id = d.project_id
        LEFT JOIN users u ON u.id = d.assigned_to
        WHERE d.client_id = ?
        ORDER BY d.id DESC
    ");
    $dos->execute([$id]);
    $dossiers = $dos->fetchAll();

    // ── Promesses de vente (promises table) ─────────────────
    $promises = [];
    try {
        $prm = $pdo->prepare("
            SELECT p.id, p.promise_number, p.unit_code, p.status, p.is_locked,
                   p.promise_date, p.signed_date, p.total_ttc, p.sale_price,
                   p.paid_amount, p.remaining_amount,
                   pj.code AS project_code
            FROM promises p
            LEFT JOIN projects pj ON pj.id = p.project_id
            WHERE p.client_id = ?
            ORDER BY p.id DESC
        ");
        $prm->execute([$id]);
        $promises = $prm->fetchAll();
    } catch(Throwable $e){}

    // ── Visites ──────────────────────────────────────────────
    $vis = $pdo->prepare("
        SELECT v.*, p.code AS project_code, u.full_name AS commercial_nom
        FROM crm_visits v
        LEFT JOIN projects p ON p.id = v.project_id
        LEFT JOIN users u ON u.id = v.commercial_id
        WHERE v.client_id = ?
        ORDER BY v.visit_date DESC
    ");
    $vis->execute([$id]);
    $visits = $vis->fetchAll();

    echo json_encode([
        'client'     => $client,
        'needs'      => $clientNeeds,
        'units'      => $clientUnits,
        'activities' => $activities,
        'promises'   => $promises,
        'dossiers'   => $dossiers,
        'visits'     => $visits,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
