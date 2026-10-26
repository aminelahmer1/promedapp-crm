<?php
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/reservation_lib.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function flava_canonical_unit_code(string $code): string {
    $value = strtoupper(trim($code));
    if ($value === '') return '';

    if (preg_match('/^A-RDC-LC0?(\d{1,2})$/', $value, $m)) return 'LC ' . (int)$m[1];
    if ($value === 'A-MEZZ-M01') return 'M01';
    if (preg_match('/^([AB])-([123])-(\d{1,2})$/', $value, $m)) return $m[1] . $m[2] . '-' . (int)$m[3];
    if (preg_match('/^D-1-(\d{1,2})$/', $value, $m)) return 'D1-' . (int)$m[1];
    if (preg_match('/^B-(?:RDC|MEZZ)-D0?(\d{1,2})$/', $value, $m)) return 'D0-' . (int)$m[1];

    return trim($code);
}

$raw = json_decode(file_get_contents('php://input'), true);
$kind = strtoupper(trim((string)($raw['kind'] ?? 'UNIT')));
$code = trim((string)($raw['code'] ?? ''));
$status = trim((string)($raw['status'] ?? ''));
if ($code === '' || $status === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Code ou statut manquant']);
    exit;
}
$map = ['Disponible' => 'D', 'Réservé' => 'R', 'Vendu' => 'V'];
if (!isset($map[$status])) {
    http_response_code(400);
    echo json_encode(['error' => 'Statut invalide']);
    exit;
}
$statusCode = $map[$status];
if ($kind !== 'ACCESSORY') {
    $code = flava_canonical_unit_code($code);
}

// N2 : ne pas remettre « Disponible » ce qui est dans une promesse en cours
if ($statusCode === 'D') {
    $projectId = projectIdFromCode($pdo, 'FLAVA');
    if ($projectId) {
        $busy = $kind === 'ACCESSORY'
            ? findActivePromiseForAccessory($pdo, $projectId, $code)
            : findActivePromiseForUnit($pdo, $projectId, $code);
        if ($busy) {
            http_response_code(409);
            echo json_encode(['error' => ($kind === 'ACCESSORY' ? 'Ce parking/cellier' : 'Cet appartement')
                . ' est dans une promesse en cours (' . ($busy['promise_number'] ?: '#' . $busy['id'])
                . '). Annulez d\'abord la promesse.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
try {
    $reservedAt = $statusCode === 'R' ? date('Y-m-d H:i:s') : null;
    if ($kind === 'ACCESSORY') {
        $stmt = $pdo->prepare("UPDATE unit_accessoires a JOIN projects p ON p.id = a.project_id SET a.status_code = ?, a.reserved_at = ? WHERE p.code='FLAVA' AND a.code = ?");
    } else {
        $stmt = $pdo->prepare("UPDATE units u JOIN projects p ON p.id = u.project_id SET u.status_code = ?, u.reserved_at = ? WHERE p.code='FLAVA' AND u.ref_code = ?");
    }
    $stmt->execute([$statusCode, $reservedAt, $code]);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur', 'message' => $e->getMessage()]);
}
