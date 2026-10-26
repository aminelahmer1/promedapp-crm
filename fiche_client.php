<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/price_lib.php';
require_once __DIR__ . '/includes/reservation_lib.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function pickFirst(array $row, array $keys, $default = '') {
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
            return $row[$k];
        }
    }
    return $default;
}

function guessProjectFromUnit(array $unit): string {
    $project = trim((string)pickFirst($unit, ['project', 'residence', 'programme', 'nom_projet'], ''));
    if ($project !== '') return strtoupper($project);
    $code = strtoupper((string)pickFirst($unit, ['code'], ''));
    if (strpos($code, 'A-') === 0 || strpos($code, 'AVA') === 0) return 'AVA';
    return 'FLAVA';
}

function normalizeProjectCode(string $project): string {
    return strtoupper(trim($project));
}

function flavaCanonicalUnitCode(string $code): string {
    $value = strtoupper(trim($code));
    if ($value === '') return '';

    if (preg_match('/^A-RDC-LC0?(\d{1,2})$/', $value, $m)) return 'LC ' . (int)$m[1];
    if ($value === 'A-MEZZ-M01') return 'M01';
    if (preg_match('/^([AB])-([123])-(\d{1,2})$/', $value, $m)) return $m[1] . $m[2] . '-' . (int)$m[3];
    if (preg_match('/^D-1-(\d{1,2})$/', $value, $m)) return 'D1-' . (int)$m[1];
    if (preg_match('/^B-(?:RDC|MEZZ)-D0?(\d{1,2})$/', $value, $m)) return 'D0-' . (int)$m[1];

    return trim($code);
}

function flavaUnitCodeCandidates(string $code): array {
    $raw = strtoupper(trim($code));
    $canonical = flavaCanonicalUnitCode($raw);
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

function flavaPlanFallback(string $code): string {
    $canonical = strtoupper(trim(flavaCanonicalUnitCode($code)));

    if (preg_match('/^D0-(\d{1,2})$/', $canonical, $m))
        return 'uploads/plans/units/flava/D0-' . (int)$m[1] . '.jpg';
    if (preg_match('/^D1-(\d{1,2})$/', $canonical, $m))
        return 'uploads/plans/units/flava/D1-' . (int)$m[1] . '.jpg';
    if (preg_match('/^([AB][123])-(\d{1,2})$/', $canonical, $m))
        return 'uploads/plans/units/flava/' . $m[1] . '-' . (int)$m[2] . '.jpg';
    if (preg_match('/^LC\s*(\d{1,2})$/', $canonical, $m))
        return 'uploads/plans/units/flava/Boutique ' . str_pad((int)$m[1], 2, '0', STR_PAD_LEFT) . '.jpg';
    if ($canonical === 'M01')
        return 'uploads/plans/units/flava/M01.jpg';

    return '';
}

function saleProjectCode(array $row): string {
    $project = normalizeProjectCode((string)pickFirst($row, ['sale_project_code'], ''));
    if ($project !== '') return $project;

    $details = $row['details_json'] ?? null;
    if (is_string($details) && $details !== '') {
        $decoded = json_decode($details, true);
        if (is_array($decoded)) {
            $project = normalizeProjectCode((string)($decoded['projectName'] ?? ''));
            if ($project !== '') return $project;
        }
    }

    $objet = strtoupper((string)pickFirst($row, ['objet'], ''));
    if (strpos($objet, 'FLAVA') !== false) return 'FLAVA';
    if (strpos($objet, 'AVA') !== false) return 'AVA';

    return '';
}

$requestedUnitCode = isset($_GET['unit_code']) ? trim($_GET['unit_code']) : '';
$requestedProject = isset($_GET['project']) ? strtoupper(trim($_GET['project'])) : '';
$requestedUnitCodeCanonical = ($requestedProject === 'FLAVA') ? flavaCanonicalUnitCode($requestedUnitCode) : $requestedUnitCode;
$requestedUnitSearchCodes = ($requestedProject === 'FLAVA') ? flavaUnitCodeCandidates($requestedUnitCode) : [$requestedUnitCode];
$sessionUsername = (string)($_SESSION['username'] ?? '');
$sessionFullName = (string)($_SESSION['full_name'] ?? $_SESSION['name'] ?? $sessionUsername);
$sessionRole = strtolower(trim((string)($_SESSION['role'] ?? '')));

$units = [];
$parkings = [];
$celliers = [];
// Prix du mois par projet, comme la Liste des prix (blocage compris)
$monthPricesByProject = ['AVA' => project_month_prices($pdo, 'AVA'), 'FLAVA' => project_month_prices($pdo, 'FLAVA')];

if (tableExists($pdo, 'units') && tableExists($pdo, 'projects')) {
    $hasRefCode = columnExists($pdo, 'units', 'ref_code');
    $sql = $hasRefCode
        ? "SELECT u.*, p.code AS project_code FROM units u LEFT JOIN projects p ON p.id = u.project_id ORDER BY p.code, u.ref_code"
        : "SELECT u.*, p.code AS project_code FROM units u LEFT JOIN projects p ON p.id = u.project_id ORDER BY p.code, u.code";
    $stmt = $pdo->query($sql);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = (string)pickFirst($row, ['ref_code','code']);
        if ($code === '') continue;
        $project = strtoupper((string)pickFirst($row, ['project_code'], guessProjectFromUnit($row)));
        $planImage = (string)pickFirst($row, ['plan_image'], '');
        if ($project === 'FLAVA' && $planImage === '') {
            $planImage = flavaPlanFallback($code);
        }
        // EVOLO : plan image déposé dans uploads/plans/units/evolo/<référence>.jpg (ou .png / .pdf)
        if ($project === 'EVOLO' && $planImage === '') {
            foreach (['jpg', 'png', 'pdf'] as $ext) {
                $evoloPlan = 'uploads/plans/units/evolo/' . $code . '.' . $ext;
                if (is_file(__DIR__ . '/' . $evoloPlan)) { $planImage = $evoloPlan; break; }
            }
        }
        if (!empty($monthPricesByProject[$project])) {
            $pr = project_row_price($monthPricesByProject[$project], $row, false);
            $row['price_ht_sale'] = $pr['price_ht'];
            $row['price_m2_ht_sale'] = $pr['price_m2_ht'];
            $row['price_ttc_sale'] = $pr['price_ttc'];
        }
        $unitStatusCode = strtoupper((string)pickFirst($row, ['status_code', 'status'], 'D'));
        $unitStatusLabel = $unitStatusCode === 'V' ? 'vendu' : ($unitStatusCode === 'R' ? 'réservé' : 'disponible');
        $units[] = [
            'code' => $code,
            'project' => $project,
            'status' => $unitStatusLabel,
            'type' => (string)pickFirst($row, ['type','designation','composition'], 'Appartement'),
            'coveredArea' => (float)pickFirst($row, ['surface','covered_area','surface_couverte'], 0),
            'openArea' => (float)pickFirst($row, ['open_area','surface_decouverte'], 0),
            'shoArea' => (float)pickFirst($row, ['total_surface','sho','sho_area','surface'], 0),
            'pcArea' => (float)pickFirst($row, ['pc','pc_area'], 0),
            'priceHt'   => (float)pickFirst($row, ['price_ht_sale','price_ht','prix_ht','total_ht'], 0),
            'priceM2Ht' => (float)pickFirst($row, ['price_m2_ht_sale','price_m2_ht','price_m2_base','prix_m2_ht'], 0),
            'priceTtc'  => (float)pickFirst($row, ['price_ttc_sale','price_ttc'], 0),
            'block' => (string)pickFirst($row, ['block_name','block'], ''),
            'level' => (string)pickFirst($row, ['floor_name','floor'], ''),
            'plan_image' => $planImage,
        ];
    }
}

if (tableExists($pdo, 'unit_accessoires') && tableExists($pdo, 'projects')) {
    $stmt = $pdo->query("SELECT a.*, p.code AS project_code FROM unit_accessoires a LEFT JOIN projects p ON p.id = a.project_id ORDER BY p.code, a.code");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $accProject = strtoupper((string)($row['project_code'] ?? ''));
        if (!empty($monthPricesByProject[$accProject])) {
            $pr = project_row_price($monthPricesByProject[$accProject], $row, true);
            $row['price_ht_sale'] = $pr['price_ht'];
            $row['price_ttc_sale'] = $pr['price_ttc'];
        }
        $rawPriceHt  = (float)pickFirst($row, ['price_ht_sale','price_ht','prix_ht'], 0);
        $rawPriceTtc = (float)pickFirst($row, ['price_ttc_sale','price_ttc','prix_ttc','price'], 0);
        $statusCode = strtoupper((string)pickFirst($row, ['status_code', 'status'], 'D'));
        $statusLabel = $statusCode === 'V' ? 'vendu' : ($statusCode === 'R' ? 'réservé' : 'disponible');
        $item = [
            'code' => (string)pickFirst($row, ['code']),
            'project' => strtoupper((string)pickFirst($row, ['project_code'], '')),
            'surface' => (float)pickFirst($row, ['surface'], 0),
            'priceHt' => $rawPriceHt ?: $rawPriceTtc,
            'priceTtc' => $rawPriceTtc ?: $rawPriceHt,
            'plan_image' => (string)pickFirst($row, ['plan_image'], ''),
            'type' => strtoupper((string)pickFirst($row, ['accessory_type'], '')),
            'status' => $statusLabel,
        ];
        if ($item['type'] === 'PARKING') $parkings[] = $item;
        if ($item['type'] === 'CELLIER') $celliers[] = $item;
    }
}

if (!$units) {
    $units = [
        ['code' => 'A3-1', 'project' => 'AVA', 'type' => 'Appartement', 'coveredArea' => 244.682, 'openArea' => 0, 'shoArea' => 244.682, 'pcArea' => 30.717, 'priceHt' => 1487160, 'priceTtc' => 1769860.4],
        ['code' => 'B3-5', 'project' => 'FLAVA', 'type' => 'Appartement', 'coveredArea' => 116.020, 'openArea' => 0, 'shoArea' => 116.020, 'pcArea' => 0, 'priceHt' => 657253.3, 'priceTtc' => 782131.427],
    ];
}
if (!$parkings) {
    $parkings = [
        ['code' => 'P01', 'project' => 'AVA', 'surface' => 0, 'priceHt' => 35700, 'priceTtc' => 35700, 'status' => 'disponible', 'type' => 'PARKING'],
        ['code' => 'P02', 'project' => 'AVA', 'surface' => 0, 'priceHt' => 35700, 'priceTtc' => 35700, 'status' => 'disponible', 'type' => 'PARKING'],
    ];
}
if (!$celliers) {
    $celliers = [
        ['code' => 'C01', 'project' => 'AVA', 'surface' => 11.96, 'priceHt' => 33876.7, 'priceTtc' => 40313.27, 'status' => 'disponible', 'type' => 'CELLIER'],
        ['code' => 'C05', 'project' => 'AVA', 'surface' => 18.82, 'priceHt' => 53693.46, 'priceTtc' => 63895.22, 'status' => 'disponible', 'type' => 'CELLIER'],
    ];
}

usort($units, fn($a, $b) => strcmp((string)$a['project'] . '|' . (string)$a['code'], (string)$b['project'] . '|' . (string)$b['code']));
// Parking pris : appartement, bloc et client de la promesse active qui le contient
if ($parkings && tableExists($pdo, 'promises')) {
    $unitInfo = [];
    foreach ($units as $u) $unitInfo[strtoupper($u['code'])] = $u;
    $links = [];
    try {
        $st = $pdo->query("SELECT pr.unit_code, pr.parking_codes, c.nom AS client_nom
                           FROM promises pr LEFT JOIN clients c ON c.id = pr.client_id
                           WHERE pr.status IN ('draft','signed','converted_to_sale') AND IFNULL(pr.parking_codes,'') <> ''
                           ORDER BY pr.id");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $u = $unitInfo[strtoupper(trim((string)$pr['unit_code']))] ?? null;
            foreach (array_filter(array_map('trim', explode(',', (string)$pr['parking_codes']))) as $pc) {
                $links[($u['project'] ?? '') . '|' . strtoupper($pc)] = [
                    'unit'   => (string)$pr['unit_code'],
                    'block'  => (string)($u['block'] ?? ''),
                    'client' => trim((string)($pr['client_nom'] ?? '')),
                ];
            }
        }
    } catch (Throwable $e) {}
    // Parking réservé ou vendu sans promesse en cours : client de la dernière vente (fiche enregistrée) qui le contient
    $saleLinks = [];
    if (tableExists($pdo, 'ventes') && columnExists($pdo, 'ventes', 'parking')) {
        try {
            $st = $pdo->query("SELECT v.unit_code, v.parking, c.nom AS client_nom
                               FROM ventes v LEFT JOIN clients c ON c.id = v.client_id
                               WHERE IFNULL(v.parking,'') <> '' ORDER BY v.id");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $vr) {
                $u = $unitInfo[strtoupper(trim((string)$vr['unit_code']))] ?? null;
                foreach (array_filter(array_map('trim', explode(',', (string)$vr['parking']))) as $pc) {
                    $saleLinks[($u['project'] ?? '') . '|' . strtoupper($pc)] = [
                        'unit'   => (string)$vr['unit_code'],
                        'block'  => (string)($u['block'] ?? ''),
                        'client' => trim((string)($vr['client_nom'] ?? '')),
                    ];
                }
            }
        } catch (Throwable $e) {}
    }
    foreach ($parkings as &$pk) {
        $key = $pk['project'] . '|' . strtoupper($pk['code']);
        $l = $links[$key] ?? $links['|' . strtoupper($pk['code'])] ?? null;
        if (!$l && $pk['status'] !== 'disponible') $l = $saleLinks[$key] ?? $saleLinks['|' . strtoupper($pk['code'])] ?? null;
        if ($l) { $pk['linkedUnit'] = $l['unit']; $pk['linkedBlock'] = $l['block']; $pk['linkedClient'] = $l['client']; }
    }
    unset($pk);
}

usort($parkings, fn($a, $b) => strcmp((string)$a['project'] . '|' . (string)$a['code'], (string)$b['project'] . '|' . (string)$b['code']));
usort($celliers, fn($a, $b) => strcmp((string)$a['project'] . '|' . (string)$a['code'], (string)$b['project'] . '|' . (string)$b['code']));

$prefill = [
    'clientType' => 'Personne physique',
    'country' => 'Tunisia',
    'lastName' => '',
    'firstName' => '',
    'address' => '',
    'zip' => '',
    'city' => '',
    'phone1' => '',
    'phone2' => '',
    'email1' => '',
    'email2' => '',
    'idType' => 'CIN',
    'idNumber' => '',
    'maritalStatus' => '',
    'maritalRegime' => '',
    'responsableName' => 'Sarah KAABACHI',
    'responsableRole' => 'Responsable commerciale',
    'validationName' => 'Mohamed ZOUARI',
    'validationRole' => 'CEO',
    'discountApartment' => 0,
    'discountParking' => 0,
    'discountCellier' => 0,
    'discountTotal' => 0,
    'extraWorks' => 0,
    'vatRate' => 19,
    'financeMode' => '1',
    'tranches' => [],
    'selectedParkings' => [],
    'selectedCelliers' => [],
    'coClients' => [],
    'projectName' => $requestedProject,
    'assetType' => 'Appartement',
    'unitCode' => $requestedUnitCodeCanonical,
];

// EVOLO : Réservé / Vendu choisi sur un appartement disponible = nouvelle vente.
// On part d'une fiche vide (on ne reprend pas le client ni la promesse annulée d'avant).
$evoloNewSale = false;
if ($requestedProject === 'EVOLO' && in_array(strtoupper($_GET['status'] ?? ''), ['R', 'V'], true) && $requestedUnitCode !== '') {
    $evoloId = projectIdFromCode($pdo, 'EVOLO');
    if ($evoloId) {
        $st = $pdo->prepare("SELECT status_code FROM units WHERE project_id = ? AND UPPER(TRIM(ref_code)) = UPPER(?) LIMIT 1");
        $st->execute([$evoloId, $requestedUnitCode]);
        $evoloNewSale = strtoupper((string)$st->fetchColumn()) === 'D' && !findActivePromiseForUnit($pdo, $evoloId, $requestedUnitCode);
    }
}

