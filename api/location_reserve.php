<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(403); exit(json_encode(['error'=>'Non autorisé'])); }
header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); exit(json_encode(['error'=>'Données invalides'])); }

$unit_id   = (int)($body['unit_id']   ?? 0);
$unit_type = ($body['unit_type'] ?? '') === 'accessoire' ? 'accessoire' : 'unit';
$loc_type  = ($body['locataire_type'] ?? '') === 'morale' ? 'morale' : 'physique';

if (!$unit_id) { http_response_code(400); exit(json_encode(['error'=>'unit_id manquant'])); }

/* ── Résolution project_id ── */
$project_id = null;
if (!empty($body['project_code'])) {
    $r = $pdo->prepare("SELECT id FROM projects WHERE code = ? LIMIT 1");
    $r->execute([$body['project_code']]);
    $project_id = $r->fetchColumn() ?: null;
}

/* ── Validation champs obligatoires ── */
if ($loc_type === 'physique') {
    if (empty(trim($body['pp_nom_complet'] ?? '')))
        { http_response_code(422); exit(json_encode(['error'=>'Nom complet obligatoire'])); }
    if (empty(trim($body['pp_tel'] ?? '')))
        { http_response_code(422); exit(json_encode(['error'=>'Téléphone obligatoire'])); }
} else {
    if (empty(trim($body['pm_denomination'] ?? '')))
        { http_response_code(422); exit(json_encode(['error'=>'Dénomination sociale obligatoire'])); }
    if (empty(trim($body['pm_representant_nom'] ?? '')))
        { http_response_code(422); exit(json_encode(['error'=>'Nom du représentant légal obligatoire'])); }
}

try {
    $pdo->beginTransaction();

    /* Vérifier qu'il n'y a pas déjà un dossier actif */
    $chk = $pdo->prepare("SELECT id FROM location_dossiers WHERE unit_id=? AND unit_type=? AND statut IN ('reserve','loue') LIMIT 1");
    $chk->execute([$unit_id, $unit_type]);
    if ($chk->fetchColumn()) {
        $pdo->rollBack();
        exit(json_encode(['error'=>'Ce bien est déjà réservé ou loué.']));
    }

    /* Créer le dossier */
    $cols = ['unit_id','unit_type','project_id','statut','locataire_type','created_by'];
    $vals = [$unit_id, $unit_type, $project_id, 'reserve', $loc_type, $_SESSION['user_id']];

    $fields_physique = ['pp_nom_complet','pp_cin_passeport','pp_tel','pp_email','pp_adresse','pp_ville'];
    $fields_morale   = ['pm_denomination','pm_forme_juridique','pm_capital_social','pm_matricule_fiscal',
                        'pm_registre_commerce','pm_date_creation','pm_adresse_siege','pm_tel','pm_email',
                        'pm_representant_nom','pm_representant_fonction','pm_representant_cin',
                        'pm_representant_tel','pm_representant_email','pm_secteur_activite','pm_description_activite'];

    $active_fields = $loc_type === 'physique' ? $fields_physique : $fields_morale;
    foreach ($active_fields as $f) {
        $v = isset($body[$f]) && $body[$f] !== '' ? $body[$f] : null;
        $cols[] = $f; $vals[] = $v;
    }
    if (isset($body['remarques']) && $body['remarques'] !== '') { $cols[] = 'remarques'; $vals[] = $body['remarques']; }

    $ph = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO location_dossiers (".implode(',',$cols).") VALUES ($ph)")->execute($vals);
    $dossier_id = (int)$pdo->lastInsertId();

    /* Mettre le bien en Réservé */
    $tbl = $unit_type === 'accessoire' ? 'unit_accessoires' : 'units';
    $pdo->prepare("UPDATE $tbl SET status_code='R' WHERE id=?")->execute([$unit_id]);

    /* Historique */
    $pdo->prepare("INSERT INTO location_status_history (dossier_id,old_status,new_status,changed_by) VALUES (?,?,?,?)")
        ->execute([$dossier_id,'D','R',$_SESSION['user_id']]);

    $pdo->commit();
    echo json_encode(['ok'=>true, 'dossier_id'=>$dossier_id]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error'=>'Erreur serveur : '.$e->getMessage()]);
}
