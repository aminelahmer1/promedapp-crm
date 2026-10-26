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

/* ══ Constantes de base ══════════════════════════════════════ */
define('APT_BASE_M2',     5350.0);
define('PARKING_BASE',   30000.0);
define('CELLIER_BASE_M2', 2675.0);
define('MONTHLY_RATE',    0.005);
define('VAT_RATE',        1.19);
define('BASE_YEAR',       2025);
define('BASE_MO',         2);   // février = 2 (MONTH() MySQL est 1-indexé)

/* ══ Vérifier le freeze ════════════════════════════════════ */
try {
    $fzVal = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='ava_price_frozen'")->fetchColumn();
    if ($fzVal === '1') {
        echo json_encode(['error' => 'Prix AVA bloqués par l\'administrateur. Aucune mise à jour effectuée.']);
        exit;
    }
} catch (Throwable $_e) { /* table absente : on continue */ }

/* ══ Nombre de mois écoulés depuis Fév. 2025 ═══════════════ */
$now     = new DateTime();
$curY    = (int)$now->format('Y');
$curMo   = (int)$now->format('n');  // 1-indexé
$months  = ($curY - BASE_YEAR) * 12 + ($curMo - BASE_MO);
if ($months < 0) $months = 0;

$factor  = pow(1 + MONTHLY_RATE, $months);

/* ══ Prix du mois courant ════════════════════════════════════ */
$aptM2Ht   = round(APT_BASE_M2     * $factor, 3);
$aptM2Ttc  = round($aptM2Ht  * VAT_RATE, 3);
$parkHt    = round(PARKING_BASE    * $factor, 3);
$parkTtc   = round($parkHt   * VAT_RATE, 3);
$cellM2Ht  = round(CELLIER_BASE_M2 * $factor, 3);
$cellM2Ttc = round($cellM2Ht * VAT_RATE, 3);

try {
    $pdo->beginTransaction();

    /* ── Appartements : unités non vendues ────────────────── */
    $s1 = $pdo->prepare("
        UPDATE units u
        JOIN projects p ON p.id = u.project_id
        SET u.price_m2_ht_sale  = :m2ht,
            u.price_m2_ttc_sale = :m2ttc,
            u.price_ht_sale     = ROUND(:m2ht  * u.surface, 0),
            u.price_ttc_sale    = ROUND(:m2ttc * u.surface, 0)
        WHERE p.code = 'AVA'
          AND u.operation_mode = 'sale'
          AND u.status_code   != 'V'
    ");
    $s1->execute([':m2ht' => $aptM2Ht, ':m2ttc' => $aptM2Ttc]);
    $nApt = $s1->rowCount();

    /* ── Parking : non vendu ──────────────────────────────── */
    $s2 = $pdo->prepare("
        UPDATE unit_accessoires a
        JOIN projects p ON p.id = a.project_id
        SET a.price_ht_sale  = :ht,
            a.price_ttc_sale = :ttc
        WHERE p.code = 'AVA'
          AND a.operation_mode = 'sale'
          AND UPPER(a.accessory_type) = 'PARKING'
          AND a.status_code  != 'V'
    ");
    $s2->execute([':ht' => $parkHt, ':ttc' => $parkTtc]);
    $nPark = $s2->rowCount();

    /* ── Celliers : non vendus ────────────────────────────── */
    $s3 = $pdo->prepare("
        UPDATE unit_accessoires a
        JOIN projects p ON p.id = a.project_id
        SET a.price_m2_ht_sale = :m2ht,
            a.price_ht_sale    = ROUND(:m2ht  * a.surface, 0),
            a.price_ttc_sale   = ROUND(:m2ttc * a.surface, 0)
        WHERE p.code = 'AVA'
          AND a.operation_mode = 'sale'
          AND UPPER(a.accessory_type) != 'PARKING'
          AND a.status_code   != 'V'
    ");
    $s3->execute([':m2ht' => $cellM2Ht, ':m2ttc' => $cellM2Ttc]);
    $nCell = $s3->rowCount();

    $pdo->commit();

    echo json_encode([
        'ok'           => true,
        'mois'         => $now->format('m/Y'),
        'mois_label'   => ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'][$curMo] . ' ' . $curY,
        'n_mois'       => $months,
        'apt_m2_ht'    => $aptM2Ht,
        'apt_m2_ttc'   => $aptM2Ttc,
        'parking_ht'   => $parkHt,
        'parking_ttc'  => $parkTtc,
        'cellier_m2'   => $cellM2Ht,
        'lignes'       => [
            'appartements' => $nApt,
            'parking'      => $nPark,
            'celliers'     => $nCell,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