if (!$evoloNewSale && $requestedUnitCode !== '' && tableExists($pdo, 'ventes') && tableExists($pdo, 'clients')) {
    $joinProjects = (columnExists($pdo, 'ventes', 'project_id') && tableExists($pdo, 'projects'))
        ? " LEFT JOIN projects p ON p.id = v.project_id "
        : "";
    $projectSelect = $joinProjects !== '' ? ", p.code AS sale_project_code" : "";
    $searchCodes = array_values(array_unique(array_filter(array_map('strtoupper', $requestedUnitSearchCodes))));
    $placeholders = implode(', ', array_fill(0, count($searchCodes), '?'));
    $sql = "SELECT v.*, c.*$projectSelect
            FROM ventes v
            JOIN clients c ON c.id = v.client_id
            $joinProjects
            WHERE UPPER(v.unit_code) IN ($placeholders)
            ORDER BY v.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($searchCodes);
    $row = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        if ($requestedProject === '' || saleProjectCode($candidate) === $requestedProject) {
            $row = $candidate;
            break;
        }
    }
    if ($row) {
        $fullName = trim((string)pickFirst($row, ['nom_client', 'nom'], ''));
        $parts = preg_split('/\s+/', $fullName, 2) ?: [];
        $prefill['firstName'] = $parts[0] ?? '';
        $prefill['lastName'] = $parts[1] ?? '';
        $prefill['phone1'] = (string)pickFirst($row, ['tel_client', 'tel'], '');
        $prefill['email1'] = (string)pickFirst($row, ['email_client', 'email'], '');
        $prefill['idNumber'] = (string)pickFirst($row, ['cin'], '');
        $prefill['projectName'] = (string)pickFirst($row, ['project_name', 'sale_project_code'], $requestedProject);
        $prefill['assetType'] = (string)pickFirst($row, ['asset_type', 'type_bien'], 'Appartement');
        $prefill['discountApartment'] = (float)pickFirst($row, ['discount_apartment', 'remise_appartement'], 0);
        $prefill['discountParking'] = (float)pickFirst($row, ['discount_parking', 'remise_parking'], 0);
        $prefill['discountCellier'] = (float)pickFirst($row, ['discount_cellier', 'remise_cellier'], 0);
        $prefill['discountTotal'] = (float)pickFirst($row, ['discount_total', 'remise_totale'], 0);
        $prefill['extraWorks'] = (float)pickFirst($row, ['extra_works', 'travaux_supp'], 0);
        $prefill['financeMode'] = (string)pickFirst($row, ['finance_mode', 'mode_financement'], '1');
        if (!empty($row['parking'])) $prefill['selectedParkings'] = array_map('trim', explode(',', (string)$row['parking']));
        if (!empty($row['cellier'])) $prefill['selectedCelliers'] = array_map('trim', explode(',', (string)$row['cellier']));
        if (!empty($row['details_json'])) {
            $json = json_decode((string)$row['details_json'], true);
            if (is_array($json)) {
                foreach (['clientType','country','lastName','firstName','address','zip','city','phone1','phone2','email1','email2','idType','idNumber','maritalStatus','maritalRegime','responsableName','responsableRole','validationName','validationRole','discountApartment','discountParking','discountCellier','discountTotal','extraWorks','vatRate','financeMode','projectName','assetType','unitPriceHt','parkingPrices','cellierPrices'] as $k) {
                    if (array_key_exists($k, $json)) $prefill[$k] = $json[$k];
                }
                if (!empty($json['tranches']) && is_array($json['tranches'])) $prefill['tranches'] = $json['tranches'];
                if (!empty($json['selectedParkings']) && is_array($json['selectedParkings'])) $prefill['selectedParkings'] = $json['selectedParkings'];
                if (!empty($json['selectedCelliers']) && is_array($json['selectedCelliers'])) $prefill['selectedCelliers'] = $json['selectedCelliers'];
                if (isset($json['coClients']) && is_array($json['coClients'])) $prefill['coClients'] = $json['coClients'];
            }
        }
        $prefill['unitCode'] = $requestedUnitCodeCanonical;
    }
}

// ── Charger la promesse existante pour cette unité ───────────────────────
$existingPromise = null;
if (!$evoloNewSale && $requestedUnitCode !== '' && tableExists($pdo, 'promises')) {
    $searchCodes2 = $requestedUnitSearchCodes ?? [$requestedUnitCode];
    $ph2 = implode(', ', array_fill(0, count($searchCodes2), '?'));
    try {
        $stmt2 = $pdo->prepare("
            SELECT p.*, ps.paid_amount, ps.remaining_amount, ps.progress_percent, ps.id AS schedule_id
            FROM promises p
            LEFT JOIN payment_schedules ps ON ps.promise_id = p.id
            WHERE UPPER(p.unit_code) IN ($ph2)
            ORDER BY p.id DESC
        ");
        $stmt2->execute(array_map('strtoupper', $searchCodes2));
        // A1-1..A1-4 existent dans AVA et FLAVA : ne reprendre que la promesse du projet demandé
        $wantedProjectId = $requestedProject !== '' ? projectIdFromCode($pdo, $requestedProject) : null;
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $cand) {
            if (!$wantedProjectId || promiseProjectId($pdo, $cand) === $wantedProjectId) {
                $existingPromise = $cand;
                break;
            }
        }
    } catch (Throwable $e) { $existingPromise = null; }
}

// ── La promesse est la SOURCE DE VÉRITÉ pour parkings/celliers ──────────
// Elle écrase ce que ventes.parking ou ventes.details_json contenaient,
// car c'est dans la promesse que saveFiche() enregistre la sélection définitive.
if ($existingPromise) {
    // 1. Lire depuis promises.parking_codes / cellier_codes (colonnes dédiées)
    if (!empty($existingPromise['parking_codes'])) {
        $prefill['selectedParkings'] = array_values(array_filter(
            array_map('trim', explode(',', (string)$existingPromise['parking_codes']))
        ));
    }
    if (!empty($existingPromise['cellier_codes'])) {
        $prefill['selectedCelliers'] = array_values(array_filter(
            array_map('trim', explode(',', (string)$existingPromise['cellier_codes']))
        ));
    }
    // 2. Surcharger avec promises.details_json si plus précis (avec prix)
    if (!empty($existingPromise['details_json'])) {
        $pdj = json_decode((string)$existingPromise['details_json'], true);
        if (is_array($pdj)) {
            if (!empty($pdj['selectedParkings']) && is_array($pdj['selectedParkings'])) {
                $prefill['selectedParkings'] = array_values(array_filter($pdj['selectedParkings']));
            }
            if (!empty($pdj['selectedCelliers']) && is_array($pdj['selectedCelliers'])) {
                $prefill['selectedCelliers'] = array_values(array_filter($pdj['selectedCelliers']));
            }
            if (!empty($pdj['parkingPrices'])) $prefill['parkingPrices'] = $pdj['parkingPrices'];
            if (!empty($pdj['cellierPrices']))  $prefill['cellierPrices']  = $pdj['cellierPrices'];
            if (!empty($pdj['unitPriceHt']))    $prefill['unitPriceHt']    = $pdj['unitPriceHt'];
        }
    }
}

// ── Client ID de la vente existante (pour pré-remplir JS) ───────────────
$existingClientId = 0;
if (isset($row) && $row && isset($row['client_id'])) {
    $existingClientId = (int)$row['client_id'];
}

// ── Notifications non lues ───────────────────────────────────────────────
$unreadCount = 0;
if (tableExists($pdo, 'notifications')) {
    try { $unreadCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn(); }
    catch (Throwable $e) {}
}

