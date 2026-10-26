<?php
require __DIR__ . '/../config.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Accès refusé']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit;
}

header('Content-Type: application/json; charset=utf-8');

$project = strtolower(trim($_POST['project'] ?? ''));
if (!in_array($project, ['flava', 'ava'])) {
    echo json_encode(['error' => 'Projet invalide']); exit;
}

function getSetting(PDO $pdo, string $key): ?string {
    $r = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $r->execute([$key]);
    $v = $r->fetchColumn();
    return $v === false ? null : (string)$v;
}

function setSetting(PDO $pdo, string $key, ?string $value): void {
    $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$key, $value]);
}

$frozenKey = $project . '_price_frozen';
$current   = getSetting($pdo, $frozenKey);
$nowFrozen = ($current === '1');

if ($nowFrozen) {
    // Reprendre l'augmentation
    setSetting($pdo, $frozenKey,                 '0');
    setSetting($pdo, $project . '_price_freeze_year',  null);
    setSetting($pdo, $project . '_price_freeze_month', null);
    echo json_encode(['frozen' => false, 'project' => strtoupper($project)]);
} else {
    // Bloquer à partir du mois courant
    $now = new DateTime();
    setSetting($pdo, $frozenKey,                       '1');
    setSetting($pdo, $project . '_price_freeze_year',  $now->format('Y'));
    setSetting($pdo, $project . '_price_freeze_month', $now->format('n')); // 1-indexed
    echo json_encode([
        'frozen'  => true,
        'project' => strtoupper($project),
        'year'    => (int)$now->format('Y'),
        'month'   => (int)$now->format('n'),
    ]);
}
