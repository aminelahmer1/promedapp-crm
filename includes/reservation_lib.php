<?php
// Expiration des réservations de plus de 7 jours.
// Ne libère PAS un appartement / parking / cellier qui est dans une promesse active
// (brouillon, signée, convertie en vente) ou dans une option de vente encore valable.

function expireReservations(PDO $pdo): array {
    $cutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
    $ci = fn($col) => "CONVERT($col USING utf8mb4) COLLATE utf8mb4_unicode_ci";

    $hasOptions = false;
    try {
        $hasOptions = (bool)$pdo->query("SHOW TABLES LIKE 'option_ventes'")->fetchColumn();
    } catch (Throwable $e) { /* table absente */ }

    // Appartements
    $sqlUnits = "UPDATE units u SET u.status_code='D', u.reserved_at=NULL
        WHERE u.status_code='R' AND u.reserved_at IS NOT NULL AND u.reserved_at < ?
          AND NOT EXISTS (
              SELECT 1 FROM promises pr
              WHERE pr.status IN ('draft','signed','converted_to_sale')
                AND UPPER(TRIM(" . $ci('pr.unit_code') . ")) = UPPER(TRIM(" . $ci('u.ref_code') . "))
                AND (pr.project_id IS NULL OR pr.project_id = u.project_id))";
    if ($hasOptions) {
        $sqlUnits .= "
          AND NOT EXISTS (
              SELECT 1 FROM option_ventes ov
              WHERE ov.status = 'active' AND ov.valid_until >= CURDATE()
                AND UPPER(TRIM(" . $ci('ov.unit_code') . ")) = UPPER(TRIM(" . $ci('u.ref_code') . "))
                AND (ov.project_id IS NULL OR ov.project_id = u.project_id))";
    }
    $u = $pdo->prepare($sqlUnits);
    $u->execute([$cutoff]);

    // Parkings / celliers
    $codeA = "UPPER(TRIM(" . $ci('a.code') . "))";
    $a = $pdo->prepare("UPDATE unit_accessoires a SET a.status_code='D', a.reserved_at=NULL
        WHERE a.status_code='R' AND a.reserved_at IS NOT NULL AND a.reserved_at < ?
          AND NOT EXISTS (
              SELECT 1 FROM promises pr
              WHERE pr.status IN ('draft','signed','converted_to_sale')
                AND (pr.project_id IS NULL OR pr.project_id = a.project_id)
                AND (FIND_IN_SET($codeA, UPPER(REPLACE(" . $ci("IFNULL(pr.parking_codes,'')") . ",' ','')))
                  OR FIND_IN_SET($codeA, UPPER(REPLACE(" . $ci("IFNULL(pr.cellier_codes,'')") . ",' ','')))))");
    $a->execute([$cutoff]);

    return ['expired_units' => $u->rowCount(), 'expired_accessories' => $a->rowCount()];
}

// ── Promesses en cours ──────────────────────────────────────────────────
// Les codes A1-1..A1-4 existent dans AVA et FLAVA : on compare toujours le projet.

function projectIdFromCode(PDO $pdo, string $code): ?int {
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM projects WHERE UPPER(code) = ? LIMIT 1");
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

// Projet d'un appartement quand son code n'existe que dans un seul projet
function uniqueUnitProjectId(PDO $pdo, string $unitCode): ?int {
    $stmt = $pdo->prepare("SELECT DISTINCT project_id FROM units WHERE UPPER(ref_code) = UPPER(?)");
    $stmt->execute([trim($unitCode)]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return count($ids) === 1 ? (int)$ids[0] : null;
}

// Projet d'une promesse : colonne project_id, sinon projectName de la fiche, sinon code unique
function promiseProjectId(PDO $pdo, array $promise): ?int {
    if (!empty($promise['project_id'])) return (int)$promise['project_id'];
    $dj = json_decode((string)($promise['details_json'] ?? ''), true);
    if (is_array($dj) && !empty($dj['projectName'])) {
        $id = projectIdFromCode($pdo, (string)$dj['projectName']);
        if ($id) return $id;
    }
    return uniqueUnitProjectId($pdo, (string)($promise['unit_code'] ?? ''));
}

// Promesse en cours (brouillon, signée, convertie) sur cet appartement, hors $excludeId
function findActivePromiseForUnit(PDO $pdo, int $projectId, string $unitCode, int $excludeId = 0): ?array {
    $stmt = $pdo->prepare("SELECT id, promise_number, project_id, unit_code, details_json, status FROM promises
                           WHERE id <> ? AND status IN ('draft','signed','converted_to_sale')
                             AND UPPER(TRIM(unit_code)) = UPPER(?)
                           ORDER BY id DESC");
    $stmt->execute([$excludeId, trim($unitCode)]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if (promiseProjectId($pdo, $p) === $projectId) return $p;
    }
    return null;
}

// Promesse en cours qui contient ce parking / cellier, hors $excludeId
function findActivePromiseForAccessory(PDO $pdo, int $projectId, string $code, int $excludeId = 0): ?array {
    $code = strtoupper(str_replace(' ', '', $code));
    $stmt = $pdo->prepare("SELECT id, promise_number, project_id, unit_code, details_json, status FROM promises
                           WHERE id <> ? AND status IN ('draft','signed','converted_to_sale')
                             AND (FIND_IN_SET(?, UPPER(REPLACE(IFNULL(parking_codes,''),' ','')))
                               OR FIND_IN_SET(?, UPPER(REPLACE(IFNULL(cellier_codes,''),' ',''))))
                           ORDER BY id DESC");
    $stmt->execute([$excludeId, $code, $code]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $pid = promiseProjectId($pdo, $p);
        if ($pid === $projectId || $pid === null) return $p;
    }
    return null;
}

// Option de vente active et encore valable sur cet appartement
function hasValidOption(PDO $pdo, int $projectId, string $unitCode): bool {
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'option_ventes'")->fetchColumn()) return false;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM option_ventes
                               WHERE status = 'active' AND valid_until >= CURDATE()
                                 AND UPPER(TRIM(unit_code)) = UPPER(?)
                                 AND (project_id IS NULL OR project_id = ?)");
        $stmt->execute([trim($unitCode), $projectId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

// Parking / cellier vendu avec un AUTRE appartement (vente faite depuis la fiche ou le plan)
function accessorySoldWithOtherUnit(PDO $pdo, int $projectId, string $code, string $unitCode): bool {
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'ventes'")->fetchColumn()) return false;
        $code = strtoupper(str_replace(' ', '', $code));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ventes v
                               JOIN units u ON UPPER(u.ref_code) = UPPER(v.unit_code) AND u.project_id = ?
                               WHERE u.status_code = 'V'
                                 AND (v.project_id IS NULL OR v.project_id = ?)
                                 AND UPPER(v.unit_code) <> UPPER(?)
                                 AND (FIND_IN_SET(?, UPPER(REPLACE(IFNULL(v.parking,''),' ','')))
                                   OR FIND_IN_SET(?, UPPER(REPLACE(IFNULL(v.cellier,''),' ',''))))");
        $stmt->execute([$projectId, $projectId, trim($unitCode), $code, $code]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) { return true; } // en cas de doute, on ne libère pas
}
