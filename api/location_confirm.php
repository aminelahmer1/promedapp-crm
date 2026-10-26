<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(403); exit(json_encode(['error'=>'Non autorisé'])); }
header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true);
$dossier_id = (int)($body['dossier_id'] ?? 0);
if (!$dossier_id) { http_response_code(400); exit(json_encode(['error'=>'dossier_id manquant'])); }

$stmt = $pdo->prepare("SELECT id, unit_id, unit_type, statut FROM location_dossiers WHERE id=? LIMIT 1");
$stmt->execute([$dossier_id]);
$dossier = $stmt->fetch();

if (!$dossier)
    { http_response_code(404); exit(json_encode(['error'=>'Dossier introuvable'])); }
if ($dossier['statut'] !== 'reserve')
    { http_response_code(409); exit(json_encode(['error'=>'Le dossier n\'est pas en statut réservé'])); }

/* ── Vérifier documents obligatoires ── */
$chk = $pdo->prepare("SELECT COUNT(DISTINCT doc_type) FROM location_documents WHERE dossier_id=? AND doc_type IN ('contrat_signe','etat_des_lieux')");
$chk->execute([$dossier_id]);
if ((int)$chk->fetchColumn() < 2)
    { http_response_code(422); exit(json_encode(['error'=>'Documents obligatoires manquants (contrat signé + état des lieux)'])); }

try {
    $pdo->beginTransaction();

    $tbl = $dossier['unit_type'] === 'accessoire' ? 'unit_accessoires' : 'units';
    $pdo->prepare("UPDATE $tbl SET status_code='L' WHERE id=?")->execute([$dossier['unit_id']]);
    $pdo->prepare("UPDATE location_dossiers SET statut='loue', date_confirmation=NOW() WHERE id=?")->execute([$dossier_id]);
    $pdo->prepare("INSERT INTO location_status_history (dossier_id,old_status,new_status,changed_by) VALUES (?,?,?,?)")
        ->execute([$dossier_id,'R','L',$_SESSION['user_id']]);

    $pdo->commit();
    echo json_encode(['ok'=>true]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error'=>'Erreur serveur : '.$e->getMessage()]);
}