// ── Flags JSON sûrs pour sortie dans <script> ────────────────────────────
$jsf = JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $jsf |= JSON_INVALID_UTF8_SUBSTITUTE;
function safe_js_json($data, int $flags): string {
    $r = @json_encode($data, $flags);
    if ($r === false || $r === null) {
        $r = @json_encode($data, $flags | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    return ($r !== false && $r !== null) ? $r : (is_array($data) ? '[]' : '{}');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Fiche client récapitulative</title>
  <style>
    :root{--bg:#f4f4f4;--paper:#fff;--line:#bdbdbd;--text:#151515;--muted:#5e5e5e;--soft:#efefef;--brand:#145f7d;--brand2:#a56d2d}
    *{box-sizing:border-box} html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif} body{padding:24px}
    .app{display:grid;grid-template-columns:min(560px,42%) minmax(0,1fr);gap:16px;align-items:start;max-width:1700px;margin:0 auto}.panel{background:#fff;border:1px solid #ddd;border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.05);position:sticky;top:18px;max-height:calc(100vh - 36px);overflow:auto;container-type:inline-size;container-name:panel}
    @container panel (max-width:440px){.grid-2,.grid-3,.grid-4{grid-template-columns:1fr!important}.row-mini{grid-template-columns:1fr auto!important}}
    @container panel (min-width:441px) and (max-width:520px){.grid-3,.grid-4{grid-template-columns:1fr 1fr!important}}.panel h1{font-size:20px;margin:0 0 6px}.panel .sub{color:var(--muted);font-size:13px;margin-bottom:16px}.form-section{border-top:1px solid #e5e5e5;padding-top:14px;margin-top:14px}.form-section h2{font-size:14px;margin:0 0 10px;text-transform:uppercase;letter-spacing:.4px}.grid-2,.grid-3,.grid-4{display:grid;gap:10px}.grid-2{grid-template-columns:1fr 1fr}.grid-3{grid-template-columns:repeat(3,1fr)}.grid-4{grid-template-columns:repeat(4,1fr)} label{display:block;font-size:12px;font-weight:700;color:#202020;margin-bottom:4px} input,select,textarea,button{font:inherit} input,select,textarea{width:100%;border:1px solid #d7d7d7;border-radius:10px;padding:10px 12px;background:#fff;font-size:13px} textarea{min-height:72px;resize:vertical}.inline-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}.btn{border:0;border-radius:12px;padding:12px 14px;font-weight:700;cursor:pointer}.btn-primary{background:#111;color:#fff}.btn-secondary{background:#ececec;color:#111}.btn-brand{background:var(--brand);color:#fff}.btn-save{background:#19733e;color:#fff}.row-mini{display:grid;grid-template-columns:1.3fr .8fr .8fr auto;gap:8px;align-items:end;margin-bottom:8px}.paper-wrap{display:flex;flex-direction:column;gap:18px}.paper.paper-land{width:297mm;min-height:210mm;display:flex;flex-direction:column}.land-box{flex:1;width:100%;display:flex;align-items:center;justify-content:center;overflow:hidden;min-height:150mm}.land-box img{max-width:100%;max-height:160mm;width:auto;height:auto;object-fit:contain}.land-box iframe{width:100%;height:160mm;border:0;background:#fff}.pk-pick{position:relative}.pk-btn{width:100%;text-align:left;border:1px solid #d7d7d7;border-radius:10px;padding:10px 12px;background:#fff;font-size:13px;cursor:pointer}.pk-list{display:none;position:absolute;z-index:60;left:0;min-width:100%;width:max-content;max-width:360px;max-height:280px;overflow:auto;background:#fff;border:1px solid #ccc;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15)}.pk-item{padding:6px 10px;font-size:12px;cursor:pointer;white-space:nowrap}.pk-item:hover{background:#eef4f8}.st-r{color:#d35400!important;font-weight:700}.st-v{color:#c62828!important;font-weight:700}.pk-tip{display:none;position:fixed;z-index:100;max-width:320px;background:#222;color:#fff;font-size:12px;padding:6px 9px;border-radius:6px;pointer-events:none}.paper{width:210mm;min-height:297mm;margin:0 auto;background:var(--paper);box-shadow:0 12px 40px rgba(0,0,0,.08);padding:16mm 15mm 14mm;position:relative}.doc-head{margin-bottom:10px}.doc-head-logos{display:grid;grid-template-columns:150px 1fr 150px;align-items:center;gap:8px;border-bottom:2px solid #222;padding-bottom:6px;margin-bottom:4px}.doc-head-title{font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;text-align:left;color:#145f7d;padding:2px 0}.logo-left,.logo-right,.logo-mid{height:74px;display:flex;align-items:center;justify-content:center;font-weight:800;text-align:center;border-radius:6px}.logo-left img,.logo-right img,.logo-mid img{max-height:68px;max-width:138px;object-fit:contain}.doc-date{text-align:right;font-size:11px;color:#555;margin:-2px 0 6px}.title{text-align:center;font-size:21px;font-weight:800;text-transform:uppercase;line-height:1.15}.section-title{margin-top:10px;padding-top:4px;border-top:2px solid #454545;font-size:13px;font-weight:800;margin-bottom:8px} table{border-collapse:collapse;width:100%}.kv td,.kv th,.price-table td,.price-table th,.schedule-table td,.schedule-table th,.sign-table td,.sign-table th,.mini-table td,.mini-table th{border:1px solid var(--line);padding:2px 6px;font-size:12px;vertical-align:middle}.kv td.label,.mini-table td.label,.schedule-table td.label,.sign-table td.label{width:46%;font-weight:700;background:#fff}.kv td.value,.mini-table td.value{background:var(--soft)}.two-col{display:grid;grid-template-columns:1.05fr .9fr;gap:12px;align-items:start}.project-badge{display:inline-block;background:var(--brand);color:#fff;font-weight:800;min-width:160px;padding:1px 8px}.price-table th{font-weight:800;text-align:center;background:#fff}.price-table td{background:#fff}.price-table td.value-grey{background:var(--soft);text-align:center}.price-table td.left-label{width:16%;font-weight:700}.price-table td.left-sub{width:14%;background:var(--soft)}.price-total td{background:var(--brand)!important;color:#fff;font-weight:800;text-align:center;font-size:13px}.price-total td:first-child{text-align:left}.small-note{font-size:10px;line-height:1.45;color:#202020;margin:10px 0 6px}.small-note strong{font-weight:800}.finance-head{display:grid;grid-template-columns:1fr .9fr .75fr;gap:12px;align-items:start}.schedule-grid{display:grid;grid-template-columns:1.5fr .8fr;gap:12px;align-items:start;margin-top:4px}.schedule-table th,.sign-table th{background:#fff;font-weight:800;text-align:center}.sign-table td{background:var(--soft);height:28px}.sign-table .who{width:16%;font-weight:800;background:#fff}.sign-box{height:72px!important;vertical-align:top!important;padding-top:10px!important}.text-right{text-align:right}.text-center{text-align:center}.mb-8{margin-bottom:8px}.mt-10{margin-top:10px}.bloc-head-row{background:#eef4f8!important;color:#145f7d;font-weight:800;font-size:11px;text-transform:uppercase;letter-spacing:.05em;padding:5px 8px!important;border-bottom:2px solid #c8dce8!important;}
    .price-block{margin-bottom:10px;border:1px solid #dde8ee;border-radius:6px;overflow:hidden;break-inside:avoid;page-break-inside:avoid}
    .price-block-header{background:#eef4f8;color:#145f7d;font-weight:800;font-size:12px;text-transform:uppercase;letter-spacing:.05em;padding:5px 10px;border-bottom:1px solid #c8dce8}
    .price-item{display:flex;justify-content:space-between;align-items:baseline;padding:4px 10px;font-size:12px;border-bottom:1px solid #f0f0f0}
    .price-item:last-child{border-bottom:none}
    .price-item .pi-label{color:#333;flex:1}
    .price-item .pi-value{font-variant-numeric:tabular-nums;text-align:right;white-space:nowrap;min-width:160px}
    .price-discount-row .pi-label{color:#991b1b;font-style:italic}
    .price-discount-row .pi-value{color:#991b1b;font-style:italic;font-weight:700}
    .price-net-row .pi-label{font-weight:800;color:#145f7d}
    .price-net-row .pi-value{font-weight:800;color:#145f7d}
    .price-grand-total{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#faf5e4;border:1px solid #e2d099;border-radius:6px;margin-top:8px;break-inside:avoid;page-break-inside:avoid}
    .price-grand-total .pg-label{font-size:13px;font-weight:800;text-transform:uppercase;color:#111}
    .price-grand-total .pg-value{font-size:13px;font-weight:800;color:#111;text-align:right}
    .pct-badge{display:inline-block;background:#fef2f2;color:#991b1b;font-style:normal;font-weight:700;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:4px}
    .sign-section{break-inside:avoid;page-break-inside:avoid}
    .price-table tr.remise-row td{color:#991b1b;font-style:italic}
    .price-table tr.remise-row td.left-sub{background:var(--soft)}
    .price-table tr.net-row td{color:#145f7d;font-weight:700}
    .price-table tr.net-row td.left-sub{background:var(--soft)}
    .pct-badge{display:inline-block;background:#fef2f2;color:#991b1b;font-style:normal;font-weight:700;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:4px}
    .plans-box{border:1px dashed #d1d1d1;border-radius:10px;padding:12px;color:#666;min-height:100px;display:flex;align-items:center;justify-content:center;text-align:center;font-size:12px}.field-locked select,.field-locked input{background:#f0f0f0;color:#555;cursor:not-allowed;border-color:#ccc}.field-locked label::after{content:' 🔒';font-size:10px;color:#888}.btn-lock{background:none;border:1px solid #ccc;border-radius:4px;padding:2px 8px;font-size:14px;cursor:pointer;line-height:1.4;transition:background .15s}.btn-lock[data-locked="1"]{background:#fef3c7;border-color:#f59e0b}
    .flash{display:none;padding:10px 12px;border-radius:10px;font-size:13px;margin-bottom:12px}.flash.ok{display:block;background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}.flash.err{display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca}@media (max-width:900px){.app{display:block}.panel{position:relative;top:auto;max-height:none;margin-bottom:20px}.paper-wrap{display:block}}@media print{@page{size:A4 portrait;margin:16mm 14mm 14mm}@page land{size:A4 landscape;margin:10mm 12mm}html,body{background:#fff}body{padding:0}.panel{display:none!important}.app{display:block;max-width:none}.paper-wrap{gap:0}.paper{box-shadow:none;margin:0;width:100%;min-height:267mm;page-break-after:always;padding:0}.paper:last-child{page-break-after:auto}.paper.paper-land{page:land;width:100%;min-height:188mm}.paper.paper-land.last-page{page-break-after:auto}.land-box{min-height:0}.land-box img{max-height:145mm!important}.land-box iframe{height:145mm!important}.price-section{page-break-inside:avoid;break-inside:avoid}}
    /* EVOLO : pas de cellier */
    body.no-cellier .cel-only{display:none !important}
  </style>
</head>
<body>
  <div class="app">
    <aside class="panel">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
        <h1 style="margin:0">Formulaire fiche client</h1>
        <div style="display:flex;gap:8px;align-items:center">
          <a href="paiements.php" title="Suivi des paiements" style="text-decoration:none;font-size:20px">📊</a>
          <button type="button" id="notifBell" onclick="toggleNotifPanel()" title="Notifications" style="position:relative;background:none;border:none;cursor:pointer;font-size:20px;padding:2px">
            🔔<span id="notifBadge" style="display:<?= $unreadCount > 0 ? 'inline-flex' : 'none' ?>;position:absolute;top:-4px;right:-4px;background:#c03030;color:#fff;border-radius:50%;font-size:10px;font-weight:700;width:16px;height:16px;align-items:center;justify-content:center"><?= $unreadCount ?></span>
          </button>
        </div>
      </div>
      <div id="notifPanel" style="display:none;background:#fefce8;border:1px solid #fde68a;border-radius:10px;padding:10px;margin-bottom:10px;max-height:200px;overflow-y:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <span style="font-size:12px;font-weight:700;color:#92400e">🔔 Alertes échéances</span>
          <button type="button" onclick="markAllRead()" style="font-size:11px;background:#145f7d;color:#fff;border:none;border-radius:6px;padding:2px 8px;cursor:pointer">Tout lire</button>
        </div>
        <div id="notifList"><span style="font-size:12px;color:#888">Chargement...</span></div>
      </div>
      <div id="flashBox" class="flash"></div>

      <div class="form-section">
        <h2>1. Informations client</h2>

        <!-- Bloc A : Identité -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin-bottom:10px">
          <div style="font-size:11px;font-weight:800;color:#145f7d;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Identité</div>
          <div class="grid-2">
            <div><label>Type de client</label><select id="clientType"><option>Personne physique</option><option>Personne morale</option></select></div>
            <div><label>Pays / Nationalité</label><input id="country"></div>
            <div><label>Nom</label><input id="lastName"></div>
            <div><label>Prénom</label><input id="firstName"></div>
            <div><label>Situation matrimoniale</label><input id="maritalStatus" placeholder="ex : Marié(e)"></div>
            <div><label>Régime matrimonial</label><input id="maritalRegime" placeholder="ex : Communauté réduite"></div>
          </div>
        </div>

        <!-- Bloc B : Pièce d'identité -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin-bottom:10px">
          <div style="font-size:11px;font-weight:800;color:#145f7d;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Pièce d'identité</div>
          <div class="grid-2">
            <div><label>Type de pièce d'identité</label><select id="idType"><option>CIN</option><option>Passeport</option></select></div>
            <div><label>Numéro de pièce d'identité</label><input id="idNumber" placeholder="Ex : 12345678"><span id="idNumberHint" style="font-size:11px;color:#888;margin-top:3px;display:block"></span></div>
          </div>
        </div>

        <!-- Bloc C : Coordonnées -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px">
          <div style="font-size:11px;font-weight:800;color:#145f7d;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Coordonnées</div>
          <div><label>Adresse</label><textarea id="address" style="min-height:56px"></textarea></div>
          <div class="grid-2 mt-10">
            <div><label>Code postal</label><input id="zip"></div>
            <div><label>Ville</label><input id="city"></div>
          </div>
          <div class="grid-2 mt-10">
            <div><label>Téléphone principal</label><input id="phone1"></div>
            <div><label>Téléphone 2</label><input id="phone2"></div>
          </div>
          <div class="grid-2 mt-10">
            <div><label>Email principal</label><input id="email1"></div>
            <div><label>Email 2</label><input id="email2"></div>
          </div>
        </div>

        <!-- Co-acquéreurs -->
        <div id="co-client-forms"></div>
        <button type="button" onclick="addCoClientForm()"
          style="width:100%;margin-top:10px;padding:10px 0;background:#f0f7ff;color:#145f7d;border:1px dashed #b8d4f0;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">
          ➕ Ajouter un co-acquéreur
        </button>
      </div>

      <div class="form-section">
        <h2>2. Bien réservé</h2>
        <div class="grid-2">
          <div><label>Projet</label><select id="projectName"><option>AVA</option><option>FLAVA</option><option>EVOLO</option></select></div>
          <div><label>Type de bien</label><input id="assetType"></div>
          <div><label>Référence du bien</label><select id="unitCode"></select></div>
          <div><label>Prix appartement HT</label><input id="unitPriceHt" type="number" step="0.001"></div>
        </div>
        <div class="grid-4 mt-10">
          <div><label>Surface couverte</label><input id="coveredArea" type="number" step="0.001"></div>
          <div><label>Surface découverte</label><input id="openArea" type="number" step="0.001"></div>
          <div><label>SHO</label><input id="shoArea" type="number" step="0.001"></div>
          <div><label>PC</label><input id="pcArea" type="number" step="0.001"></div>
        </div>
        <div class="form-section"><h2>Parkings</h2><div id="parkingRows"></div><button type="button" class="btn btn-secondary" id="addParkingBtn">+ Ajouter parking</button></div>
        <div class="form-section"><h2>Celliers</h2><div id="cellierRows"></div><button type="button" class="btn btn-secondary" id="addCellierBtn">+ Ajouter cellier</button></div>
      </div>

      <div class="form-section">
        <h2>3. Prix et remises</h2>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
          <span style="font-size:13px;font-weight:700">Remise</span>
          <button type="button" id="discountToggle" class="btn btn-secondary" style="padding:6px 16px;font-size:12px">Désactivée</button>
        </div>
        <div id="discountSection" style="display:none">
          <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:10px;margin-bottom:12px">
            <div style="font-size:12px;font-weight:700;margin-bottom:6px;color:#b45309">Remise globale (sur le total)</div>
            <div class="grid-2">
              <div><label>Remise globale %</label><input id="discountGlobalPct" type="number" step="0.01" min="0" max="100" placeholder="0"></div>
              <div><label>Valeur remise HT</label><input id="discountGlobalVal" type="number" step="0.01" placeholder="0" readonly style="background:#f5f5f5;color:#555"></div>
            </div>
            <div style="font-size:11px;color:#92400e;margin-top:6px">Applique le même % à toutes les catégories non verrouillées</div>
          </div>
          <div style="background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;padding:10px;margin-bottom:8px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
              <div style="font-size:12px;font-weight:700;color:#145f7d">Appartement</div>
              <button type="button" class="btn-lock" id="lockApartment" data-locked="0" onclick="toggleLock('apartment')">🔓</button>
            </div>
            <div class="grid-2">
              <div><label>Remise %</label><input id="discountApartmentPct" type="number" step="0.01" min="0" max="100" placeholder="0"></div>
              <div><label>Prix après remise (HT)</label><input id="discountApartmentNet" type="number" step="0.01" placeholder="0"></div>
            </div>
          </div>
          <div style="background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;padding:10px;margin-bottom:8px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
              <div style="font-size:12px;font-weight:700;color:#145f7d">Parking (total)</div>
              <button type="button" class="btn-lock" id="lockParking" data-locked="0" onclick="toggleLock('parking')">🔓</button>
            </div>
            <div class="grid-2">
              <div><label>Remise %</label><input id="discountParkingPct" type="number" step="0.01" min="0" max="100" placeholder="0"></div>
              <div><label>Prix après remise (HT)</label><input id="discountParkingNet" type="number" step="0.01" placeholder="0"></div>
            </div>
          </div>
          <div style="background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;padding:10px;margin-bottom:8px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
              <div style="font-size:12px;font-weight:700;color:#145f7d">Cellier (total)</div>
              <button type="button" class="btn-lock" id="lockCellier" data-locked="0" onclick="toggleLock('cellier')">🔓</button>
            </div>
            <div class="grid-2">
              <div><label>Remise %</label><input id="discountCellierPct" type="number" step="0.01" min="0" max="100" placeholder="0"></div>
              <div><label>Prix après remise (HT)</label><input id="discountCellierNet" type="number" step="0.01" placeholder="0"></div>
            </div>
          </div>
          <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:8px;padding:10px;margin-top:8px">
            <div style="font-size:12px;font-weight:700;margin-bottom:6px;color:#2e7d32">Prix cible TTC</div>
            <div style="display:flex;gap:8px;align-items:center">
              <input id="prixCibleInput" type="number" step="0.001" placeholder="ex: 2000000" style="flex:1;padding:6px 8px;border:1px solid #a5d6a7;border-radius:6px;font-size:13px">
              <button type="button" onclick="applyPrixCible()" style="padding:6px 14px;background:#2e7d32;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;white-space:nowrap">Appliquer sur appart.</button>
            </div>
            <div style="font-size:11px;color:#388e3c;margin-top:4px">Ajuste la remise appartement pour atteindre exactement ce total TTC (parking/cellier gardent leur remise).</div>
          </div>
        </div>
        <input type="hidden" id="discountApartment" value="0">
        <input type="hidden" id="discountParking" value="0">
        <input type="hidden" id="discountCellier" value="0">
        <input type="hidden" id="discountTotal" value="0">
        <div class="grid-2 mt-10">
          <div><label>Prix travaux supp.</label><input id="extraWorks" type="number" step="0.001"></div>
          <div><label>TVA %</label><input id="vatRate" type="number" step="0.001"></div>
        </div>
        <div class="mt-10">
          <div><label>Mode de financement</label><input id="financeMode"></div>
        </div>
      </div>

      <div class="form-section"><h2>4. Échéancier manuel</h2><div id="trancheRows"></div><button type="button" class="btn btn-secondary" id="addTrancheBtn">+ Ajouter tranche</button><div id="trancheWarning" style="display:none;color:#c00;font-size:12px;font-weight:700;margin-top:6px;padding:6px 10px;background:#fff0f0;border-radius:6px;border:1px solid #fcc"></div></div>
      <div class="form-section">
        <h2>5. Signatures</h2>
        <div class="grid-2">
          <div><label>Responsable commerciale</label><input id="responsableName"></div>
          <div><label>Qualité responsable</label><input id="responsableRole"></div>
          <div><label>Validation</label><input id="validationName"></div>
          <div><label>Qualité validation</label><input id="validationRole"></div>
        </div>
      </div>
      <div class="inline-actions">
        <button class="btn btn-save" type="button" id="saveBtn">Enregistrer</button>
        <button class="btn btn-brand" type="button" id="printBtn">Imprimer la fiche</button>
        <button class="btn" type="button" id="optionVenteBtn" style="background:#f0f7ff;color:#145f7d;border:1px solid #b8d4f0" onclick="openOptionVente()">📄 Option de vente</button>
        <a class="btn btn-secondary" href="javascript:history.back()">Retour</a>
      </div>

      <!-- ── Suivi promesse ─────────────────────────────── -->
      <div class="form-section" id="promiseSuivi">
        <h2>Suivi promesse de vente</h2>
        <input type="hidden" id="promiseId" value="<?= $existingPromise ? (int)$existingPromise['id'] : '' ?>">
        <input type="hidden" id="clientId" value="<?= $existingClientId ?>">

        <?php if ($existingPromise): ?>
          <?php
            $pStatus = $existingPromise['status'] ?? 'draft';
            $pNum    = htmlspecialchars($existingPromise['promise_number'] ?? '');
            $pColors = ['draft'=>'#e2eef5','signed'=>'#ecfdf5','cancelled'=>'#fef2f2','converted_to_sale'=>'#fef3c7'];
            $pLabels = ['draft'=>'Brouillon','signed'=>'Signée ✅','cancelled'=>'Annulée','converted_to_sale'=>'Vendue'];
            $pColor  = $pColors[$pStatus] ?? '#f4f6f8';
            $pLabel  = $pLabels[$pStatus] ?? $pStatus;
            $paid    = (float)($existingPromise['paid_amount'] ?? 0);
            $total   = 0;
            if ($existingPromise['schedule_id']) {
                try {
                    $r = $pdo->prepare("SELECT total_amount FROM payment_schedules WHERE id=?");
                    $r->execute([$existingPromise['schedule_id']]);
                    $total = (float)($r->fetchColumn() ?: 0);
                } catch (Throwable $e) {}
            }
            $progress = ($total > 0) ? round($paid / $total * 100, 1) : 0;
          ?>
          <div style="background:<?= $pColor ?>;border-radius:8px;padding:10px;margin-bottom:10px">
            <div style="font-size:12px;font-weight:700;color:#145f7d">N° <?= $pNum ?></div>
            <div style="font-size:13px;font-weight:800;margin-top:2px"><?= $pLabel ?></div>
            <?php if ($total > 0): ?>
            <div style="margin-top:8px">
              <div style="font-size:11px;color:#555;margin-bottom:3px">Paiements : <?= number_format($paid,3,',',' ') ?> / <?= number_format($total,3,',',' ') ?> TND (<?= $progress ?>%)</div>
              <div style="height:6px;background:#e0e0e0;border-radius:4px;overflow:hidden">
                <div style="width:<?= $progress ?>%;height:100%;background:<?= $progress >= 100 ? '#19733e' : '#145f7d' ?>;border-radius:4px"></div>
              </div>
            </div>
            <?php endif; ?>
          </div>

          <?php if ($pStatus === 'draft'): ?>
          <button type="button" onclick="marquerSignee()" class="btn" style="width:100%;background:#ecfdf5;color:#166534;border:1px solid #bbf7d0;margin-bottom:8px;font-size:13px">
            ✅ Marquer promesse signée
          </button>
          <?php endif; ?>

          <div style="margin-bottom:8px">
            <label style="font-size:12px;font-weight:700">Justificatif de virement (PDF / image)</label>
            <div style="display:flex;gap:8px;margin-top:6px">
              <input type="file" id="docUpload" accept=".pdf,.jpg,.jpeg,.png" style="flex:1;font-size:12px">
              <button type="button" onclick="uploadDocument()" class="btn btn-brand" style="padding:8px 12px;font-size:12px;white-space:nowrap">⬆ Upload</button>
            </div>
            <div id="docUploadStatus" style="font-size:12px;margin-top:4px"></div>
            <div id="docList" style="margin-top:8px"></div>
          </div>

          <a href="paiements.php?promise_id=<?= (int)$existingPromise['id'] ?>" class="btn btn-secondary" style="display:block;text-align:center;text-decoration:none;font-size:13px">
            📊 Voir suivi des paiements
          </a>
        <?php else: ?>
          <div style="background:#f4f6f8;border-radius:8px;padding:10px;font-size:13px;color:#666">
            Enregistrez la fiche pour créer la promesse de vente.
          </div>
        <?php endif; ?>
      </div>
    </aside>

    <main class="paper-wrap">
      <section class="paper">
        <div class="doc-head"><div class="doc-head-logos"><div class="logo-left"><img src="assets/images/LogoPROMED.png" alt="Logo Promed"></div><div class="logo-mid"><img id="logoProjectTop" src="" alt="Logo projet"></div><div class="logo-right"><img src="assets/images/PromedGroup.png" alt="Logo Promed Group"></div></div><div class="doc-date" id="docDateTop"></div><div class="doc-head-title">Fiche client récapitulative pour promesse de vente</div></div>
        <div class="section-title" style="border-top:none">1. Informations sur le client</div>
        <table class="kv">
          <tr><td colspan="4" class="bloc-head-row">Identité</td></tr>
          <tr>
            <td class="label">Type de client</td><td class="value" id="pv_clientType"></td>
            <td class="label">Pays / Nationalité</td><td class="value" id="pv_country"></td>
          </tr>
          <tr>
            <td class="label">Nom</td><td class="value" id="pv_lastName"></td>
            <td class="label">Prénom</td><td class="value" id="pv_firstName"></td>
          </tr>
          <tr>
            <td class="label">Situation matrimoniale</td><td class="value" id="pv_maritalStatus"></td>
            <td class="label">Régime matrimonial</td><td class="value" id="pv_maritalRegime"></td>
          </tr>
          <tr><td colspan="4" class="bloc-head-row">Pièce d'identité</td></tr>
          <tr>
            <td class="label">Type de pièce</td><td class="value" id="pv_idType"></td>
            <td class="label">Numéro</td><td class="value" id="pv_idNumber"></td>
          </tr>
          <tr><td colspan="4" class="bloc-head-row">Coordonnées</td></tr>
          <tr>
            <td class="label">Adresse</td><td class="value" colspan="3" id="pv_address"></td>
          </tr>
          <tr>
            <td class="label">Code postal</td><td class="value" id="pv_zip"></td>
            <td class="label">Ville</td><td class="value" id="pv_city"></td>
          </tr>
          <tr>
            <td class="label">Téléphone principal</td><td class="value" id="pv_phone1"></td>
            <td class="label">Téléphone 2</td><td class="value" id="pv_phone2"></td>
          </tr>
          <tr>
            <td class="label">Email principal</td><td class="value" id="pv_email1"></td>
            <td class="label">Email 2</td><td class="value" id="pv_email2"></td>
          </tr>
        </table>
        <div id="co-clients-doc-preview"></div>
        <div class="section-title">2. Informations sur le bien réservé</div>
        <div class="two-col">
          <div>
            <table class="kv">
              <tr><td class="label">Type de bien *</td><td class="value" id="pv_assetType"></td></tr>
              <tr><td class="label">Nom du projet</td><td class="value"><span class="project-badge" id="pv_projectName"></span></td></tr>
              <tr><td class="label">Référence du bien</td><td class="value"><span class="project-badge" id="pv_unitCode"></span></td></tr>
              <tr><td class="label">Parking</td><td class="value" id="pv_hasParking"></td></tr>
              <tr><td class="label">Nombre de parking</td><td class="value" id="pv_parkingCount"></td></tr>
              <tbody id="parkingPreviewBody"></tbody>
              <tr><td class="label">Cellier</td><td class="value" id="pv_hasCellier"></td></tr>
              <tr><td class="label">Nombre de Celier</td><td class="value" id="pv_cellierCount"></td></tr>
              <tbody id="cellierPreviewBody"></tbody>
            </table>
          </div>
          <div>
            <table class="mini-table">
              <tr><td class="label">Surface de vente (m²)</td><td class="value text-right" id="pv_saleArea"></td></tr>
              <tr><td class="label">Surface couverte (m²)</td><td class="value text-right" id="pv_coveredArea"></td></tr>
              <tr><td class="label">Surface découverte (m²)</td><td class="value text-right" id="pv_openArea"></td></tr>
              <tr><td class="label">SHO (m²)</td><td class="value text-right" id="pv_shoArea"></td></tr>
              <tr><td class="label">PC (m²)</td><td class="value text-right" id="pv_pcArea"></td></tr>
            </table>
            <div class="mt-10" id="cellierSurfaceTable"></div>
          </div>
        </div>
        <div class="price-section">
        <div class="section-title">3. Détail du prix de vente</div>
        <table class="price-table">
          <thead>
            <tr><th style="width:40%;text-align:left"></th><th>HT</th><th>TVA (<span id="pv_vatLabel">19</span>%)</th><th>TTC</th></tr>
          </thead>
          <tbody>
            <tr><td class="left-label">Prix Appartement</td><td class="value-grey text-right" id="pv_priceApartmentHt"></td><td class="value-grey text-right" id="pv_priceApartmentVat"></td><td class="value-grey text-right" id="pv_priceApartmentTtc"></td></tr>
            <tr id="tr_discountApartment" class="remise-row" style="display:none"><td class="left-sub">Remise <span id="pv_discountApartmentPct" class="pct-badge"></span></td><td class="value-grey text-right" id="pv_discountApartment"></td><td class="value-grey text-right" id="pv_discountApartmentVat"></td><td class="value-grey text-right" id="pv_discountApartmentTtc"></td></tr>
            <tr id="tr_priceApartmentNet" class="net-row" style="display:none"><td class="left-sub">Prix net appartement</td><td class="value-grey text-right" id="pv_priceApartmentNet"></td><td class="value-grey text-right" id="pv_priceApartmentNetVat"></td><td class="value-grey text-right" id="pv_priceApartmentNetTtc"></td></tr>

            <tr><td class="left-label">Prix Parking</td><td class="value-grey text-right" id="pv_priceParkingHt"></td><td class="value-grey text-right" id="pv_priceParkingVat"></td><td class="value-grey text-right" id="pv_priceParkingTtc"></td></tr>
            <tr id="tr_discountParking" class="remise-row" style="display:none"><td class="left-sub">Remise <span id="pv_discountParkingPct" class="pct-badge"></span></td><td class="value-grey text-right" id="pv_discountParking"></td><td class="value-grey text-right" id="pv_discountParkingVat"></td><td class="value-grey text-right" id="pv_discountParkingTtc"></td></tr>
            <tr id="tr_priceParkingNet" class="net-row" style="display:none"><td class="left-sub">Prix net parking</td><td class="value-grey text-right" id="pv_priceParkingNet"></td><td class="value-grey text-right" id="pv_priceParkingNetVat"></td><td class="value-grey text-right" id="pv_priceParkingNetTtc"></td></tr>

            <tr><td class="left-label">Prix Cellier</td><td class="value-grey text-right" id="pv_priceCellierHt"></td><td class="value-grey text-right" id="pv_priceCellierVat"></td><td class="value-grey text-right" id="pv_priceCellierTtc"></td></tr>
            <tr id="tr_discountCellier" class="remise-row" style="display:none"><td class="left-sub">Remise <span id="pv_discountCellierPct" class="pct-badge"></span></td><td class="value-grey text-right" id="pv_discountCellier"></td><td class="value-grey text-right" id="pv_discountCellierVat"></td><td class="value-grey text-right" id="pv_discountCellierTtc"></td></tr>
            <tr id="tr_priceCellierNet" class="net-row" style="display:none"><td class="left-sub">Prix net cellier</td><td class="value-grey text-right" id="pv_priceCellierNet"></td><td class="value-grey text-right" id="pv_priceCellierNetVat"></td><td class="value-grey text-right" id="pv_priceCellierNetTtc"></td></tr>

            <tr><td class="left-label">Prix travaux supp.</td><td class="value-grey text-right" id="pv_extraWorksHt"></td><td class="value-grey text-right" id="pv_extraWorksVat"></td><td class="value-grey text-right" id="pv_extraWorksTtc"></td></tr>
            <tr class="price-total"><td style="font-size:15px;padding:6px 8px">PRIX TOTAL</td><td id="pv_totalHt" style="font-size:15px;padding:6px 8px"></td><td id="pv_totalVat" style="font-size:15px;padding:6px 8px"></td><td id="pv_totalTtc" style="font-size:15px;padding:6px 8px"></td></tr>
            <tr id="tr_discountTotal" style="display:none"><td class="left-sub">Remise globale</td><td class="value-grey text-right" id="pv_discountTotal"></td><td class="value-grey text-right" id="pv_discountTotalPct"></td><td class="value-grey"></td></tr>
            <tr id="tr_netTotal" style="display:none" class="price-total"><td>Prix net total</td><td id="pv_netTotalHt"></td><td id="pv_netTotalVat"></td><td id="pv_netTotalTtc"></td></tr>
          </tbody>
        </table>
        </div>
        <div class="section-title">4. Modalités financières</div>
        <div class="finance-head">
          <table class="kv"><tr><td class="label">Mode de financement</td><td class="value" id="pv_financeMode"></td></tr><tr><td class="label">Fonds propres</td><td class="value"></td></tr></table>
          <table class="kv"><tr><td class="label">Montant (%)</td><td class="value text-right" id="pv_financePct"></td></tr></table>
          <table class="kv"><tr><td class="label">Montant (TTC)</td><td class="value text-right" id="pv_financeTtc"></td></tr></table>
        </div>
        <div class="small-note"><strong>Clause de paiement</strong><br><br>Tous les paiements relatifs à la présente réservation doivent être exclusivement libellés au nom de promed immobilière et effectués par un moyen de paiement garantissant leur traçabilité, à savoir un virement bancaire ou un chèque libellé au nom de la société.<br>Les paiements par virement bancaire doivent être effectués sur le compte suivant :<br><br><strong>Titulaire du compte : promed immobilière</strong><br><strong>Banque : Banque Zitouna</strong><br><strong>RIB : 25 000000000000420307</strong><br><strong>IBAN : TN59 25 000000000000420307</strong><br><strong>BIC/SWIFT : BZITTNTT</strong><br><br>Tout changement de coordonnées bancaires fera l'objet d'une notification officielle par un moyen laissant une trace écrite.<br>Tout paiement effectué au nom d'un tiers ou sur un compte autre que celui spécifié ci-dessus ne sera pas reconnu et ne saurait engager la responsabilité de promed immobilière. La réservation ne sera considérée comme valide qu'après confirmation écrite de la réception effective des fonds sur les comptes bancaires de la société.</div>
        <div class="mb-8"><strong>Échéancier de paiement</strong></div>
        <table class="schedule-table">
          <thead>
            <tr><th class="label">Nombre de tranches</th><th colspan="3" id="pv_trancheCount"></th></tr>
            <tr><th>Tranche</th><th>Pourcentage (%)</th><th>Montant (TTC)</th><th>Date</th></tr>
          </thead>
          <tbody id="scheduleBody"></tbody>
        </table>
        <div class="sign-section">
        <div class="section-title" style="margin-top:22px">5. Validation et signatures</div>
        <table class="sign-table">
          <tr><th class="who"></th><th>Nom et prénom</th><th>Date et Signature</th></tr>
          <tr><td class="who">Client</td><td class="text-center" id="pv_signClient"></td><td class="sign-box">-- / -- / ----</td></tr>
          <tr><td class="who">Responsable</td><td><div class="text-center" id="pv_signResp"></div><table class="kv" style="margin-top:8px"><tr><td class="label">Qualité :</td><td class="value" id="pv_signRespRole"></td></tr></table></td><td class="sign-box">-- / -- / ----</td></tr>
          <tr><td class="who">Validation</td><td><div class="text-center" id="pv_signVal"></div><table class="kv" style="margin-top:8px"><tr><td class="label">Qualité :</td><td class="value" id="pv_signValRole"></td></tr></table></td><td class="sign-box">-- / -- / ----</td></tr>
        </table>
        </div>
      </section>

      <section class="paper paper-land" id="pv_planPage">
        <div class="doc-head"><div class="doc-head-logos"><div class="logo-left"><img src="assets/images/LogoPROMED.png" alt="Logo Promed"></div><div class="logo-mid"><img id="logoProjectPlan1" src="" alt="Logo projet"></div><div class="logo-right"><img src="assets/images/PromedGroup.png" alt="Logo Promed Group"></div></div></div>
        <div class="section-title" style="border-top:none">6. Plan de l'appartement — <span id="pv_planUnit"></span></div>
        <div id="pv_planUnitBox" class="land-box">
          <img id="pv_planUnitImg" src="" alt="Plan appartement" style="display:none">
          <iframe id="pv_planUnitPdf" title="Plan appartement" style="display:none"></iframe>
          <span id="pv_planUnitNoImg" style="padding:20px;color:#aaa;font-size:12px">Aucun plan disponible</span>
        </div>
      </section>
      <div id="pv_accPages"></div>
    </main>
  </div>
<script>
const DB={units:<?php echo safe_js_json($units,$jsf); ?>,parkings:<?php echo safe_js_json($parkings,$jsf); ?>,celliers:<?php echo safe_js_json($celliers,$jsf); ?>};
const LOCKED_FROM_URL=<?php echo ($requestedUnitCode !== '') ? 'true' : 'false'; ?>;
const PREFILL=<?php echo safe_js_json($prefill,$jsf); ?>;
const SESSION_USER=<?php echo safe_js_json(['username'=>$sessionUsername,'fullName'=>$sessionFullName,'role'=>$sessionRole],$jsf); ?>;
const ids=['clientType','country','lastName','firstName','address','zip','city','phone1','phone2','email1','email2','idType','idNumber','maritalStatus','maritalRegime','projectName','assetType','unitCode','unitPriceHt','coveredArea','openArea','shoArea','pcArea','discountApartment','discountParking','discountCellier','discountTotal','discountGlobalPct','discountGlobalVal','discountApartmentPct','discountApartmentNet','discountParkingPct','discountParkingNet','discountCellierPct','discountCellierNet','extraWorks','vatRate','financeMode','responsableName','responsableRole','validationName','validationRole'];
const els={}; ids.forEach(id=>els[id]=document.getElementById(id)); const parkingRows=document.getElementById('parkingRows'); const cellierRows=document.getElementById('cellierRows'); const trancheRows=document.getElementById('trancheRows'); const flashBox=document.getElementById('flashBox');
const fmt=(v,d=3)=>Number(v||0).toLocaleString('fr-FR',{minimumFractionDigits:d,maximumFractionDigits:d}); const pct=(v,d=2)=>Number(v||0).toLocaleString('fr-FR',{minimumFractionDigits:d,maximumFractionDigits:d})+'%'; const setText=(id,val)=>{const _e=document.getElementById(id);if(_e)_e.textContent=val??'';};
function flash(msg,type='ok'){flashBox.className='flash '+type;flashBox.textContent=msg;}

/* ── Co-acquéreurs ─────────────────────────────────────── */
let _coIdx = 0;
const CO_FIELDS = ['clientType','country','lastName','firstName','maritalStatus','maritalRegime','idType','idNumber','address','zip','city','phone1','phone2','email1','email2'];

function addCoClientForm(data) {
  data = data || {};
  const idx = ++_coIdx;
  const v = f => data[f] ? String(data[f]).replace(/"/g,'&quot;') : '';
  const sel = (id, val) => { const el = document.getElementById(id); if(el && val) el.value = val; };
  const container = document.getElementById('co-client-forms');
  const wrap = document.createElement('div');
  wrap.dataset.coIdx = idx;
  wrap.style.cssText = 'margin-top:14px;border:1px solid #b8d4f0;border-radius:10px;padding:12px;background:#f7fbff;position:relative';
  wrap.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <span style="font-size:12px;font-weight:800;color:#145f7d;text-transform:uppercase;letter-spacing:.05em">Co-acquéreur ${idx}</span>
      <button type="button" onclick="removeCoClientForm(${idx})" style="background:none;border:none;color:#c0392b;font-size:18px;cursor:pointer;line-height:1" title="Supprimer">✕</button>
    </div>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;margin-bottom:8px">
      <div style="font-size:11px;font-weight:700;color:#145f7d;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">Identité</div>
      <div class="grid-2">
        <div><label>Type de client</label><select id="co_${idx}_clientType" oninput="refreshPreview()"><option>Personne physique</option><option>Personne morale</option></select></div>
        <div><label>Pays / Nationalité</label><input id="co_${idx}_country" value="${v('country')}" oninput="refreshPreview()"></div>
        <div><label>Nom</label><input id="co_${idx}_lastName" value="${v('lastName')}" oninput="refreshPreview()"></div>
        <div><label>Prénom</label><input id="co_${idx}_firstName" value="${v('firstName')}" oninput="refreshPreview()"></div>
        <div><label>Situation matrimoniale</label><input id="co_${idx}_maritalStatus" value="${v('maritalStatus')}" placeholder="ex : Marié(e)" oninput="refreshPreview()"></div>
        <div><label>Régime matrimonial</label><input id="co_${idx}_maritalRegime" value="${v('maritalRegime')}" placeholder="ex : Communauté réduite" oninput="refreshPreview()"></div>
      </div>
    </div>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;margin-bottom:8px">
      <div style="font-size:11px;font-weight:700;color:#145f7d;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">Pièce d'identité</div>
      <div class="grid-2">
        <div><label>Type de pièce</label><select id="co_${idx}_idType" oninput="refreshPreview()"><option>CIN</option><option>Passeport</option></select></div>
        <div><label>Numéro</label><input id="co_${idx}_idNumber" value="${v('idNumber')}" oninput="refreshPreview()"></div>
      </div>
    </div>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px">
      <div style="font-size:11px;font-weight:700;color:#145f7d;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">Coordonnées</div>
      <div><label>Adresse</label><textarea id="co_${idx}_address" style="min-height:48px" oninput="refreshPreview()">${v('address')}</textarea></div>
      <div class="grid-2 mt-10">
        <div><label>Code postal</label><input id="co_${idx}_zip" value="${v('zip')}" oninput="refreshPreview()"></div>
        <div><label>Ville</label><input id="co_${idx}_city" value="${v('city')}" oninput="refreshPreview()"></div>
      </div>
      <div class="grid-2 mt-10">
        <div><label>Téléphone principal</label><input id="co_${idx}_phone1" value="${v('phone1')}" oninput="refreshPreview()"></div>
        <div><label>Téléphone 2</label><input id="co_${idx}_phone2" value="${v('phone2')}" oninput="refreshPreview()"></div>
      </div>
      <div class="grid-2 mt-10">
        <div><label>Email principal</label><input id="co_${idx}_email1" value="${v('email1')}" oninput="refreshPreview()"></div>
        <div><label>Email 2</label><input id="co_${idx}_email2" value="${v('email2')}" oninput="refreshPreview()"></div>
      </div>
    </div>
  `;
  container.appendChild(wrap);
  sel(`co_${idx}_clientType`, data.clientType);
  sel(`co_${idx}_idType`, data.idType);
  refreshPreview();
}

function removeCoClientForm(idx) {
  const el = document.querySelector(`[data-co-idx="${idx}"]`);
  if (el) { el.remove(); refreshPreview(); }
}

function getCoClients() {
  return Array.from(document.querySelectorAll('#co-client-forms [data-co-idx]')).map(wrap => {
    const idx = wrap.dataset.coIdx;
    const get = f => (document.getElementById(`co_${idx}_${f}`)?.value || '');
    return { clientType:get('clientType'), country:get('country'), lastName:get('lastName'), firstName:get('firstName'), maritalStatus:get('maritalStatus'), maritalRegime:get('maritalRegime'), idType:get('idType'), idNumber:get('idNumber'), address:get('address'), zip:get('zip'), city:get('city'), phone1:get('phone1'), phone2:get('phone2'), email1:get('email1'), email2:get('email2') };
  });
}

function renderCoClientsDoc() {
  const co = getCoClients();
  const preview = document.getElementById('co-clients-doc-preview');
  if (!preview) return;
  preview.innerHTML = co.map((c, i) => `
    <div class="section-title" style="margin-top:12px">1.${i+2}. Co-acquéreur ${i+1}</div>
    <table class="kv">
      <tr><td colspan="4" class="bloc-head-row">Identité</td></tr>
      <tr><td class="label">Type de client</td><td class="value">${c.clientType}</td><td class="label">Pays / Nationalité</td><td class="value">${c.country}</td></tr>
      <tr><td class="label">Nom</td><td class="value">${c.lastName}</td><td class="label">Prénom</td><td class="value">${c.firstName}</td></tr>
      <tr><td class="label">Situation matrimoniale</td><td class="value">${c.maritalStatus}</td><td class="label">Régime matrimonial</td><td class="value">${c.maritalRegime}</td></tr>
      <tr><td colspan="4" class="bloc-head-row">Pièce d'identité</td></tr>
      <tr><td class="label">Type de pièce</td><td class="value">${c.idType}</td><td class="label">Numéro</td><td class="value">${c.idNumber}</td></tr>
      <tr><td colspan="4" class="bloc-head-row">Coordonnées</td></tr>
      <tr><td class="label">Adresse</td><td class="value" colspan="3">${c.address}</td></tr>
      <tr><td class="label">Code postal</td><td class="value">${c.zip}</td><td class="label">Ville</td><td class="value">${c.city}</td></tr>
      <tr><td class="label">Téléphone principal</td><td class="value">${c.phone1}</td><td class="label">Téléphone 2</td><td class="value">${c.phone2}</td></tr>
      <tr><td class="label">Email principal</td><td class="value">${c.email1}</td><td class="label">Email 2</td><td class="value">${c.email2}</td></tr>
    </table>
  `).join('');
}
// Prix déjà calculés pour le mois par le serveur (même calcul que la Liste des prix, blocage compris)
function indexedPrice(price){return Number(price)||0;}
function getParkingHt(p,project){return Number(p.priceHt)||0;}
let discountEnabled=false;
const discountLocks={apartment:false,parking:false,cellier:false};
function toggleLock(key){discountLocks[key]=!discountLocks[key];const id='lock'+key.charAt(0).toUpperCase()+key.slice(1);const btn=document.getElementById(id);btn.textContent=discountLocks[key]?'🔒':'🔓';btn.dataset.locked=discountLocks[key]?'1':'0';}
function getDiscountBases(){return{aptHt:Number(els.unitPriceHt.value||0),prkHt:getParkings().reduce((s,x)=>s+x.priceHt,0),celHt:getCelliers().reduce((s,x)=>s+x.priceHt,0)};}
function updateGlobalDisplay(){const dA=Number(els.discountApartment.value||0),dP=Number(els.discountParking.value||0),dC=Number(els.discountCellier.value||0);const tot=dA+dP+dC;els.discountTotal.value=Math.round(tot*100)/100;els.discountGlobalVal.value=tot>0?Math.round(tot*100)/100:'';}
function applyPrixCible(){const targetTtc=parseFloat(document.getElementById('prixCibleInput').value||0);if(!targetTtc){alert('Saisissez un prix cible TTC.');return;}const vat=parseFloat(els.vatRate.value||19);const factor=1+vat/100;const targetHt=targetTtc/factor;const baseA=parseFloat(els.unitPriceHt.value||0);if(!baseA){alert('Prix unitaire HT non disponible.');return;}const netP=parseFloat(els.discountParkingNet.value||0);const netC=parseFloat(els.discountCellierNet.value||0);const extra=parseFloat((els.extraWorks&&els.extraWorks.value)||0)||0;const targetNetA=Math.round((targetHt-netP-netC-extra)*1000)/1000;if(targetNetA<0){alert('Prix cible trop bas — inférieur à la somme parking + cellier + travaux.');return;}const dA=Math.round((baseA-targetNetA)*1000)/1000;if(dA<0){alert('Prix cible supérieur au prix brut appartement. Aucune remise appliquée.');return;}const pct=Math.round(dA/baseA*10000)/100;els.discountApartmentPct.value=pct;els.discountApartment.value=dA;els.discountApartmentNet.value=targetNetA;updateGlobalDisplay();refreshPreview();}
function adjustOthersToGlobal(changedKey){const ck=k=>k.charAt(0).toUpperCase()+k.slice(1);const b=getDiscountBases();const totalBase=b.aptHt+b.prkHt+b.celHt;if(!totalBase){updateGlobalDisplay();return;}const gPct=Number(els.discountGlobalPct.value||0);if(!gPct){updateGlobalDisplay();return;}const gTarget=totalBase*gPct/100;const keys=['apartment','parking','cellier'];const baseMap={apartment:b.aptHt,parking:b.prkHt,cellier:b.celHt};const dChanged=Number(els['discount'+ck(changedKey)].value||0);let fixed=dChanged;keys.forEach(k=>{if(k!==changedKey&&discountLocks[k])fixed+=Number(els['discount'+ck(k)].value||0);});const remaining=Math.max(0,gTarget-fixed);const adj=keys.filter(k=>k!==changedKey&&!discountLocks[k]&&baseMap[k]>0);const adjBase=adj.reduce((s,k)=>s+baseMap[k],0);if(adjBase>0){adj.forEach(k=>{const d=Math.round(remaining*(baseMap[k]/adjBase)*100)/100;els['discount'+ck(k)].value=d;els['discount'+ck(k)+'Net'].value=Math.round(Math.max(0,baseMap[k]-d)*100)/100;els['discount'+ck(k)+'Pct'].value=baseMap[k]>0?Math.round(d/baseMap[k]*10000)/100:0;});}const tot=keys.reduce((s,k)=>s+Number(els['discount'+ck(k)].value||0),0);els.discountTotal.value=Math.round(tot*100)/100;els.discountGlobalVal.value=Math.round(tot*100)/100;}
function applyGlobalPercent(p){const b=getDiscountBases();if(b.aptHt>0&&!discountLocks.apartment){const d=Math.round(b.aptHt*p/100*100)/100;els.discountApartment.value=d;els.discountApartmentPct.value=p;els.discountApartmentNet.value=Math.round((b.aptHt-d)*100)/100;}if(b.prkHt>0&&!discountLocks.parking){const d=Math.round(b.prkHt*p/100*100)/100;els.discountParking.value=d;els.discountParkingPct.value=p;els.discountParkingNet.value=Math.round((b.prkHt-d)*100)/100;}if(b.celHt>0&&!discountLocks.cellier){const d=Math.round(b.celHt*p/100*100)/100;els.discountCellier.value=d;els.discountCellierPct.value=p;els.discountCellierNet.value=Math.round((b.celHt-d)*100)/100;}updateGlobalDisplay();refreshPreview();}
function syncDiscountGlobal(src){const b=getDiscountBases();const totalBase=b.aptHt+b.prkHt+b.celHt;if(!totalBase)return;if(src==='pct'){const p=Math.min(100,Math.max(0,Number(els.discountGlobalPct.value||0)));applyGlobalPercent(p);}else{const val=Number(els.discountGlobalVal.value||0);const p=Math.min(100,Math.max(0,Math.round(val/totalBase*10000)/100));els.discountGlobalPct.value=p;applyGlobalPercent(p);}}
function setDiscountToggle(on){discountEnabled=on;const btn=document.getElementById('discountToggle');const sec=document.getElementById('discountSection');btn.textContent=on?'Activée':'Désactivée';btn.className=on?'btn btn-brand':'btn btn-secondary';sec.style.display=on?'':'none';if(!on){els.discountApartment.value=0;els.discountParking.value=0;els.discountCellier.value=0;els.discountTotal.value=0;els.discountGlobalPct.value='';els.discountGlobalVal.value='';els.discountApartmentPct.value='';els.discountApartmentNet.value='';els.discountParkingPct.value='';els.discountParkingNet.value='';els.discountCellierPct.value='';els.discountCellierNet.value='';}refreshPreview();}
document.getElementById('discountToggle').onclick=()=>setDiscountToggle(!discountEnabled);
function validateIdNumber(){
  const type=els.idType.value;
  const val=(els.idNumber.value||'').trim();
  const hint=document.getElementById('idNumberHint');
  const isCin=type==='CIN';
  els.idNumber.placeholder=isCin?'Ex : 12345678':'Ex : A123456';
  if(!val){hint.textContent='';els.idNumber.style.borderColor='';return;}
  const ok=isCin?/^\d{8}$/.test(val):/^[A-Za-z]\d{6}$/.test(val);
  const msg=isCin?'8 chiffres requis':'1 lettre + 6 chiffres requis';
  hint.textContent=ok?'✓ Format correct':'⚠ '+msg;
  hint.style.color=ok?'#166534':'#b91c1c';
  els.idNumber.style.borderColor=ok?'#86efac':'#fca5a5';
}
function syncDiscountApartment(src){const base=Number(els.unitPriceHt.value||0);if(!base)return;if(src==='pct'){const p=Math.min(100,Math.max(0,Number(els.discountApartmentPct.value||0)));const net=Math.round(base*(1-p/100)*100)/100;els.discountApartmentNet.value=net;els.discountApartment.value=Math.round((base-net)*100)/100;}else{const net=Number(els.discountApartmentNet.value||0);const p=Math.round((base-net)/base*10000)/100;els.discountApartmentPct.value=Math.max(0,p);els.discountApartment.value=Math.round(Math.max(0,base-net)*100)/100;}updateGlobalDisplay();refreshPreview();}
function syncDiscountParking(src){const base=getParkings().reduce((s,x)=>s+x.priceHt,0);if(!base)return;if(src==='pct'){const p=Math.min(100,Math.max(0,Number(els.discountParkingPct.value||0)));const net=Math.round(base*(1-p/100)*100)/100;els.discountParkingNet.value=net;els.discountParking.value=Math.round((base-net)*100)/100;}else{const net=Number(els.discountParkingNet.value||0);const p=Math.round((base-net)/base*10000)/100;els.discountParkingPct.value=Math.max(0,p);els.discountParking.value=Math.round(Math.max(0,base-net)*100)/100;}updateGlobalDisplay();refreshPreview();}
function syncDiscountCellier(src){const base=getCelliers().reduce((s,x)=>s+x.priceHt,0);if(!base)return;if(src==='pct'){const p=Math.min(100,Math.max(0,Number(els.discountCellierPct.value||0)));const net=Math.round(base*(1-p/100)*100)/100;els.discountCellierNet.value=net;els.discountCellier.value=Math.round((base-net)*100)/100;}else{const net=Number(els.discountCellierNet.value||0);const p=Math.round((base-net)/base*10000)/100;els.discountCellierPct.value=Math.max(0,p);els.discountCellier.value=Math.round(Math.max(0,base-net)*100)/100;}updateGlobalDisplay();refreshPreview();}
function syncDiscountDisplays(){const dA=Number(els.discountApartment.value||0),dP=Number(els.discountParking.value||0),dC=Number(els.discountCellier.value||0);const baseA=Number(els.unitPriceHt.value||0),baseP=getParkings().reduce((s,x)=>s+x.priceHt,0),baseC=getCelliers().reduce((s,x)=>s+x.priceHt,0);if(baseA&&dA){els.discountApartmentPct.value=Math.round(dA/baseA*10000)/100;els.discountApartmentNet.value=Math.round((baseA-dA)*100)/100;}if(baseP&&dP){els.discountParkingPct.value=Math.round(dP/baseP*10000)/100;els.discountParkingNet.value=Math.round((baseP-dP)*100)/100;}if(baseC&&dC){els.discountCellierPct.value=Math.round(dC/baseC*10000)/100;els.discountCellierNet.value=Math.round((baseC-dC)*100)/100;}const tot=dA+dP+dC;const totalBase=baseA+baseP+baseC;if(totalBase>0&&tot>0){els.discountGlobalPct.value=Math.round(tot/totalBase*10000)/100;els.discountGlobalVal.value=Math.round(tot*100)/100;}els.discountTotal.value=Math.round(tot*100)/100;}
const normalizeProject=(project)=>(project||'').toString().trim().toUpperCase();
function canonicalizeFlavaUnitCode(code){const value=(code||'').toString().trim().toUpperCase(); let match=null; if(!value) return ''; match=value.match(/^A-RDC-LC0?(\d{1,2})$/); if(match) return `LC ${Number(match[1])}`; if(value==='A-MEZZ-M01') return 'M01'; match=value.match(/^([AB])-([123])-(\d{1,2})$/); if(match) return `${match[1]}${match[2]}-${Number(match[3])}`; match=value.match(/^D-1-(\d{1,2})$/); if(match) return `D1-${Number(match[1])}`; match=value.match(/^B-(?:RDC|MEZZ)-D0?(\d{1,2})$/); if(match) return `D0-${Number(match[1])}`; return value;}
function normalizeUnitCodeForProject(project,code){return normalizeProject(project)==='FLAVA'?canonicalizeFlavaUnitCode(code):(code||'').toString().trim();}
const sameProject=(item,project)=>normalizeProject(item?.project||'')===normalizeProject(project);
function findUnit(project,code){const normalizedCode=normalizeUnitCodeForProject(project,code); return DB.units.find(u=>sameProject(u,project)&&normalizeUnitCodeForProject(project,u.code)===normalizedCode);}
function findAccessory(collection,project,code){return collection.find(item=>sameProject(item,project)&&item.code===code);}
function getUnitsByProject(project){return DB.units.filter(u=>sameProject(u,project));}
function resolveUnit(project,code){
  if(!code) return null;
  const normalizedProject=normalizeProject(project);
  const normalizedCode=normalizeUnitCodeForProject(normalizedProject||PREFILL.projectName||'',code);
  if(normalizedProject){
    const exact=findUnit(normalizedProject,code);
    if(exact) return exact;
  }
  const matches=DB.units.filter(u=>normalizeUnitCodeForProject(u.project||normalizedProject,u.code)===normalizedCode);
  if(matches.length===1) return matches[0];
  const prefillProject=normalizeProject(PREFILL.projectName||'');
  if(prefillProject){
    const preferred=matches.find(u=>sameProject(u,prefillProject));
    if(preferred) return preferred;
  }
  return matches[0]||null;
}
function getCurrentProject(){
  const explicit=normalizeProject(els.projectName.value||PREFILL.projectName||'');
  if(explicit) return explicit;
  return normalizeProject(resolveUnit('',els.unitCode.value)?.project||'AVA');
}
function populateUnitSelect(selectedCode=''){
  let currentProject=getCurrentProject();
  let list=getUnitsByProject(currentProject);
  if(!list.length && selectedCode){
    const fallback=resolveUnit(currentProject,selectedCode);
    if(fallback){
      currentProject=normalizeProject(fallback.project||currentProject||'AVA');
      els.projectName.value=currentProject;
      list=getUnitsByProject(currentProject);
    }
  }
  const normalSel=normalizeUnitCodeForProject(currentProject,selectedCode);
  const filtered=list.filter(u=>(u.status||'').toLowerCase()!=='vendu'||normalizeUnitCodeForProject(currentProject,u.code)===normalSel);
  els.unitCode.innerHTML='';
  filtered.forEach(u=>{const o=document.createElement('option');o.value=u.code;o.textContent=u.code+(u.status&&u.status!=='disponible'?' ('+u.status+')':'');els.unitCode.appendChild(o);});
  if(selectedCode){
    const opts=[...els.unitCode.options];
    const exactOpt=opts.find(o=>o.value===selectedCode);
    if(exactOpt){els.unitCode.value=selectedCode;}
    else{const canonSel=normalizeUnitCodeForProject(currentProject,selectedCode);const canonOpt=opts.find(o=>normalizeUnitCodeForProject(currentProject,o.value)===canonSel);if(canonOpt){els.unitCode.value=canonOpt.value;}else if(opts.length){els.unitCode.selectedIndex=0;}}
  } else if(els.unitCode.options.length){els.unitCode.selectedIndex=0;}
}
function calcUnitHt(unit){
  // 1. Total HT stocké explicitement
  if(unit.priceHt>0) return unit.priceHt;
  // 2. Total TTC → back-calculate HT
  if(unit.priceTtc>0){ const v=Number(els.vatRate.value||19)/100; return Math.round(unit.priceTtc/(1+v)*100)/100; }
  // 3. Prix/m² × surface couverte (même formule que dashboard AVA)
  if(unit.priceM2Ht>0 && unit.coveredArea>0) return Math.round(indexedPrice(unit.priceM2Ht)*unit.coveredArea*100)/100;
  return 0;
}
function applyUnitData(){
  const unit=resolveUnit(getCurrentProject(),els.unitCode.value);
  if(!unit){
    els.assetType.value='Appartement';
    els.unitPriceHt.value=0;
    els.coveredArea.value=0;
    els.openArea.value=0;
    els.shoArea.value=0;
    els.pcArea.value=0;
    refreshPreview();
    return;
  }
  els.projectName.value=normalizeProject(unit.project||getCurrentProject()||'AVA');
  if(els.unitCode.value!==unit.code) els.unitCode.value=unit.code;
  els.assetType.value=unit.type||'Appartement';
  els.unitPriceHt.value=calcUnitHt(unit)||0;
  els.coveredArea.value=unit.coveredArea||0;
  els.openArea.value=unit.openArea||0;
  els.shoArea.value=unit.shoArea||0;
  els.pcArea.value=unit.pcArea||0;
  refreshPreview();
}
/* Liste des parkings : réservés en orange, vendus en rouge, au survol à qui il est pris */
let _pkTip=null;
function pkTip(text,el){if(!_pkTip){_pkTip=document.createElement('div');_pkTip.className='pk-tip';document.body.appendChild(_pkTip);} if(!text){_pkTip.style.display='none';return;} const r=el.getBoundingClientRect(); _pkTip.textContent=text; _pkTip.style.display='block'; _pkTip.style.left=Math.min(r.right+8,window.innerWidth-_pkTip.offsetWidth-8)+'px'; _pkTip.style.top=r.top+'px';}
function enhanceParkingSelect(s){
  s.style.display='none';
  const wrap=document.createElement('div'); wrap.className='pk-pick';
  const btn=document.createElement('button'); btn.type='button'; btn.className='pk-btn';
  const list=document.createElement('div'); list.className='pk-list';
  const close=()=>{list.style.display='none'; pkTip('');};
  const upd=()=>{const o=s.options[s.selectedIndex]; btn.textContent=o?o.textContent:'—'; btn.className='pk-btn '+(o?.dataset.st||''); btn.onmouseenter=()=>pkTip(o?.dataset.info||'',btn); btn.onmouseleave=()=>pkTip('');};
  [...s.options].forEach(o=>{const it=document.createElement('div'); it.className='pk-item '+(o.dataset.st||''); it.textContent=o.textContent; it.onmouseenter=()=>pkTip(o.dataset.info||'',it); it.onmouseleave=()=>pkTip(''); it.onclick=()=>{s.value=o.value; s.dispatchEvent(new Event('change')); close();}; list.appendChild(it);});
  btn.onclick=()=>{const open=list.style.display==='block'; close(); if(!open) list.style.display='block';};
  document.addEventListener('click',e=>{if(!wrap.contains(e.target)) close();});
  wrap.append(btn,list); s.after(wrap); s.addEventListener('change',upd); upd();
}
function parkingTakenInfo(p){const st=(p.status||'').toLowerCase()==='vendu'?'Vendu':'Réservé'; const who=p.linkedClient?(st==='Vendu'?' — vendu à ':' — réservé pour ')+p.linkedClient:''; if(!p.linkedUnit) return st; const b=String(p.linkedBlock||'').replace(/^bloc\s*/i,''); const bloc=b?('Bloc '+b+' – '):''; return st+' : '+bloc+'appartement '+p.linkedUnit+who;}
function isAvailable(item){const st=(item.status||'').toLowerCase(); return st!=='vendu' && st!=='réservé' && st!=='reserve';}
function createParkingRow(value=''){const row=document.createElement('div'); row.className='row-mini parking-row'; row.style.gridTemplateColumns='1.5fr 1fr auto'; row.innerHTML='<div><label>Parking</label><select class="parking-code"></select></div><div><label>Prix TTC</label><input class="parking-price" type="number" step="0.01"></div><button type="button" class="btn btn-secondary remove-row">Suppr.</button>'; const s=row.querySelector('.parking-code'); const currentProject=getCurrentProject(); DB.parkings.filter(p=>(!p.project||sameProject(p,currentProject))).forEach(p=>{const o=document.createElement('option');o.value=p.code;const ht=getParkingHt(p,currentProject);const htStr=ht?(' – '+Math.round(ht).toLocaleString('fr-FR')+' TND HT'):'';o.textContent=p.code+htStr;if(!isAvailable(p)){const v=(p.status||'').toLowerCase()==='vendu';o.dataset.st=v?'st-v':'st-r';o.dataset.info=parkingTakenInfo(p);o.textContent+=v?' · Vendu':' · Réservé';}s.appendChild(o);});
  // Si le parking sauvegardé est vendu/réservé, l'ajouter quand même en tête pour ne pas perdre la sélection
  if(value && ![...s.options].some(o=>o.value===value)){const o=document.createElement('option');o.value=value;const saved=DB.parkings.find(p=>p.code===value);const ht=saved?getParkingHt(saved,currentProject):0;const htStr=ht?(' – '+Math.round(ht).toLocaleString('fr-FR')+' TND HT'):'';o.textContent=value+htStr+' ✓';s.insertBefore(o,s.firstChild);}
  if(value && [...s.options].some(o=>o.value===value)) s.value=value; else if(s.options.length) s.selectedIndex=0; const hint=document.createElement('div'); hint.style.cssText='grid-column:1/-1;font-size:11px;color:#c62828;margin-top:-4px'; const sync=()=>{const p=findAccessory(DB.parkings,currentProject,s.value); const taken=p&&!isAvailable(p); hint.textContent=taken?'⚠ Parking '+parkingTakenInfo(p):''; hint.style.color=(p&&(p.status||'').toLowerCase()==='vendu')?'#c62828':'#d35400'; const ht=p?getParkingHt(p,currentProject):0; const vat=Number(els.vatRate.value||19)/100; row.querySelector('.parking-price').value=Math.round(ht*(1+vat)*100)/100; refreshPreview();}; s.addEventListener('change',sync); row.querySelector('.parking-price').addEventListener('input',refreshPreview); row.querySelector('.remove-row').addEventListener('click',()=>{row.remove();refreshPreview();}); row.appendChild(hint); parkingRows.appendChild(row); enhanceParkingSelect(s); sync();}
function createCellierRow(value=''){const row=document.createElement('div'); row.className='row-mini cellier-row'; row.innerHTML='<div><label>Cellier</label><select class="cellier-code"></select></div><div><label>Surface (m²)</label><input class="cellier-surface" type="number" step="0.001" readonly></div><div><label>Prix</label><input class="cellier-price" type="number" step="0.01"></div><button type="button" class="btn btn-secondary remove-row">Suppr.</button>'; const s=row.querySelector('.cellier-code'); const currentProject=getCurrentProject(); DB.celliers.filter(c=>(!c.project||sameProject(c,currentProject))).forEach(c=>{const o=document.createElement('option');o.value=c.code;const ttc=c.priceTtc||c.priceHt||0;const ttcStr=ttc?(' – '+Math.round(ttc).toLocaleString('fr-FR')+' TND TTC'):'';const stStr=c.status&&c.status!=='Disponible'?' ('+c.status+')':'';o.textContent=c.code+ttcStr+stStr;s.appendChild(o);}); if(value && [...s.options].some(o=>o.value===value)) s.value=value; else if(s.options.length) s.selectedIndex=0; const sync=()=>{const c=findAccessory(DB.celliers,currentProject,s.value); row.querySelector('.cellier-surface').value=c?c.surface:0; row.querySelector('.cellier-price').value=c?indexedPrice(c.priceHt):0; refreshPreview();}; s.addEventListener('change',sync); row.querySelector('.cellier-price').addEventListener('input',refreshPreview); row.querySelector('.remove-row').addEventListener('click',()=>{row.remove();refreshPreview();}); cellierRows.appendChild(row); sync();}
function createTrancheRow(percent=10,date='',label='',amount=0){
  const idx=trancheRows.querySelectorAll('.tranche-row').length+1;
  const displayLabel=label||(ord(idx)+' tranche');
  const row=document.createElement('div');
  row.className='tranche-row';
  // 'pct' : le montant suit le prix total ; 'amount' : le montant saisi est gardé et le % suit
  row.dataset.mode = amount>0 ? 'amount' : 'pct';
  row.style.cssText='margin-bottom:10px;border:1px solid #e5e7eb;border-radius:10px;padding:8px 10px;background:#fafafa';
  row.innerHTML=`
    <div style="margin-bottom:6px">
      <label style="font-size:12px;font-weight:700;color:#202020;display:block;margin-bottom:3px">Libellé</label>
      <input class="tranche-label" value="${displayLabel}" style="width:100%;border:1px solid #d7d7d7;border-radius:8px;padding:7px 10px;font-size:13px">
    </div>
    <div style="display:grid;grid-template-columns:1fr 68px 1fr auto;gap:8px;align-items:end">
      <div>
        <label style="font-size:12px;font-weight:700;color:#202020;display:block;margin-bottom:3px">Montant TTC</label>
        <input class="tranche-amount" type="number" step="0.001" min="0" placeholder="0.000" value="${amount||''}"
          style="width:100%;border:1px solid #d7d7d7;border-radius:8px;padding:7px 10px;font-size:13px;min-width:0">
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;color:#202020;display:block;margin-bottom:3px">%</label>
        <input class="tranche-percent" type="number" step="0.01" min="0" max="100" value="${percent}"
          style="width:100%;border:1px solid #d7d7d7;border-radius:8px;padding:7px 6px;font-size:13px;min-width:0;text-align:center">
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;color:#202020;display:block;margin-bottom:3px">Date</label>
        <input class="tranche-date" type="date" value="${date}"
          style="width:100%;border:1px solid #d7d7d7;border-radius:8px;padding:7px 8px;font-size:13px;min-width:0">
      </div>
      <button type="button" class="btn btn-secondary remove-row"
        style="padding:7px 10px;font-size:13px;border-radius:8px;align-self:end;white-space:nowrap">✕</button>
    </div>`;
  const amtInp=row.querySelector('.tranche-amount');
  const pctInp=row.querySelector('.tranche-percent');
  amtInp.addEventListener('input',()=>{
    const ttc=calc().totalTtc;
    const a=parseFloat(amtInp.value)||0;
    row.dataset.mode='amount';
    if(ttc>0) pctInp.value=Math.round(a/ttc*10000)/100;
    refreshPreview();
  });
  pctInp.addEventListener('input',()=>{
    const ttc=calc().totalTtc;
    const p=parseFloat(pctInp.value)||0;
    row.dataset.mode='pct';
    amtInp.value=Math.round(ttc*p/100*1000)/1000||'';
    refreshPreview();
  });
  row.querySelector('.tranche-label').addEventListener('input',refreshPreview);
  row.querySelector('.tranche-date').addEventListener('input',refreshPreview);
  row.querySelector('.remove-row').addEventListener('click',()=>{row.remove();refreshPreview();});
  trancheRows.appendChild(row);
  if(!amount&&percent>0){const ttc=calc().totalTtc;if(ttc>0)amtInp.value=Math.round(ttc*percent/100*1000)/1000||'';}
  refreshPreview();
}
const getParkings=()=>{const v=Number(els.vatRate.value||19)/100; return[...parkingRows.querySelectorAll('.parking-row')].map(r=>({code:r.querySelector('.parking-code').value,surface:0,priceHt:Math.round(Number(r.querySelector('.parking-price').value||0)/(1+v)*100)/100}));};
const getCelliers=()=>[...cellierRows.querySelectorAll('.cellier-row')].map(r=>({code:r.querySelector('.cellier-code').value,surface:Number(r.querySelector('.cellier-surface').value||0),priceHt:Number(r.querySelector('.cellier-price').value||0)}));
const getTranches=()=>[...trancheRows.querySelectorAll('.tranche-row')].map((r,i)=>{
  const pct=Number(r.querySelector('.tranche-percent').value||0);
  const amt=Number(r.querySelector('.tranche-amount').value||0);
  const ttc=calc().totalTtc;
  // Si montant saisi manuellement, recalculer le % depuis le montant pour rester cohérent
  const finalPct = amt>0 && ttc>0 ? Math.round(amt/ttc*10000)/100 : pct;
  return {label:r.querySelector('.tranche-label').value||`${i+1}ème tranche`, percent:finalPct, amount:amt, date:r.querySelector('.tranche-date').value};
});
function calc(){const vat=Number(els.vatRate.value||19)/100, apartmentHt=Number(els.unitPriceHt.value||0), parkingHtBase=getParkings().reduce((s,x)=>s+x.priceHt,0), cellierHtBase=getCelliers().reduce((s,x)=>s+x.priceHt,0), extraWorksHt=Number(els.extraWorks.value||0), dA=discountEnabled?Number(els.discountApartment.value||0):0, dP=discountEnabled?Number(els.discountParking.value||0):0, dC=discountEnabled?Number(els.discountCellier.value||0):0, apartmentNetHt=Math.max(0,apartmentHt-dA), parkingNetHt=Math.max(0,parkingHtBase-dP), cellierNetHt=Math.max(0,cellierHtBase-dC), apartmentVat=apartmentNetHt*vat, parkingVat=parkingNetHt*vat, cellierVat=cellierNetHt*vat, extraWorksVat=extraWorksHt*vat, apartmentTtc=apartmentNetHt+apartmentVat, parkingTtc=parkingNetHt+parkingVat, cellierTtc=cellierNetHt+cellierVat, extraWorksTtc=extraWorksHt+extraWorksVat, totalHt=apartmentNetHt+parkingNetHt+cellierNetHt+extraWorksHt, totalVat=apartmentVat+parkingVat+cellierVat+extraWorksVat, totalTtc=apartmentTtc+parkingTtc+cellierTtc+extraWorksTtc, saleArea=Number(els.coveredArea.value||0)+Number(els.openArea.value||0)+Number(els.pcArea.value||0); return {vat,apartmentHt,parkingHtBase,cellierHtBase,extraWorksHt,dA,dP,dC,apartmentNetHt,parkingNetHt,cellierNetHt,apartmentVat,parkingVat,cellierVat,extraWorksVat,apartmentTtc,parkingTtc,cellierTtc,extraWorksTtc,totalHt,totalVat,totalTtc,saleArea};}
const ord=n=>n===1?'1ère':`${n}ème`; const frDate=iso=>iso?(iso.split('-').reverse().join('/')):'';
// Recalcule montants ou % des tranches quand le prix total change (remise, parking, prix cible...)
function syncTranches(){
  const ttc=calc().totalTtc;
  if(!(ttc>0)) return;
  trancheRows.querySelectorAll('.tranche-row').forEach(r=>{
    const a=r.querySelector('.tranche-amount'), p=r.querySelector('.tranche-percent');
    if(r.dataset.mode==='amount'){
      const v=parseFloat(a.value)||0;
      if(v>0) p.value=Math.round(v/ttc*10000)/100;
    } else {
      const pv=parseFloat(p.value)||0;
      a.value=Math.round(ttc*pv/100*1000)/1000||'';
    }
  });
}
function refreshPreview(){syncTranches();const currentProject=getCurrentProject(), currentUnit=resolveUnit(currentProject,els.unitCode.value), c=calc(), parkings=getParkings(), celliers=getCelliers(), tranches=getTranches(); if(currentUnit){els.projectName.value=normalizeProject(currentUnit.project||currentProject||'AVA'); if(els.unitCode.value!==currentUnit.code) els.unitCode.value=currentUnit.code;} ['clientType','lastName','firstName','country','address','zip','city','phone1','phone2','email1','email2','idType','idNumber','maritalStatus','maritalRegime','assetType','projectName','unitCode','financeMode'].forEach(k=>setText('pv_'+k,els[k].value)); const _proj=normalizeProject(els.projectName.value||currentUnit?.project||currentProject||'AVA'); const _logoMid=_proj==='AVA'?'assets/images/logoava.avif':(_proj==='EVOLO'?'assets/images/LogoEVOLO.png':'assets/images/LogoFLAVA.png'); ['logoProjectTop','logoProjectMid','logoProjectPlan1','logoProjectPlan2'].forEach(id=>{const el=document.getElementById(id);if(el){el.onerror=()=>{el.style.visibility='hidden';};el.style.visibility='';el.src=_logoMid;}}); const _today=new Date().toLocaleDateString('fr-FR',{day:'2-digit',month:'long',year:'numeric'}); setText('docDateTop','Fait le '+_today); setText('pv_hasParking',parkings.length?'oui':'non'); setText('pv_parkingCount',parkings.length); setText('pv_hasCellier',celliers.length?'oui':'non'); setText('pv_cellierCount',celliers.length); setText('pv_saleArea',fmt(c.saleArea,1)); setText('pv_coveredArea',fmt(els.coveredArea.value,3)); setText('pv_openArea',fmt(els.openArea.value,3)); setText('pv_shoArea',fmt(els.shoArea.value,3)); setText('pv_pcArea',fmt(els.pcArea.value,3)); document.getElementById('parkingPreviewBody').innerHTML=parkings.map((p,i)=>`<tr><td class="label">N° de parking ${i+1}</td><td class="value">${p.code}</td></tr>`).join(''); document.getElementById('cellierPreviewBody').innerHTML=celliers.map((x,i)=>`<tr><td class="label">N° de cellier ${i+1}</td><td class="value">${x.code}</td></tr>`).join(''); document.getElementById('cellierSurfaceTable').innerHTML=celliers.map((x,i)=>`<table class="mini-table" style="margin-top:8px"><tr><td class="label">Superficie N° de cellier ${i+1} (m²)</td><td class="value text-right">${fmt(x.surface,2)}</td></tr></table>`).join('');
setText('pv_vatLabel',Number(els.vatRate.value||19)); const _hasDA=discountEnabled&&c.dA>0,_hasDP=discountEnabled&&c.dP>0,_hasDC=discountEnabled&&c.dC>0; const _aGV=Math.round(c.apartmentHt*c.vat*100)/100,_aGT=Math.round(c.apartmentHt*(1+c.vat)*100)/100,_aDV=Math.round(c.dA*c.vat*100)/100,_aDT=Math.round(c.dA*(1+c.vat)*100)/100; const _pGV=Math.round(c.parkingHtBase*c.vat*100)/100,_pGT=Math.round(c.parkingHtBase*(1+c.vat)*100)/100,_pDV=Math.round(c.dP*c.vat*100)/100,_pDT=Math.round(c.dP*(1+c.vat)*100)/100; const _cGV=Math.round(c.cellierHtBase*c.vat*100)/100,_cGT=Math.round(c.cellierHtBase*(1+c.vat)*100)/100,_cDV=Math.round(c.dC*c.vat*100)/100,_cDT=Math.round(c.dC*(1+c.vat)*100)/100; setText('pv_priceApartmentHt',fmt(c.apartmentHt)); setText('pv_priceApartmentVat',_hasDA?fmt(_aGV):fmt(c.apartmentVat)); setText('pv_priceApartmentTtc',_hasDA?fmt(_aGT):fmt(c.apartmentTtc)); document.getElementById('tr_discountApartment').style.display=_hasDA?'':'none'; document.getElementById('tr_priceApartmentNet').style.display=_hasDA?'':'none'; setText('pv_discountApartment',_hasDA?'- '+fmt(c.dA):''); setText('pv_discountApartmentPct',_hasDA?pct(c.apartmentHt?(c.dA/c.apartmentHt)*100:0):''); setText('pv_discountApartmentVat',_hasDA?'- '+fmt(_aDV):''); setText('pv_discountApartmentTtc',_hasDA?'- '+fmt(_aDT):''); setText('pv_priceApartmentNet',_hasDA?fmt(c.apartmentNetHt):''); setText('pv_priceApartmentNetVat',_hasDA?fmt(c.apartmentVat):''); setText('pv_priceApartmentNetTtc',_hasDA?fmt(c.apartmentTtc):''); setText('pv_priceParkingHt',fmt(c.parkingHtBase)); setText('pv_priceParkingVat',_hasDP?fmt(_pGV):fmt(c.parkingVat)); setText('pv_priceParkingTtc',_hasDP?fmt(_pGT):fmt(c.parkingTtc)); document.getElementById('tr_discountParking').style.display=_hasDP?'':'none'; document.getElementById('tr_priceParkingNet').style.display=_hasDP?'':'none'; setText('pv_discountParking',_hasDP?'- '+fmt(c.dP):''); setText('pv_discountParkingPct',_hasDP?pct(c.parkingHtBase?(c.dP/c.parkingHtBase)*100:0):''); setText('pv_discountParkingVat',_hasDP?'- '+fmt(_pDV):''); setText('pv_discountParkingTtc',_hasDP?'- '+fmt(_pDT):''); setText('pv_priceParkingNet',_hasDP?fmt(c.parkingNetHt):''); setText('pv_priceParkingNetVat',_hasDP?fmt(c.parkingVat):''); setText('pv_priceParkingNetTtc',_hasDP?fmt(c.parkingTtc):''); setText('pv_priceCellierHt',fmt(c.cellierHtBase)); setText('pv_priceCellierVat',_hasDC?fmt(_cGV):fmt(c.cellierVat)); setText('pv_priceCellierTtc',_hasDC?fmt(_cGT):fmt(c.cellierTtc)); document.getElementById('tr_discountCellier').style.display=_hasDC?'':'none'; document.getElementById('tr_priceCellierNet').style.display=_hasDC?'':'none'; setText('pv_discountCellier',_hasDC?'- '+fmt(c.dC):''); setText('pv_discountCellierPct',_hasDC?pct(c.cellierHtBase?(c.dC/c.cellierHtBase)*100:0):''); setText('pv_discountCellierVat',_hasDC?'- '+fmt(_cDV):''); setText('pv_discountCellierTtc',_hasDC?'- '+fmt(_cDT):''); setText('pv_priceCellierNet',_hasDC?fmt(c.cellierNetHt):''); setText('pv_priceCellierNetVat',_hasDC?fmt(c.cellierVat):''); setText('pv_priceCellierNetTtc',_hasDC?fmt(c.cellierTtc):''); setText('pv_extraWorksHt',fmt(c.extraWorksHt)); setText('pv_extraWorksVat',fmt(c.extraWorksVat)); setText('pv_extraWorksTtc',fmt(c.extraWorksTtc)); setText('pv_totalHt',fmt(c.totalHt)); setText('pv_totalVat',fmt(c.totalVat)); setText('pv_totalTtc',fmt(c.totalTtc)); document.getElementById('tr_discountTotal').style.display='none'; document.getElementById('tr_netTotal').style.display='none'; const finalTtc=c.totalTtc; setText('pv_financePct',pct(100)); setText('pv_financeTtc',fmt(finalTtc)); const _ts=getTranches(),_tsum=_ts.reduce((s,t)=>s+(t.amount>0&&c.totalTtc>0?t.amount/c.totalTtc*100:t.percent),0),_tw=document.getElementById('trancheWarning'); if(_ts.length>0&&Math.abs(_tsum-100)>0.01){_tw.textContent='⚠ Total tranches : '+_tsum.toFixed(2)+'% (doit être 100%)';_tw.style.display='';}else{_tw.style.display='none';} setText('pv_trancheCount',tranches.length); document.getElementById('scheduleBody').innerHTML=tranches.map((t,i)=>{
  const dispAmt = t.amount > 0 ? t.amount : finalTtc*(t.percent/100);
  return `<tr><td class="label">${ord(i+1)} tranche</td><td class="text-right">${pct(t.percent)}</td><td class="text-right">${fmt(dispAmt,2)}</td><td class="text-right">${frDate(t.date)||'-- / -- / ----'}</td></tr>`;
}).join(''); setText('pv_signClient',`${els.firstName.value} ${els.lastName.value}`.trim()); setText('pv_signResp',els.responsableName.value); setText('pv_signRespRole',els.responsableRole.value); setText('pv_signVal',els.validationName.value); setText('pv_signValRole',els.validationRole.value); const _unit=currentUnit||resolveUnit(_proj,els.unitCode.value); const _unitPlan=_unit?.plan_image||''; setText('pv_planUnit',els.unitCode.value); const _uImg=document.getElementById('pv_planUnitImg'),_uPdf=document.getElementById('pv_planUnitPdf'),_uNo=document.getElementById('pv_planUnitNoImg'); if(_unitPlan){if(/\.pdf(?:\?|#|$)/i.test(_unitPlan)){_uImg.removeAttribute('src');_uImg.style.display='none';_uPdf.src=_unitPlan;_uPdf.style.display='';}else{_uPdf.removeAttribute('src');_uPdf.style.display='none';_uImg.src=_unitPlan;_uImg.style.display='';}_uNo.style.display='none';}else{_uImg.removeAttribute('src');_uPdf.removeAttribute('src');_uImg.style.display='none';_uPdf.style.display='none';_uNo.style.display='';}
/* Une page A4 paysage par parking et par cellier */
const _accHead=`<div class="doc-head"><div class="doc-head-logos"><div class="logo-left"><img src="assets/images/LogoPROMED.png" alt="Logo Promed"></div><div class="logo-mid"><img src="${_logoMid}" alt="Logo projet"></div><div class="logo-right"><img src="assets/images/PromedGroup.png" alt="Logo Promed Group"></div></div></div>`;
const _accPage=(label,code,src)=>{const media=!src?`<span style="padding:20px;color:#aaa;font-size:12px">Aucun plan disponible</span>`:/\.pdf(?:\?|#|$)/i.test(src)?`<iframe src="${src}" title="${label} ${code}"></iframe>`:`<img src="${src}" alt="${label} ${code}">`; return `<section class="paper paper-land">${_accHead}<div class="section-title" style="border-top:none">7. Plan ${label.toLowerCase()} — ${code}</div><div class="land-box">${media}</div></section>`;};
const _accHtml=parkings.map(p=>_accPage('Parking',p.code,findAccessory(DB.parkings,_proj,p.code)?.plan_image||'')).join('')+celliers.map(x=>_accPage('Cellier',x.code,findAccessory(DB.celliers,_proj,x.code)?.plan_image||'')).join(''); document.getElementById('pv_accPages').innerHTML=_accHtml; document.getElementById('pv_planPage').classList.toggle('last-page',!_accHtml);
renderCoClientsDoc();}
function hydrate(){Object.keys(PREFILL).forEach(k=>{if(els[k]) els[k].value=PREFILL[k] ?? '';}); const lockedUnit=resolveUnit(PREFILL.projectName||'',PREFILL.unitCode||''); if(lockedUnit){els.projectName.value=normalizeProject(lockedUnit.project||PREFILL.projectName||'AVA');} else if(PREFILL.projectName){els.projectName.value=normalizeProject(PREFILL.projectName);} if(SESSION_USER.fullName){els.responsableName.value=SESSION_USER.fullName;} populateUnitSelect(PREFILL.unitCode||''); applyUnitData(); if(PREFILL.unitPriceHt>0) els.unitPriceHt.value=PREFILL.unitPriceHt; parkingRows.innerHTML=''; cellierRows.innerHTML=''; trancheRows.innerHTML=''; (PREFILL.selectedParkings||[]).forEach(code=>createParkingRow(code)); if(PREFILL.parkingPrices){parkingRows.querySelectorAll('.parking-row').forEach(row=>{const _pc=row.querySelector('.parking-code').value;if(PREFILL.parkingPrices[_pc]!=null){const _v2=Number(els.vatRate.value||19)/100;row.querySelector('.parking-price').value=Math.round(PREFILL.parkingPrices[_pc]*(1+_v2)*100)/100;}});} (PREFILL.selectedCelliers||[]).forEach(code=>createCellierRow(code)); if(PREFILL.cellierPrices){cellierRows.querySelectorAll('.cellier-row').forEach(row=>{const _cc=row.querySelector('.cellier-code').value;if(PREFILL.cellierPrices[_cc]!=null){row.querySelector('.cellier-price').value=PREFILL.cellierPrices[_cc];}});} if(!parkingRows.children.length && PREFILL.selectedParkings?.length===0){} if(!cellierRows.children.length && PREFILL.selectedCelliers?.length===0){} (PREFILL.tranches||[]).forEach(t=>createTrancheRow(t.percent||10,t.date||'',t.label||'',t.amount||0)); if(!trancheRows.children.length){[['20',''],['10',''],['10',''],['10','']].forEach(x=>createTrancheRow(x[0],x[1]));} const _savedDA=Number(PREFILL.discountApartment||0),_savedDP=Number(PREFILL.discountParking||0),_savedDC=Number(PREFILL.discountCellier||0),_savedDT=Number(PREFILL.discountTotal||0); if(_savedDA>0||_savedDP>0||_savedDC>0||_savedDT>0){setDiscountToggle(true);syncDiscountDisplays();}
document.getElementById('co-client-forms').innerHTML=''; _coIdx=0;
(PREFILL.coClients||[]).forEach(co=>addCoClientForm(co));
// Avertissement si promesse existante sans snapshot de prix
const _existingPid=parseInt(document.getElementById('promiseId').value)||0;
if(_existingPid>0 && !(PREFILL.unitPriceHt>0)){
  const _warn=document.createElement('div');
  _warn.style.cssText='background:#fff3cd;border:1px solid #ffc107;color:#856404;border-radius:8px;padding:10px 12px;font-size:13px;margin-bottom:10px;line-height:1.4';
  _warn.innerHTML='⚠️ <strong>Prix recalculés au mois courant</strong><br>Cette promesse a été créée avant la sauvegarde automatique des prix. Les montants affichés reflètent le barème du mois en cours et peuvent différer du document original. Enregistrez la fiche pour figer les prix actuels.';
  document.getElementById('flashBox').after(_warn);
}
refreshPreview();
// EVOLO : pas de cellier dans ce projet, on cache tout ce qui concerne les celliers
(function(){
  const tag=el=>{if(el)el.classList.add('cel-only');};
  tag(document.getElementById('cellierRows')?.closest('.form-section'));
  tag(document.getElementById('lockCellier')?.parentElement?.parentElement);
  ['pv_hasCellier','pv_cellierCount','pv_priceCellierHt'].forEach(id=>tag(document.getElementById(id)?.closest('tr')));
  ['cellierPreviewBody','cellierSurfaceTable','tr_discountCellier','tr_priceCellierNet'].forEach(id=>tag(document.getElementById(id)));
  function applyEvoloCellier(){
    const isEvolo=normalizeProject(els.projectName.value)==='EVOLO';
    document.body.classList.toggle('no-cellier',isEvolo);
    if(isEvolo&&cellierRows.querySelector('.cellier-row')){cellierRows.innerHTML='';refreshPreview();}
  }
  els.projectName.addEventListener('change',applyEvoloCellier);
  applyEvoloCellier();
})();
const _wantedSt=(new URLSearchParams(location.search).get('status')||'').toUpperCase();
if(normalizeProject(els.projectName.value)==='EVOLO'&&(_wantedSt==='R'||_wantedSt==='V')){flash('Statut demandé : '+(_wantedSt==='V'?'Vendu':'Réservé')+'. Il sera enregistré quand vous cliquerez sur Enregistrer.');}
}
async function saveFiche(){const payload={nom:[els.firstName.value,els.lastName.value].filter(Boolean).join(' ').trim(),tel:els.phone1.value,email:els.email1.value,cin:els.idNumber.value,date_naissance:null,cin_delivre_le:null,objet:`Fiche client ${els.projectName.value} - ${els.unitCode.value}`,unit_code:els.unitCode.value,details_json:JSON.stringify({clientType:els.clientType.value,country:els.country.value,lastName:els.lastName.value,firstName:els.firstName.value,address:els.address.value,zip:els.zip.value,city:els.city.value,phone1:els.phone1.value,phone2:els.phone2.value,email1:els.email1.value,email2:els.email2.value,idType:els.idType.value,idNumber:els.idNumber.value,maritalStatus:els.maritalStatus.value,maritalRegime:els.maritalRegime.value,projectName:els.projectName.value,assetType:els.assetType.value,discountApartment:Number(els.discountApartment.value||0),discountParking:Number(els.discountParking.value||0),discountCellier:Number(els.discountCellier.value||0),discountTotal:Number(els.discountApartment.value||0)+Number(els.discountParking.value||0)+Number(els.discountCellier.value||0),extraWorks:Number(els.extraWorks.value||0),vatRate:Number(els.vatRate.value||19),financeMode:els.financeMode.value,responsableName:els.responsableName.value,responsableRole:els.responsableRole.value,validationName:els.validationName.value,validationRole:els.validationRole.value,selectedParkings:getParkings().map(x=>x.code),selectedCelliers:getCelliers().map(x=>x.code),tranches:getTranches(),coClients:getCoClients(),unitPriceHt:Number(els.unitPriceHt.value||0),parkingPrices:getParkings().reduce((acc,x)=>{acc[x.code]=x.priceHt;return acc;},{}),cellierPrices:getCelliers().reduce((acc,x)=>{acc[x.code]=x.priceHt;return acc;},{})}),parking:getParkings().map(x=>x.code).join(', '),cellier:getCelliers().map(x=>x.code).join(', '),prix_ttc:calc().totalTtc,prix_ht:calc().totalHt,discount_apartment:Number(els.discountApartment.value||0),discount_parking:Number(els.discountParking.value||0),discount_cellier:Number(els.discountCellier.value||0),extra_works:Number(els.extraWorks.value||0),finance_mode:els.financeMode.value};
if(!payload.nom||!payload.cin||!payload.unit_code){flash('Nom, CIN et unite sont obligatoires.','err');return;}
// Parking / cellier déjà pris par un autre client : on n'enregistre pas (sauf s'il était déjà dans cette fiche)
{const _cp=getCurrentProject(), _orig=new Set([...(PREFILL.selectedParkings||[]),...(PREFILL.selectedCelliers||[])].map(c=>String(c).trim().toUpperCase())), _seen=new Set(), _bad=[];
 [['Parking',getParkings(),DB.parkings],['Cellier',getCelliers(),DB.celliers]].forEach(([lbl,list,db])=>list.forEach(x=>{const k=String(x.code||'').trim().toUpperCase(); if(!k) return; if(_seen.has(k)){_bad.push(lbl+' '+x.code+' choisi deux fois');return;} _seen.add(k); if(_orig.has(k)) return; const a=findAccessory(db,_cp,x.code); if(a&&!isAvailable(a)) _bad.push(lbl+' '+x.code+' : '+parkingTakenInfo(a));}));
 if(_bad.length){flash('Fiche non enregistrée. '+_bad.join(' ; ')+'. Choisissez une place disponible.','err');return;}}
try{
  const res=await fetch('api/client_save.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const json=await res.json();
  if(!res.ok||json.error){flash(json.error||'Erreur enregistrement.','err');return;}
  const clientId=json.client_id||0;
  const venteId=json.vente_id||0;
  if(clientId){document.getElementById('clientId').value=clientId;}
  if(!clientId){flash('Erreur : client non enregistré.','err');return;}

  // ── Étape 2 : Sauvegarder la promesse ─────────────────────────────────────
  const existingPid=parseInt(document.getElementById('promiseId').value)||0;
  const c2=calc();
  const dj={clientType:els.clientType.value,country:els.country.value,lastName:els.lastName.value,firstName:els.firstName.value,address:els.address.value,zip:els.zip.value,city:els.city.value,phone1:els.phone1.value,phone2:els.phone2.value,email1:els.email1.value,email2:els.email2.value,idType:els.idType.value,idNumber:els.idNumber.value,maritalStatus:els.maritalStatus.value,maritalRegime:els.maritalRegime.value,projectName:els.projectName.value,assetType:els.assetType.value,discountApartment:Number(els.discountApartment.value||0),discountParking:Number(els.discountParking.value||0),discountCellier:Number(els.discountCellier.value||0),discountTotal:Number(els.discountApartment.value||0)+Number(els.discountParking.value||0)+Number(els.discountCellier.value||0),extraWorks:Number(els.extraWorks.value||0),vatRate:Number(els.vatRate.value||19),financeMode:els.financeMode.value,responsableName:els.responsableName.value,responsableRole:els.responsableRole.value,validationName:els.validationName.value,validationRole:els.validationRole.value,selectedParkings:getParkings().map(x=>x.code),selectedCelliers:getCelliers().map(x=>x.code),tranches:getTranches(),coClients:getCoClients(),unitPriceHt:Number(els.unitPriceHt.value||0),parkingPrices:getParkings().reduce((acc,x)=>{acc[x.code]=x.priceHt;return acc;},{}),cellierPrices:getCelliers().reduce((acc,x)=>{acc[x.code]=x.priceHt;return acc;},{})};
  const pp={client_id:clientId,unit_code:els.unitCode.value,sale_price:c2.totalTtc,advance_amount:0,extra_works:Number(els.extraWorks.value||0),vat_rate:Number(els.vatRate.value||19),parking_codes:getParkings().map(x=>x.code).join(', '),cellier_codes:getCelliers().map(x=>x.code).join(', '),promise_date:new Date().toISOString().slice(0,10),vente_id:venteId,details_json:dj};
  if(existingPid){pp.id=existingPid;}else{pp.status='draft';}

  const r2=await fetch('api/promise_save.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(pp)});
  const j2=await r2.json();
  if(!j2.success||!j2.id){
    flash('Erreur promesse : '+(j2.error||'réponse invalide'),'err');
    return;
  }
  document.getElementById('promiseId').value=j2.id;

  // ── Étape 3 : Sauvegarder l'échéancier ───────────────────────────────────
  const tranches=getTranches();
  if(tranches.length>0){
    const installments=tranches.map(t=>({
      label:t.label,
      expected_amount:t.amount>0 ? Math.round(t.amount*1000)/1000 : Math.round(c2.totalTtc*(t.percent/100)*1000)/1000,
      due_date:t.date||null
    }));
    try{
      const rs=await fetch('api/schedule_save.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({promise_id:j2.id,installments})});
      const rj=await rs.json();
      if(!rj.success) flash('Avertissement — Échéancier non enregistré : '+(rj.error||'erreur'),'err');
    }catch(se){ flash('Avertissement — Échéancier : '+se.message,'err'); }
  }

  // EVOLO : le statut choisi dans la liste des prix (Réservé / Vendu) n'est enregistré qu'ici
  const _url=new URL(location.href);
  const _wanted=(_url.searchParams.get('status')||'').toUpperCase();
  if(normalizeProject(els.projectName.value)==='EVOLO' && (_wanted==='R'||_wanted==='V')){
    const rst=await fetch('api/evolo_update_status.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({kind:'UNIT',code:els.unitCode.value,status:_wanted==='V'?'Vendu':'Réservé'})});
    const jst=await rst.json().catch(()=>({}));
    if(!rst.ok||jst.error){flash('Fiche enregistrée, mais statut non changé : '+(jst.error||'erreur'),'err');return;}
    _url.searchParams.delete('status');
    flash('Fiche enregistrée. '+els.unitCode.value+' est maintenant '+(_wanted==='V'?'Vendu':'Réservé')+'.');
    setTimeout(function(){location.replace(_url.toString());},1500);
    return;
  }

  flash('Fiche enregistree avec succes.');
  setTimeout(function(){location.href=_url.toString();},1000);
}catch(e){flash('Erreur serveur : '+e.message,'err');}}
function openOptionVente(){
  const c=calc();
  const fullName=[els.firstName.value,els.lastName.value].filter(Boolean).join(' ').trim();
  const address=[els.address.value,els.zip.value,els.city.value].filter(Boolean).join(', ');
  const co=getCoClients().map(x=>({
    nom:[x.firstName,x.lastName].filter(Boolean).join(' ').trim(),
    cin:x.idNumber,
    tel:x.phone1,
    email:x.email1,
    address:[x.address,x.zip,x.city].filter(Boolean).join(', '),
  }));
  const params=new URLSearchParams({
    mode:'direct',
    client_nom:fullName,
    client_cin:els.idNumber.value,
    client_tel:els.phone1.value,
    client_email:els.email1.value,
    client_address:address,
    project:els.projectName.value,
    unit_code:els.unitCode.value,
    asset_type:els.assetType.value,
    parking:getParkings().map(x=>x.code).join(', '),
    cellier:getCelliers().map(x=>x.code).join(', '),
    area:els.shoArea.value||els.coveredArea.value||0,
    price_ht:c.totalHt,
    price_ttc:c.totalTtc,
    vat_rate:els.vatRate.value||19,
    advance:getParkings().length>0?c.totalTtc*0.1:0,
    co_clients:JSON.stringify(co),
  });
  window.open('option_vente.php?'+params.toString(),'_blank');
}
document.getElementById('addParkingBtn').onclick=()=>createParkingRow(); document.getElementById('addCellierBtn').onclick=()=>createCellierRow(); document.getElementById('addTrancheBtn').onclick=()=>createTrancheRow(10,''); document.getElementById('printBtn').onclick=()=>window.print(); document.getElementById('saveBtn').onclick=saveFiche; els.projectName.onchange=()=>{populateUnitSelect(''); applyUnitData(); parkingRows.innerHTML=''; cellierRows.innerHTML=''; refreshPreview();}; els.unitCode.onchange=applyUnitData; els.discountApartmentPct.addEventListener('input',()=>syncDiscountApartment('pct')); els.discountApartmentNet.addEventListener('input',()=>syncDiscountApartment('net')); els.discountParkingPct.addEventListener('input',()=>syncDiscountParking('pct')); els.discountParkingNet.addEventListener('input',()=>syncDiscountParking('net')); els.discountCellierPct.addEventListener('input',()=>syncDiscountCellier('pct')); els.discountCellierNet.addEventListener('input',()=>syncDiscountCellier('net')); els.discountGlobalPct.addEventListener('input',()=>syncDiscountGlobal('pct')); els.idType.addEventListener('change',validateIdNumber); els.idNumber.addEventListener('input',validateIdNumber); document.querySelectorAll('input,select,textarea').forEach(el=>{el.addEventListener('input',refreshPreview);el.addEventListener('change',refreshPreview);}); hydrate(); validateIdNumber();

// ── Notifications ────────────────────────────────────────────────────────
let notifOpen = false;
function toggleNotifPanel(){
  notifOpen = !notifOpen;
  document.getElementById('notifPanel').style.display = notifOpen ? 'block' : 'none';
  if (notifOpen) loadNotifications();
}
async function loadNotifications(){
  try {
    const r = await fetch('api/notifications_list.php?limit=20');
    const d = await r.json();
    const list = document.getElementById('notifList');
    const badge = document.getElementById('notifBadge');
    badge.textContent = d.unread_count || 0;
    badge.style.display = d.unread_count > 0 ? 'inline-flex' : 'none';
    if (!d.notifications || !d.notifications.length) {
      list.innerHTML = '<span style="font-size:12px;color:#888">Aucune alerte</span>'; return;
    }
    list.innerHTML = d.notifications.map(n => `
      <div onclick="markRead(${n.id},this)" style="padding:6px 8px;margin-bottom:4px;border-radius:7px;background:${n.is_read?'#fff':'#fffbeb'};border:1px solid ${n.is_read?'#e5e7eb':'#fde68a'};cursor:pointer;font-size:12px">
        <strong>${n.client_nom||''}</strong> – ${n.unit_code||''}<br>
        <span style="color:#c03030">${n.due_date ? new Date(n.due_date).toLocaleDateString('fr-FR') : ''}</span>
        ${n.amount ? ' · ' + Number(n.amount).toLocaleString('fr-FR',{minimumFractionDigits:3}) + ' TND' : ''}
        <div style="color:#555;margin-top:2px">${n.message||''}</div>
      </div>`).join('');
  } catch(e) { document.getElementById('notifList').innerHTML = '<span style="color:red;font-size:12px">Erreur chargement</span>'; }
}
async function markRead(id, el){
  await fetch('api/notification_read.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
  if (el) { el.style.background='#fff'; el.style.borderColor='#e5e7eb'; }
  loadNotifications();
}
async function markAllRead(){
  await fetch('api/notification_read.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({})});
  loadNotifications();
}

// ── Promesse signée ──────────────────────────────────────────────────────
async function marquerSignee(){
  const pid = document.getElementById('promiseId').value;
  if (!pid) { alert('Enregistrez d\'abord la fiche.'); return; }
  const d = prompt('Date de signature (AAAA-MM-JJ) :', new Date().toISOString().slice(0,10));
  if (!d) return;
  try {
    const res = await fetch('api/promise_save.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id: parseInt(pid), status:'signed', signed_date: d,
        client_id: parseInt(document.getElementById('clientId').value)||0, unit_code: els.unitCode.value, sale_price: calc().totalTtc})
    });
    const json = await res.json();
    if (json.success) { flash('Promesse marquée signée ✅'); setTimeout(()=>location.reload(),1200); }
    else flash(json.error||'Erreur','err');
  } catch(e) { flash('Erreur serveur','err'); }
}

// ── Upload justificatif ──────────────────────────────────────────────────
async function uploadDocument(){
  const pid  = document.getElementById('promiseId').value;
  const file = document.getElementById('docUpload').files[0];
  const status = document.getElementById('docUploadStatus');
  if (!pid) { status.textContent = '⚠ Enregistrez d\'abord la fiche.'; return; }
  if (!file) { status.textContent = '⚠ Sélectionnez un fichier.'; return; }
  status.textContent = 'Envoi en cours...';
  const fd = new FormData();
  fd.append('document', file);
  fd.append('promise_id', pid);
  fd.append('payment_id', 0);
  try {
    const res  = await fetch('api/payment_document_upload.php', {method:'POST', body:fd});
    const json = await res.json();
    if (json.success) { status.style.color='green'; status.textContent='✅ Justificatif ajouté.'; loadDocuments(pid); }
    else { status.style.color='red'; status.textContent='❌ '+json.error; }
  } catch(e) { status.style.color='red'; status.textContent='❌ Erreur serveur'; }
}
async function loadDocuments(pid){
  if (!pid) return;
  try {
    const res  = await fetch('api/payment_documents_list.php?promise_id='+pid);
    const docs = await res.json();
    const div  = document.getElementById('docList');
    if (!docs || !docs.length) { div.innerHTML=''; return; }
    div.innerHTML = '<div style="font-size:11px;font-weight:700;color:#555;margin-bottom:4px">Justificatifs enregistrés :</div>' +
      docs.map(d=>`<div style="display:flex;align-items:center;gap:6px;margin-bottom:3px">
        <span style="font-size:16px">${d.mime_type==='application/pdf'?'📄':'🖼'}</span>
        <a href="${d.file_path}" target="_blank" style="font-size:12px;color:#145f7d">${d.original_name}</a>
      </div>`).join('');
  } catch(e) {}
}

// ── Charger docs + check reminders au chargement ─────────────────────────
(async ()=>{
  const pid = document.getElementById('promiseId')?.value;
  if (pid) { loadDocuments(pid); }
  // Déclencher vérification reminders (idempotent)
  try { fetch('api/check_reminders.php'); } catch(e){}
})();
</script>
</body>
</html>
