<?php
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/price_lib.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function normalize_status(?string $status): string {
    $s = trim((string)$status);
    $u = strtoupper($s);
    if ($u === 'D' || mb_strtolower($s) === 'disponible') return 'Disponible';
    if ($u === 'R' || $u === 'RESERVE' || $u === 'RÉSERVÉ' || mb_strtolower($s) === 'réservé' || mb_strtolower($s) === 'reserve') return 'Réservé';
    if ($u === 'V' || mb_strtolower($s) === 'vendu') return 'Vendu';
    return $s !== '' ? $s : 'Disponible';
}

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

function flava_plan_fallback(string $code): string {
    $canonical = strtoupper(trim(flava_canonical_unit_code($code)));

    if (preg_match('/^D0-(\d{1,2})$/', $canonical, $m))
        return 'uploads/plans/units/flava/D0-' . (int)$m[1] . '.jpg';
    if (preg_match('/^D1-(\d{1,2})$/', $canonical, $m))
        return 'uploads/plans/units/flava/D1-' . (int)$m[1] . '.jpg';
    if (preg_match('/^LC (\d{1,2})$/', $canonical, $m))
        return 'uploads/plans/units/flava/Boutique ' . str_pad((int)$m[1], 2, '0', STR_PAD_LEFT) . '.jpg';
    if ($canonical === 'M01')
        return 'uploads/plans/units/flava/M01.jpg';
    if (preg_match('/^[AB][123]-\d{1,2}$/', $canonical))
        return 'uploads/plans/units/flava/' . $canonical . '.jpg';

    return '';
}

$projectCode = strtoupper(trim((string)($_GET['project'] ?? '')));
if ($projectCode === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Projet manquant']);
    exit;
}

if (!table_exists($pdo, 'units')) {
    echo json_encode(['project' => $projectCode, 'units' => []]);
    exit;
}

$projectId = null;
if (table_exists($pdo, 'projects')) {
    $stmt = $pdo->prepare('SELECT id FROM projects WHERE UPPER(code) = ? LIMIT 1');
    $stmt->execute([$projectCode]);
    $projectId = $stmt->fetchColumn();
}

$hasRefCode = column_exists($pdo, 'units', 'ref_code');
$hasPlan = column_exists($pdo, 'units', 'plan_image');
$hasStatusCode = column_exists($pdo, 'units', 'status_code');
$hasPriceM2Unified = column_exists($pdo, 'units', 'price_m2_ht_sale');
$hasPriceM2Legacy = column_exists($pdo, 'units', 'price_m2_base');
$hasSurface = column_exists($pdo, 'units', 'surface');
$hasTotalSurface = column_exists($pdo, 'units', 'total_surface');

$units = [];
$monthPrices = project_month_prices($pdo, $projectCode);

if ($hasRefCode) {
    $where = [];
    $params = [];
    if ($projectId) {
        $where[] = 'u.project_id = ?';
        $params[] = $projectId;
    } else {
        $where[] = 'UPPER(p.code) = ?';
        $params[] = $projectCode;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT u.*, p.code AS project_code FROM units u LEFT JOIN projects p ON p.id = u.project_id $whereSql ORDER BY u.ref_code ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $priceM2 = $row['price_m2_ht_sale'] ?? $row['price_m2_ht_rent'] ?? null;
        $surface = $row['surface'] ?? $row['total_surface'] ?? null;
        $priceHt = null;
        if ($monthPrices) {
            // Prix du mois, comme la Liste des prix
            $pr = project_row_price($monthPrices, ['surface' => $surface] + $row, false);
            $priceM2 = $pr['price_m2_ht'];
            $priceHt = $pr['price_ht'];
        }
        $planImage = $hasPlan ? (string)($row['plan_image'] ?? '') : '';
        if ($projectCode === 'FLAVA' && $planImage === '') {
            $planImage = flava_plan_fallback((string)($row['ref_code'] ?? ''));
        }
        $units[] = [
            'code' => $row['ref_code'] ?? '',
            'db_code' => $row['ref_code'] ?? '',
            'block' => $row['block_name'] ?? '',
            'floor' => $row['floor_name'] ?? '',
            'type' => $row['type'] ?? ($row['designation'] ?? ''),
            'surface' => $surface !== null ? (float)$surface : 0,
            'price_m2_base' => $priceM2 !== null ? (float)$priceM2 : 0,
            'price_ht' => $priceHt !== null ? (float)$priceHt : 0,
            'status' => normalize_status($row['status_code'] ?? 'D'),
            'plan_image' => $planImage,
        ];
    }
} else {
    $where = [];
    $params = [];
    if ($projectId) {
        $where[] = 'u.project_id = ?';
        $params[] = $projectId;
    } else {
        $where[] = 'UPPER(p.code) = ?';
        $params[] = $projectCode;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT u.*, p.code AS project_code FROM units u LEFT JOIN projects p ON p.id = u.project_id $whereSql ORDER BY u.code ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $statusMap = [];
    if (table_exists($pdo, 'unit_statuses') && column_exists($pdo, 'unit_statuses', 'unit_code') && column_exists($pdo, 'unit_statuses', 'status')) {
        $st = $pdo->query('SELECT unit_code, status FROM unit_statuses');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $srow) {
            $statusMap[strtoupper(trim((string)$srow['unit_code']))] = normalize_status($srow['status'] ?? 'D');
        }
    }

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = (string)($row['code'] ?? '');
        $units[] = [
            'code' => $code,
            'block' => $row['block'] ?? '',
            'floor' => $row['floor'] ?? '',
            'type' => $row['type'] ?? ($row['composition'] ?? ''),
            'surface' => isset($row['surface']) ? (float)$row['surface'] : 0,
            'price_m2_base' => isset($row['price_m2_base']) ? (float)$row['price_m2_base'] : 0,
            'status' => $statusMap[strtoupper(trim($code))] ?? 'Disponible',
            'plan_image' => $hasPlan ? (string)($row['plan_image'] ?? '') : '',
        ];
    }
}

// ── Exclure les unités vendues si demandé (modal admin) ────────────────────
if (!empty($_GET['exclude_sold'])) {
    $units = array_values(array_filter($units, function($u) {
        return strtoupper(trim((string)($u['status'] ?? ''))) !== 'VENDU';
    }));
}

// ── Filtre agence : ne retourner que les unités autorisées ──────────────────
$sessionRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ($sessionRole === 'agence') {
    $userId = (int)$_SESSION['user_id'];
    $stmt2  = $pdo->prepare(
        "SELECT unit_code FROM agence_unit_access WHERE user_id = ? AND UPPER(project_code) = ?"
    );
    $stmt2->execute([$userId, $projectCode]);
    $allowedUp = array_flip(array_map('strtoupper', $stmt2->fetchAll(PDO::FETCH_COLUMN)));
    $units = array_values(array_filter($units, function($u) use ($allowedUp) {
        return isset($allowedUp[strtoupper(trim((string)$u['code']))]);
    }));
}

echo json_encode(['project' => $projectCode, 'units' => $units], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
