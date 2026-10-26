<?php
/* ══ Prix de vente du mois : même calcul que la Liste des prix ══════════
   AVA : mois de calcul = mois du blocage si « Bloquer l'augmentation » est actif,
   sinon le mois en cours (borné à la période de la liste).
   FLAVA : +0,5 % du prix de septembre 2025 par mois non bloqué (voir flava_coef).
   Une unité vendue (V) garde le prix enregistré en base.
   Ce fichier ne modifie jamais la base. */

const PRICE_MONTHLY_RATE = 0.005;   // +0,5 % par mois
const PRICE_VAT          = 0.19;    // TVA 19 %

/* Prix de base AVA : février 2025 */
const AVA_BASE_YEAR       = 2025;
const AVA_BASE_MONTH      = 2;      // 1 = janvier
const AVA_APT_BASE_M2     = 5350;
const AVA_PARKING_BASE    = 30000;
const AVA_CELLIER_BASE_M2 = 2675;
const AVA_LAST_YEAR       = 2027;   // dernier mois de la liste : décembre 2027
const AVA_LAST_MONTH      = 12;

/* [année, mois 1-12] utilisés pour le calcul du projet ('ava' ou 'flava') */
function price_calc_month(PDO $pdo, string $project): array {
    $project = strtolower($project);
    $y = (int)date('Y');
    $m = (int)date('n');
    try {
        $st = $pdo->prepare("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN (?,?,?)");
        $st->execute([$project . '_price_frozen', $project . '_price_freeze_year', $project . '_price_freeze_month']);
        $s = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $s[$row['setting_key']] = $row['setting_value'];
        if (($s[$project . '_price_frozen'] ?? '0') === '1') {
            $y = (int)($s[$project . '_price_freeze_year']  ?? $y);
            $m = (int)($s[$project . '_price_freeze_month'] ?? $m);
        }
    } catch (Throwable $e) {}
    return [$y, $m];
}

function price_r3(float $n): float { return round($n, 3); }
function price_r0(float $n): float { return round($n); }

/* Prix unitaires AVA du mois de calcul */
function ava_month_prices(PDO $pdo): array {
    [$y, $m] = price_calc_month($pdo, 'ava');
    $idx  = $y * 12 + $m;
    $idx  = max($idx, AVA_BASE_YEAR * 12 + AVA_BASE_MONTH);
    $idx  = min($idx, AVA_LAST_YEAR * 12 + AVA_LAST_MONTH);
    $n    = $idx - (AVA_BASE_YEAR * 12 + AVA_BASE_MONTH);
    $coef = pow(1 + PRICE_MONTHLY_RATE, $n);
    return [
        'apt_m2'     => price_r3(AVA_APT_BASE_M2 * $coef),
        'parking_ht' => price_r3(AVA_PARKING_BASE * $coef),
        'cellier_m2' => price_r3(AVA_CELLIER_BASE_M2 * $coef),
    ];
}

/* Vrai si l'unité est vendue avec un prix enregistré : on garde ce prix */
function price_keep_db(array $row): bool {
    return strtoupper(trim((string)($row['status_code'] ?? ''))) === 'V'
        && (float)($row['price_ht_sale'] ?? 0) > 0;
}

/* Prix tel qu'enregistré en base */
function price_db(array $row): array {
    $surface = (float)($row['surface'] ?? 0);
    $ht  = (float)($row['price_ht_sale'] ?? 0);
    $ttc = (float)($row['price_ttc_sale'] ?? 0) ?: price_r0($ht * (1 + PRICE_VAT));
    $m2  = (float)($row['price_m2_ht_sale'] ?? 0) ?: ($surface > 0 ? $ht / $surface : 0);
    $m2t = (float)($row['price_m2_ttc_sale'] ?? 0) ?: price_r3($m2 * (1 + PRICE_VAT));
    return ['price_m2_ht' => $m2, 'price_m2_ttc' => $m2t, 'price_ht' => $ht, 'price_ttc' => $ttc];
}

