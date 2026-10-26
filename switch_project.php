<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
if (!$code) { header('Location: select_project.php'); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.code, p.nom, p.date_fin_prevue, s.raison_sociale
        FROM projects p
        LEFT JOIN societes s ON s.id = p.societe_id
        WHERE UPPER(p.code) = ?
        LIMIT 1
    ");
    $stmt->execute([$code]);
    $project = $stmt->fetch();
} catch (Throwable $e) { $project = null; }

if (!$project) { header('Location: select_project.php'); exit; }

$_SESSION['current_project_id']       = $project['id'];
$_SESSION['current_project_code']     = $project['code'];
$_SESSION['current_project_name']     = $project['nom'];
$_SESSION['current_project_societe']  = $project['raison_sociale'];
$_SESSION['current_project_date_fin'] = $project['date_fin_prevue'];

switch ($project['code']) {
    case 'FLAVA': header('Location: home_flava.php'); break;
    case 'AVA':   header('Location: home_ava.php');   break;
    default:      header('Location: dashboard.php');  break;
}
exit;
