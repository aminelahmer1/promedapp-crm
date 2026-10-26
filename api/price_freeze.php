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
    // FLAVA : les mois bloqués (du mois du blocage au mois précédent) restent sans augmentation
    if ($project === 'flava') {
        $fy = (int)(getSetting($pdo, 'flava_price_freeze_year') ?? 0);
        $fm = (int)(getSetting($pdo, 'flava_price_freeze_month') ?? 0);
        $months = array_filter(explode(',', (string)getSetting($pdo, 'flava_price_frozen_months')));
        if ($fy > 0 && $fm >= 1 && $fm <= 12) {
            $now = new DateTime();
            $nowIdx = (int)$now->format('Y') * 12 + (int)$now->format('n');
            for ($i = $fy * 12 + $fm; $i < $nowIdx; $i++) {
                $y = intdiv($i - 1, 12);
                $months[] = sprintf('%04d-%02d', $y, $i - $y * 12);
            }
        }
        $months = array_values(array_unique(array_map('trim', $months)));
        sort($months);
        setSetting($pdo, 'flava_price_frozen_months', implode(',', $months));
    }
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
