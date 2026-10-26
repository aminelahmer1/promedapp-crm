<?php
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

/* ══ Constantes de base (septembre 2025 = mois 0) ══════════ */
define('FL_S1_BASE',      5700.0);
define('FL_S2_BASE',      5500.0);
define('FL_S3_BASE',      5450.0);   // S+3, S+3,5, S+4,5 et autres
define('FL_DUP_BASE',     5350.0);
define('FL_PENT_BASE',    5300.0);   // Penthouse + Loft
define('FL_PARK_BASE',   30000.0);
define('FL_CELL_BASE',    2730.0);
define('FL_MONTHLY_RATE', 0.005);
define('FL_VAT_RATE',     1.19);
define('FL_BASE_YEAR',    2025);
define('FL_BASE_MO',      9);        // septembre = 9 (MONTH() MySQL est 1-indexé)
require_once __DIR__ . '/../includes/price_lib.php';

/* ══ Vérifier le freeze ════════════════════════════════════ */
try {
    $fzVal = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='flava_price_frozen'")->fetchColumn();
    if ($fzVal === '1') {
        echo json_encode(['error' => 'Prix FLAVA bloqués par l\'administrateur. Aucune mise à jour effectuée.']);
        exit;
    }
} catch (Throwable $_e) { /* table absente : on continue */ }

/* ══ Nombre de mois écoulés depuis Sept. 2025 ══════════════ */
$now    = new DateTime();
$curY   = (int)$now->format('Y');
$curMo  = (int)$now->format('n');
// +0,5 % du prix de septembre 2025 par mois, mois bloqués non comptés (comme la liste)
$factor = flava_coef(flava_frozen_months($pdo), $curY, $curMo);
$months = (int)round(($factor - 1) / FL_MONTHLY_RATE);

/* ══ Prix du mois courant ════════════════════════════════════ */
$s1M2Ht    = round(FL_S1_BASE   * $factor, 3);
$s1M2Ttc   = round($s1M2Ht   * FL_VAT_RATE, 3);
$s2M2Ht    = round(FL_S2_BASE   * $factor, 3);
$s2M2Ttc   = round($s2M2Ht   * FL_VAT_RATE, 3);
$s3M2Ht    = round(FL_S3_BASE   * $factor, 3);
$s3M2Ttc   = round($s3M2Ht   * FL_VAT_RATE, 3);
$dupM2Ht   = round(FL_DUP_BASE  * $factor, 3);
$dupM2Ttc  = round($dupM2Ht  * FL_VAT_RATE, 3);
$pentM2Ht  = round(FL_PENT_BASE * $factor, 3);
$pentM2Ttc = round($pentM2Ht * FL_VAT_RATE, 3);
$parkHt    = round(FL_PARK_BASE * $factor, 0);
$parkTtc   = round($parkHt   * FL_VAT_RATE, 0);
$cellM2Ht  = round(FL_CELL_BASE * $factor, 3);
$cellM2Ttc = round($cellM2Ht * FL_VAT_RATE, 3);

