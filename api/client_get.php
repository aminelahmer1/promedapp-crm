<?php
require __DIR__ . '/../config.php';

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

function sale_project_code(array $row): string {
    $project = strtoupper(trim((string)($row['sale_project_code'] ?? '')));
    if ($project !== '') return $project;

    $details = $row['details_json'] ?? null;
    if (is_string($details) && $details !== '') {
        $decoded = json_decode($details, true);
        if (is_array($decoded)) {
            $fromJson = strtoupper(trim((string)($decoded['projectName'] ?? '')));
            if ($fromJson !== '') return $fromJson;
        }
    }

    $objet = strtoupper((string)($row['objet'] ?? ''));
    if (strpos($objet, 'FLAVA') !== false) return 'FLAVA';
    if (strpos($objet, 'AVA') !== false) return 'AVA';

    return '';
}

function flava_canonical_unit_code(string $code): string {
    $value = strtoupper(trim($code));
    if ($value === '') return '';

    if (preg_match('/^A-RDC-LC0?(\d{1,2})$/', $value, $m)) return 'LC ' . (int)$m[1];
    if ($value === 'A-MEZZ-M01') return 'M01';
    if (preg_match('/^([AB])-([123])-(\d{1,2})$/', $value, $m)) return $m[1] . $m[2] . '-' . (int)$m[3];
    if (preg_match('/^D-1-(\d{1,2})$/', $value, $m)) return 'D1-' . (int)$m[1];
    if (preg_match('/^B-(?:RDC|MEZZ)-D0?(\d{1,2})$/', $value, $m)) return 'D0-' . (int)$m[1];

    return $value;
}

function flava_unit_code_candidates(string $code): array {
    $raw = strtoupper(trim($code));
    $canonical = flava_canonical_unit_code($raw);
    $codes = [$raw, $canonical];

    if (preg_match('/^D0-(\d{1,2})$/', $canonical, $m)) {
        $suffix = str_pad((string)(int)$m[1], 2, '0', STR_PAD_LEFT);
        $codes[] = 'B-RDC-D' . $suffix;
        $codes[] = 'B-MEZZ-D' . $suffix;
    }
    if (preg_match('/^D1-(\d{1,2})$/', $canonical, $m)) {
        $codes[] = 'D-1-' . (int)$m[1];
    }
    if (preg_match('/^([AB])([123])-(\d{1,2})$/', $canonical, $m)) {
        $codes[] = $m[1] . '-' . $m[2] . '-' . (int)$m[3];
    }
    if (preg_match('/^LC (\d{1,2})$/', $canonical, $m)) {
        $codes[] = 'A-RDC-LC' . str_pad((string)(int)$m[1], 2, '0', STR_PAD_LEFT);
    }
    if ($canonical === 'M01') {
        $codes[] = 'A-MEZZ-M01';
    }

    return array_values(array_unique(array_filter($codes)));
}

$unitCode = trim((string)($_GET['unit_code'] ?? ''));
$projectCode = strtoupper(trim((string)($_GET['project'] ?? '')));

if ($unitCode === '') {
    http_response_code(400);
    echo json_encode(['error' => 'unit_code manquant']);
    exit;
}

if (!table_exists($pdo, 'ventes') || !table_exists($pdo, 'clients')) {
    http_response_code(500);
    echo json_encode(['error' => 'tables_manquantes']);
    exit;
}

try {
    $hasProjectId = column_exists($pdo, 'ventes', 'project_id');
    $joinProjects = $hasProjectId && table_exists($pdo, 'projects')
        ? " LEFT JOIN projects p ON p.id = v.project_id "
        : "";
    $projectSelect = $joinProjects !== "" ? ", p.code AS sale_project_code" : "";

    $searchCodes = ($projectCode === 'FLAVA') ? flava_unit_code_candidates($unitCode) : [$unitCode];
    $placeholders = implode(', ', array_fill(0, count($searchCodes), '?'));

    $sql = "
      SELECT c.id,
             c.nom,
             c.tel,
             c.email,
             v.objet,
             v.details_json
             $projectSelect
      FROM ventes v
      JOIN clients c ON c.id = v.client_id
      $joinProjects
      WHERE UPPER(v.unit_code) IN ($placeholders)
      ORDER BY v.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_map('strtoupper', $searchCodes));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $match = null;
    foreach ($rows as $row) {
        if ($projectCode === '' || sale_project_code($row) === $projectCode) {
            $match = $row;
            break;
        }
    }

    if (!$match && $projectCode === '' && !empty($rows)) {
        $match = $rows[0];
    }

    if (!$match) {
        echo json_encode(['error' => 'no_client']);
        exit;
    }

    echo json_encode([
        'id' => $match['id'],
        'nom' => $match['nom'] ?? '',
        'tel' => $match['tel'] ?? '',
        'email' => $match['email'] ?? '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error', 'message' => $e->getMessage()]);
}
