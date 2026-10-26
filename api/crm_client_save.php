<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) { http_response_code(400); echo json_encode(['error'=>'JSON invalide']); exit; }

$id = (int)($data['id'] ?? 0);

// Allowed fields whitelist — filtered to only columns that actually exist in DB
$allClientCols = ['nom','first_name','last_name','tel','whatsapp','email','cin','address','nationality',
                  'profession','campaign','source','assigned_to','budget_min','budget_max',
                  'buying_delay','financing_mode','motivation','notes','private_notes',
                  'crm_status','interest_level','next_followup_at'];
$existingCols = [];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM clients")->fetchAll() as $r) $existingCols[] = $r['Field'];
} catch(Throwable $e){}
$clientCols = $existingCols ? array_filter($allClientCols, fn($c) => in_array($c, $existingCols)) : $allClientCols;

try {
    if ($id) {
        // UPDATE
        $set = []; $params = [];
        foreach ($clientCols as $col) {
            if (!array_key_exists($col, $data)) continue;
            $val = $data[$col];
            if (in_array($col, ['assigned_to']) && $val !== null) $val = (int)$val;
            if (in_array($col, ['budget_min','budget_max']) && $val !== null) $val = (float)$val;
            $set[] = "$col = ?";
            $params[] = $val === '' ? null : $val;
        }
        if (empty($set)) { echo json_encode(['success'=>true,'id'=>$id]); exit; }
        $params[] = $id;
        $hasUpdAt = in_array('updated_at', $existingCols);
        $updAtSql = $hasUpdAt ? ', updated_at=NOW()' : '';
        $pdo->prepare("UPDATE clients SET " . implode(',', $set) . "$updAtSql WHERE id=?")->execute($params);

    } else {
        // INSERT — forcer cin à NULL si absent ou vide (contrainte UNIQUE)
        if (!isset($data['cin']) || $data['cin'] === '') $data['cin'] = null;
        $cols = []; $vals = []; $params = [];
        foreach ($clientCols as $col) {
            if (!array_key_exists($col, $data)) continue;
            $val = $data[$col];
            if (in_array($col, ['assigned_to']) && $val !== null) $val = (int)$val;
            $cols[] = $col; $vals[] = '?'; $params[] = $val === '' ? null : $val;
        }
        $cols[] = 'created_at'; $vals[] = 'NOW()';
        $pdo->prepare("INSERT INTO clients (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")")->execute($params);
        $id = (int)$pdo->lastInsertId();
    }

    // ── Save needs if provided ────────────────────────────
    if (!empty($data['needs'])) {
        $n = $data['needs'];
        $needCols = ['project_id','property_type','bedrooms','surface_min','surface_max','budget_min','budget_max',
                     'wants_parking','wants_cellier','wants_pool','wants_independent_kitchen',
                     'wants_terrace','wants_garden','wants_open_view','preferred_floor','notes'];
        $existing = (int)$pdo->prepare("SELECT id FROM crm_client_needs WHERE client_id=? LIMIT 1")->execute([$id]) ?
                    (int)$pdo->query("SELECT id FROM crm_client_needs WHERE client_id=$id LIMIT 1")->fetchColumn() : 0;

        $set2 = []; $params2 = [];
        foreach ($needCols as $col) {
            if (!array_key_exists($col, $n)) continue;
            $val = $n[$col];
            if (in_array($col, ['wants_parking','wants_cellier','wants_pool','wants_independent_kitchen','wants_terrace','wants_garden','wants_open_view'])) $val = $val ? 1 : 0;
            $set2[] = $col; $params2[] = $val === '' ? null : $val;
        }
        if ($set2) {
            $existId = (int)$pdo->query("SELECT id FROM crm_client_needs WHERE client_id=$id LIMIT 1")->fetchColumn();
            if ($existId) {
                $upd = array_map(fn($c) => "$c=?", $set2);
                $params2[] = $existId;
                $pdo->prepare("UPDATE crm_client_needs SET " . implode(',', $upd) . ",updated_at=NOW() WHERE id=?")->execute($params2);
            } else {
                $params2[] = $id;
                $allCols = implode(',', $set2) . ',client_id';
                $allVals = implode(',', array_fill(0, count($set2), '?')) . ',?';
                $pdo->prepare("INSERT INTO crm_client_needs ($allCols) VALUES ($allVals)")->execute($params2);
            }
        }
    }

    // Audit
    try {
        $pdo->prepare("INSERT INTO audit_logs(user_id,entity_type,entity_id,action,new_value) VALUES(?,?,?,?,?)")
            ->execute([$_SESSION['user_id'],'client',$id,'save',json_encode(['fields'=>array_keys($data)])]);
    } catch(Throwable $e){}

    echo json_encode(['success'=>true,'id'=>$id], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()]);
}