/* Prix d'une surface au m² (appartement, cellier) : arrondis de la liste */
function price_from_m2(float $m2Ht, float $surface): array {
    $m2Ttc = price_r3($m2Ht * (1 + PRICE_VAT));
    return [
        'price_m2_ht'  => $m2Ht,
        'price_m2_ttc' => $m2Ttc,
        'price_ht'     => price_r0($m2Ht * $surface),
        'price_ttc'    => price_r0($m2Ttc * $surface),
    ];
}

/* Prix AVA d'une ligne units / unit_accessoires (accessory_type = PARKING / CELLIER pour les accessoires) */
function ava_row_price(array $prices, array $row, bool $isAccessory): array {
    $surface = (float)($row['surface'] ?? 0);
    if (price_keep_db($row)) return price_db($row);
    if ($isAccessory && strtoupper(trim((string)($row['accessory_type'] ?? $row['type'] ?? ''))) === 'PARKING') {
        $ht = $prices['parking_ht'];
        $m2 = $surface > 0 ? price_r3($ht / $surface) : 0;
        return ['price_m2_ht' => $m2, 'price_m2_ttc' => price_r3($m2 * (1 + PRICE_VAT)), 'price_ht' => $ht, 'price_ttc' => price_r0($ht * (1 + PRICE_VAT))];
    }
    return price_from_m2($isAccessory ? $prices['cellier_m2'] : $prices['apt_m2'], $surface);
}

/* Prix de base FLAVA : septembre 2025 (liste_prix_flava.php) */
const FLAVA_BASE_YEAR    = 2025;
const FLAVA_BASE_MONTH   = 9;
const FLAVA_S1_BASE      = 5700;
const FLAVA_S2_BASE      = 5500;
const FLAVA_S3_BASE      = 5450;    // S+3, S+3,5, S+4,5 et assimilés
const FLAVA_DUP_BASE     = 5350;    // Duplex
const FLAVA_PENT_BASE    = 5300;    // Penthouse + Loft
const FLAVA_COMM_BASE    = 12000;   // Local commercial : prix fixe, non indexé
const FLAVA_PARK_BASE    = 30000;
const FLAVA_CELL_BASE_M2 = 2730;
const FLAVA_LAST_YEAR    = 2027;
const FLAVA_LAST_MONTH   = 12;
const FLAVA_FROZEN_MONTHS = ['2026-08', '2026-09'];   // mois bloqués par la direction

/* Mois FLAVA bloqués, en index année*12+mois : liste ci-dessus, blocages terminés
   (app_settings.flava_price_frozen_months) et blocage en cours (du mois bloqué à la fin de la liste) */
