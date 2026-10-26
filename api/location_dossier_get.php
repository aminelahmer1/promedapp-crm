<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(403); exit(json_encode(['error'=>'Non autorisé'])); }
header('Content-Type: application/json');

$unit_id   = (int)($_GET['unit_id']   ?? 0);
$unit_type = ($_GET['unit_type'] ?? '') === 'accessoire' ? 'accessoire' : 'unit';

if (!$unit_id) { http_response_code(400); exit(json_encode(['error'=>'unit_id manquant'])); }

/* ── Dossier actif ── */
$stmt = $pdo->prepare("SELECT * FROM location_dossiers WHERE unit_id=? AND unit_type=? AND statut IN ('reserve','loue') ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$unit_id, $unit_type]);
$dossier = $stmt->fetch();
if (!$dossier) { exit(json_encode(['error'=>'no_dossier'])); }

/* ── Infos du bien ── */
if ($unit_type === 'accessoire') {
    $u = $pdo->prepare("SELECT acc.code AS ref_code, acc.floor_name, acc.surface, acc.price_month_ht_rent FROM unit_accessoires acc WHERE acc.id=?");
} else {
    $u = $pdo->prepare("SELECT ref_code, floor_name, surface, price_month_ht_rent FROM units WHERE id=?");
}
$u->execute([$unit_id]);
$unit = $u->fetch();

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
    'documents'   => $documents,
    'can_confirm' => $can_confirm,
]);
