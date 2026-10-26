<?php
// Appelé silencieusement au chargement des dashboards — expire les réservations > 7 jours
// (sauf celles couvertes par une promesse active ou une option valable)
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/reservation_lib.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Non authentifié']); exit; }

try {
    $res = expireReservations($pdo);
    echo json_encode(['success' => true] + $res);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
