<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/locaux_lib.php';
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'Non autorisé']));
}

header('Content-Type: application/json');

$id     = filter_input(INPUT_POST, 'id',     FILTER_VALIDATE_INT);
$status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_SPECIAL_CHARS);
$table  = filter_input(INPUT_POST, 'table',  FILTER_SANITIZE_SPECIAL_CHARS);

$allowed = ['D', 'V', 'R', 'L'];
if (!$id || !in_array($status, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètres invalides']);
    exit;
}

if ($table === 'accessoire') {
    $stmt = $pdo->prepare("UPDATE unit_accessoires SET status_code = :status WHERE id = :id");
    $stmt->execute([':status' => $status, ':id' => $id]);
    $ids = [$id];
} else {
    /* Local en plusieurs parties : toutes les parties changent ensemble */
    $ids = local_set_status($pdo, $id, $status);
}

echo json_encode(['ok' => true, 'id' => $id, 'ids' => $ids, 'status' => $status]);
