<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) { http_response_code(403); exit(json_encode(['error'=>'Non autorisé'])); }
header('Content-Type: application/json');

$dossier_id = (int)($_POST['dossier_id'] ?? 0);
$doc_type   = $_POST['doc_type'] ?? '';
$allowed_types = ['contrat_signe','etat_des_lieux','image_edl','autre'];

if (!$dossier_id || !in_array($doc_type, $allowed_types, true))
    { http_response_code(400); exit(json_encode(['error'=>'Paramètres invalides'])); }

if (empty($_FILES['file']))
    { http_response_code(400); exit(json_encode(['error'=>'Fichier manquant'])); }

/* ── Vérifier que le dossier existe et est actif ── */
$row = $pdo->prepare("SELECT id, statut FROM location_dossiers WHERE id=? LIMIT 1");
$row->execute([$dossier_id]);
$dossier = $row->fetch();
if (!$dossier) { http_response_code(404); exit(json_encode(['error'=>'Dossier introuvable'])); }
if ($dossier['statut'] === 'annule')
    { http_response_code(403); exit(json_encode(['error'=>'Dossier annulé'])); }

/* ── Validation MIME ── */
$allowed_mimes = ['application/pdf','image/jpeg','image/png','image/gif'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $_FILES['file']['tmp_name']);
finfo_close($finfo);
if (!in_array($mime, $allowed_mimes, true))
    { http_response_code(415); exit(json_encode(['error'=>'Type de fichier non autorisé (PDF ou image uniquement)'])); }

/* ── Taille max 10 Mo ── */
if ($_FILES['file']['size'] > 10 * 1024 * 1024)
    { http_response_code(413); exit(json_encode(['error'=>'Fichier trop volumineux (max 10 Mo)'])); }

/* ── Extension ── */
$ext_map = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif'];
$ext = $ext_map[$mime];

/* ── Dossier de stockage ── */
$dir = __DIR__ . '/../uploads/location/' . $dossier_id . '/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$safe_name = $doc_type . '_' . uniqid() . '.' . $ext;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir . $safe_name))
    { http_response_code(500); exit(json_encode(['error'=>'Échec du stockage du fichier'])); }

$rel_path = 'uploads/location/' . $dossier_id . '/' . $safe_name;

$pdo->prepare("INSERT INTO location_documents (dossier_id,doc_type,file_path,original_name,mime_type,file_size,uploaded_by)
               VALUES (?,?,?,?,?,?,?)")
    ->execute([$dossier_id, $doc_type, $rel_path, $_FILES['file']['name'], $mime, $_FILES['file']['size'], $_SESSION['user_id']]);

$doc_id = (int)$pdo->lastInsertId();

/* ── Recalculer can_confirm ── */
$docs = $pdo->prepare("SELECT DISTINCT doc_type FROM location_documents WHERE dossier_id=?");
$docs->execute([$dossier_id]);
$types = $docs->fetchAll(PDO::FETCH_COLUMN);
$can_confirm = $dossier['statut'] === 'reserve'
    && in_array('contrat_signe',  $types)
    && in_array('etat_des_lieux', $types);

echo json_encode(['ok'=>true, 'doc_id'=>$doc_id, 'file_path'=>$rel_path,
                  'original_name'=>$_FILES['file']['name'], 'can_confirm'=>$can_confirm]);