try {
    $pdo->beginTransaction();

    /* ── Appartements S+1 ─────────────────────────────────── */
    $s1 = $pdo->prepare("
        UPDATE units u
        JOIN projects p ON p.id = u.project_id
        SET u.price_m2_ht_sale  = :m2ht,
            u.price_m2_ttc_sale = :m2ttc,
            u.price_ht_sale     = ROUND(:m2ht  * u.surface, 0),
            u.price_ttc_sale    = ROUND(:m2ttc * u.surface, 0)
        WHERE p.code = 'FLAVA'
          AND u.operation_mode = 'sale'
          AND u.status_code   != 'V'
          AND UPPER(u.designation) IN ('APARTMENT', 'APARTEMENT')
          AND UPPER(u.type) = 'S+1'
    ");
    $s1->execute([':m2ht' => $s1M2Ht, ':m2ttc' => $s1M2Ttc]);
    $nS1 = $s1->rowCount();

    /* ── Appartements S+2 ─────────────────────────────────── */
    $s2 = $pdo->prepare("
        UPDATE units u
        JOIN projects p ON p.id = u.project_id
        SET u.price_m2_ht_sale  = :m2ht,
            u.price_m2_ttc_sale = :m2ttc,
            u.price_ht_sale     = ROUND(:m2ht  * u.surface, 0),
            u.price_ttc_sale    = ROUND(:m2ttc * u.surface, 0)
        WHERE p.code = 'FLAVA'
          AND u.operation_mode = 'sale'
          AND u.status_code   != 'V'
          AND UPPER(u.designation) IN ('APARTMENT', 'APARTEMENT')
          AND UPPER(u.type) = 'S+2'
    ");
    $s2->execute([':m2ht' => $s2M2Ht, ':m2ttc' => $s2M2Ttc]);
    $nS2 = $s2->rowCount();

    /* ── Appartements S+3 et plus ────────────────────────── */
    $s3 = $pdo->prepare("
        UPDATE units u
        JOIN projects p ON p.id = u.project_id
        SET u.price_m2_ht_sale  = :m2ht,
            u.price_m2_ttc_sale = :m2ttc,
            u.price_ht_sale     = ROUND(:m2ht  * u.surface, 0),
            u.price_ttc_sale    = ROUND(:m2ttc * u.surface, 0)
        WHERE p.code = 'FLAVA'
          AND u.operation_mode = 'sale'
          AND u.status_code   != 'V'
          AND UPPER(u.designation) IN ('APARTMENT', 'APARTEMENT')
          AND UPPER(u.type) NOT IN ('S+1', 'S+2')
    ");
    $s3->execute([':m2ht' => $s3M2Ht, ':m2ttc' => $s3M2Ttc]);
    $nS3 = $s3->rowCount();

    /* ── Duplex ────────────────────────────────────────────── */
    $dup = $pdo->prepare("
        UPDATE units u
        JOIN projects p ON p.id = u.project_id
        SET u.price_m2_ht_sale  = :m2ht,
            u.price_m2_ttc_sale = :m2ttc,
            u.price_ht_sale     = ROUND(:m2ht  * u.surface, 0),
            u.price_ttc_sale    = ROUND(:m2ttc * u.surface, 0)
        WHERE p.code = 'FLAVA'
          AND u.operation_mode = 'sale'
          AND u.status_code   != 'V'
          AND UPPER(u.designation) = 'DUPLEX'
    ");
    $dup->execute([':m2ht' => $dupM2Ht, ':m2ttc' => $dupM2Ttc]);
    $nDup = $dup->rowCount();

    /* ── Penthouse + Loft ──────────────────────────────────── */
    $pent = $pdo->prepare("
        UPDATE units u
        JOIN projects p ON p.id = u.project_id
        SET u.price_m2_ht_sale  = :m2ht,
            u.price_m2_ttc_sale = :m2ttc,
            u.price_ht_sale     = ROUND(:m2ht  * u.surface, 0),
            u.price_ttc_sale    = ROUND(:m2ttc * u.surface, 0)
        WHERE p.code = 'FLAVA'
          AND u.operation_mode = 'sale'
          AND u.status_code   != 'V'
          AND UPPER(u.designation) IN ('PENTHOUSE', 'LOFT')
    ");
    $pent->execute([':m2ht' => $pentM2Ht, ':m2ttc' => $pentM2Ttc]);
    $nPent = $pent->rowCount();

    /* ── Parking ────────────────────────────────────────────── */
    $park = $pdo->prepare("
        UPDATE unit_accessoires a
        JOIN projects p ON p.id = a.project_id
        SET a.price_ht_sale  = :ht,
            a.price_ttc_sale = :ttc
        WHERE p.code = 'FLAVA'
          AND a.operation_mode = 'sale'
          AND UPPER(a.accessory_type) = 'PARKING'
          AND a.status_code  != 'V'
    ");
    $park->execute([':ht' => $parkHt, ':ttc' => $parkTtc]);
    $nPark = $park->rowCount();

    /* ── Celliers ────────────────────────────────────────────── */
    $cell = $pdo->prepare("
        UPDATE unit_accessoires a
        JOIN projects p ON p.id = a.project_id
        SET a.price_m2_ht_sale = :m2ht,
            a.price_ht_sale    = ROUND(:m2ht  * a.surface, 0),
            a.price_ttc_sale   = ROUND(:m2ttc * a.surface, 0)
        WHERE p.code = 'FLAVA'
          AND a.operation_mode = 'sale'
          AND UPPER(a.accessory_type) = 'CELLIER'
          AND a.status_code   != 'V'
    ");
    $cell->execute([':m2ht' => $cellM2Ht, ':m2ttc' => $cellM2Ttc]);
    $nCell = $cell->rowCount();

    $pdo->commit();

    echo json_encode([
        'ok'         => true,
        'mois'       => $now->format('m/Y'),
        'n_mois'     => $months,
        's1_m2_ht'   => $s1M2Ht,
        's2_m2_ht'   => $s2M2Ht,
        's3_m2_ht'   => $s3M2Ht,
        'dup_m2_ht'  => $dupM2Ht,
        'pent_m2_ht' => $pentM2Ht,
        'park_ht'    => $parkHt,
        'cell_m2_ht' => $cellM2Ht,
        'lignes'     => [
            's1'        => $nS1,
            's2'        => $nS2,
            's3plus'    => $nS3,
            'duplex'    => $nDup,
            'penthouse' => $nPent,
            'parking'   => $nPark,
            'celliers'  => $nCell,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
