<?php
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/locaux_lib.php';
if (empty($_SESSION['user_id'])) { http_response_code(403); exit(json_encode(['error'=>'Non autorisé'])); }
header('Content-Type: application/json');

$unit_id   = (int)($_GET['unit_id']   ?? 0);
$unit_type = ($_GET['unit_type'] ?? '') === 'accessoire' ? 'accessoire' : 'unit';

if (!$unit_id) { http_response_code(400); exit(json_encode(['error'=>'unit_id manquant'])); }

/* ── Dossier actif (sur le bien ou une autre partie du même local) ── */
$partRows = $unit_type === 'unit' ? local_part_rows($pdo, $unit_id) : [];
$partIds  = $partRows ? array_keys($partRows) : [$unit_id];
$phIds    = implode(',', array_fill(0, count($partIds), '?'));
$stmt = $pdo->prepare("SELECT * FROM location_dossiers WHERE unit_id IN ($phIds) AND unit_type=? AND statut IN ('reserve','loue') ORDER BY created_at DESC LIMIT 1");
$stmt->execute(array_merge($partIds, [$unit_type]));
$dossier = $stmt->fetch();
if (!$dossier) { exit(json_encode(['error'=>'no_dossier'])); }

/* ── Infos du bien ── */
if ($unit_type === 'accessoire') {
    $u = $pdo->prepare("SELECT acc.code AS ref_code, acc.floor_name, acc.surface, acc.price_month_ht_rent FROM unit_accessoires acc WHERE acc.id=?");
} else {
    $u = $pdo->prepare("SELECT ref_code, floor_name, surface, price_month_ht_rent FROM units WHERE id=?");
}
$u->execute([(int)$dossier['unit_id']]);
$unit = $u->fetch();

/* ── Local en plusieurs parties : nom, parties, surface totale, loyer du local ── */
$local = null;
if ($unit_type === 'unit' && count($partRows) > 1) {
    $ph = implode(',', array_fill(0, count($partRows), '?'));
    $q  = $pdo->prepare("SELECT u.ref_code, u.floor_name, u.surface, u.total_surface, u.price_month_ht_rent, p.code AS project_code
                         FROM units u JOIN projects p ON p.id = u.project_id WHERE u.id IN ($ph)");
    $q->execute(array_keys($partRows));
    $parts = $q->fetchAll();
    $g = local_group_of((string)$parts[0]['project_code'], (string)$parts[0]['ref_code']);
    $total = 0; $loyer = null; $totalDecl = null;
    foreach ($parts as $p) {
        $total += (float)$p['surface'];
        if ($p['total_surface'] !== null && $totalDecl === null) { $totalDecl = (float)$p['total_surface']; $loyer = $p['price_month_ht_rent']; }
    }
    $local = [
        'nom'           => $g['nom'] ?? '',
        'parts'         => array_map(fn($p) => ['ref_code' => $p['ref_code'], 'floor_name' => $p['floor_name'], 'surface' => $p['surface']], $parts),
        'total_surface' => $totalDecl ?? $total,
        'loyer_mois_ht' => $loyer,
    ];
}

/* ── Documents ── */
$docs = $pdo->prepare("SELECT id, doc_type, original_name, file_path, uploaded_at FROM location_documents WHERE dossier_id=? ORDER BY doc_type, uploaded_at DESC");
$docs->execute([$dossier['id']]);
$documents = $docs->fetchAll();

/* ── can_confirm ── */
$types_present = array_unique(array_column($documents, 'doc_type'));
$can_confirm   = $dossier['statut'] === 'reserve'
    && in_array('contrat_signe',   $types_present)
    && in_array('etat_des_lieux',  $types_present);

echo json_encode([
    'dossier'     => $dossier,
    'unit'        => $unit,
    'local'       => $local,
    'documents'   => $documents,
    'can_confirm' => $can_confirm,
]);