function flava_frozen_months(PDO $pdo): array {
    $codes = FLAVA_FROZEN_MONTHS;
    $frozen = [];
    try {
        $st = $pdo->query("SELECT setting_key, setting_value FROM app_settings
                           WHERE setting_key IN ('flava_price_frozen_months','flava_price_frozen','flava_price_freeze_year','flava_price_freeze_month')");
        $s = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $s[$row['setting_key']] = (string)$row['setting_value'];
        $codes = array_merge($codes, explode(',', $s['flava_price_frozen_months'] ?? ''));
        if (($s['flava_price_frozen'] ?? '0') === '1') {
            $from = (int)($s['flava_price_freeze_year'] ?? date('Y')) * 12 + (int)($s['flava_price_freeze_month'] ?? date('n'));
            for ($i = $from; $i <= FLAVA_LAST_YEAR * 12 + FLAVA_LAST_MONTH; $i++) $frozen[$i] = true;
        }
    } catch (Throwable $e) {}
    foreach ($codes as $c) {
        if (preg_match('/^(\d{4})-(\d{1,2})$/', trim($c), $m)) $frozen[(int)$m[1] * 12 + (int)$m[2]] = true;
    }
    return $frozen;
}

/* Coefficient FLAVA d'un mois : 1 + 0,5 % par mois écoulé depuis septembre 2025,
   sans compter les mois bloqués (pas de rattrapage après un blocage) */
function flava_coef(array $frozen, int $y, int $m): float {
    $base = FLAVA_BASE_YEAR * 12 + FLAVA_BASE_MONTH;
    $idx  = min(max($y * 12 + $m, $base), FLAVA_LAST_YEAR * 12 + FLAVA_LAST_MONTH);
    $n = 0;
    for ($i = $base + 1; $i <= $idx; $i++) {
        if (empty($frozen[$i])) $n++;
    }
    return 1 + PRICE_MONTHLY_RATE * $n;
}

/* Coefficient d'augmentation FLAVA du mois en cours */
function flava_month_prices(PDO $pdo): array {
    $coef = flava_coef(flava_frozen_months($pdo), (int)date('Y'), (int)date('n'));
    return [
        'coef'       => $coef,
        'parking_ht' => price_r3(FLAVA_PARK_BASE * $coef),
        'cellier_m2' => price_r3(FLAVA_CELL_BASE_M2 * $coef),
    ];
}

/* Prix/m² HT FLAVA d'une unité selon sa désignation et son type */
function flava_unit_m2(array $prices, array $row): float {
    $desig = strtoupper(trim((string)($row['designation'] ?? '')));
    $type  = str_replace(',', '.', strtoupper(trim((string)($row['type'] ?? ''))));
    if ($desig === 'COMMERCE') return FLAVA_COMM_BASE;
    if ($desig === 'DUPLEX') $base = FLAVA_DUP_BASE;
    elseif ($desig === 'PENTHOUSE' || $desig === 'LOFT') $base = FLAVA_PENT_BASE;
    elseif ($type === 'S+1') $base = FLAVA_S1_BASE;
    elseif ($type === 'S+2') $base = FLAVA_S2_BASE;
    else $base = FLAVA_S3_BASE;
    return price_r3($base * $prices['coef']);
}

/* Prix FLAVA d'une ligne units / unit_accessoires.
   Comme la liste FLAVA, toutes les unités (vendues comprises) sont au prix du mois. */
function flava_row_price(array $prices, array $row, bool $isAccessory): array {
    $surface = (float)($row['surface'] ?? 0);
    if ($isAccessory && strtoupper(trim((string)($row['accessory_type'] ?? $row['type'] ?? ''))) === 'PARKING') {
        $ht = $prices['parking_ht'];
        $m2 = $surface > 0 ? price_r3($ht / $surface) : 0;
        return ['price_m2_ht' => $m2, 'price_m2_ttc' => price_r3($m2 * (1 + PRICE_VAT)), 'price_ht' => $ht, 'price_ttc' => price_r0($ht * (1 + PRICE_VAT))];
    }
    return price_from_m2($isAccessory ? $prices['cellier_m2'] : flava_unit_m2($prices, $row), $surface);
}

/* Prix du mois pour un projet, ou null si le projet n'a pas de grille */
function project_month_prices(PDO $pdo, string $project): ?array {
    $project = strtoupper(trim($project));
    if ($project === 'AVA')   return ['project' => 'AVA']   + ava_month_prices($pdo);
    if ($project === 'FLAVA') return ['project' => 'FLAVA'] + flava_month_prices($pdo);
    return null;
}

/* Prix du mois d'une ligne. Une unité en location (operation_mode ≠ sale) garde le prix en base. */
function project_row_price(array $prices, array $row, bool $isAccessory): array {
    $mode = strtolower(trim((string)($row['operation_mode'] ?? 'sale')));
    if ($mode !== '' && $mode !== 'sale') return price_db($row);
    return $prices['project'] === 'AVA'
        ? ava_row_price($prices, $row, $isAccessory)
        : flava_row_price($prices, $row, $isAccessory);
}
