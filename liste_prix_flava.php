<?php
require __DIR__ . '/config.php';
$username = htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$isAdmin  = (($_SESSION['role'] ?? '') === 'admin');

/* ══ Lecture du freeze FLAVA depuis app_settings ════════════════ */
$flavaPriceFrozen     = false;
$flavaFreezeYear      = null;
$flavaFreezeMonth     = null;
try {
    $settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM app_settings
                                  WHERE setting_key IN ('flava_price_frozen','flava_price_freeze_year','flava_price_freeze_month')");
    $settings = [];
    foreach ($settingsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $flavaPriceFrozen = ($settings['flava_price_frozen'] ?? '0') === '1';
    if ($flavaPriceFrozen) {
        $flavaFreezeYear  = (int)($settings['flava_price_freeze_year']  ?? date('Y'));
        $flavaFreezeMonth = (int)($settings['flava_price_freeze_month'] ?? date('n'));
    }
} catch (Throwable $e) {}

/* ══ Helper : floor label from ref_code when floor_name is empty ═══ */
function inferFloor(string $refCode): string {
    $code = strtoupper(trim($refCode));
    if (str_starts_with($code, 'D0-'))  return 'RDC+MEZZ';
    if (str_starts_with($code, 'D1-'))  return '2ème & 3ème étage';
    if (str_starts_with($code, 'A1-'))  return '1er étage';
    if (str_starts_with($code, 'B1-'))  return '1er étage';
    if (str_starts_with($code, 'B2-'))  return '2ème étage';
    if (str_starts_with($code, 'B3-'))  return '3ème étage';
    if (str_starts_with($code, 'M0'))   return 'Mezzanine';
    if (str_starts_with($code, 'LC'))   return 'RDC';
    return 'Autre';
}

/* ══ Unités FLAVA ═══════════════════════════════════════════════════ */
$stmt = $pdo->prepare("
    SELECT u.ref_code, u.floor_name, u.designation, u.type, u.surface,
           u.price_m2_ht_sale, u.price_ht_sale, u.price_ttc_sale, u.status_code
    FROM units u
    JOIN projects p ON p.id = u.project_id
    WHERE p.code = 'FLAVA' AND u.operation_mode = 'sale'
    ORDER BY u.floor_name, u.ref_code
");
$stmt->execute();
$units_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Filtre agence : uniquement les unités assignées */
if (strtolower(trim((string)($_SESSION['role'] ?? ''))) === 'agence') {
    $agStmt = $pdo->prepare("SELECT UPPER(unit_code) FROM agence_unit_access WHERE user_id = ? AND UPPER(project_code) = 'FLAVA'");
    $agStmt->execute([(int)$_SESSION['user_id']]);
    $agAllowed = array_flip($agStmt->fetchAll(PDO::FETCH_COLUMN));
    $units_raw = array_values(array_filter($units_raw, function($u) use ($agAllowed) {
        return isset($agAllowed[strtoupper(trim((string)$u['ref_code']))]);
    }));
}

/* Résoudre les floor_name vides et trier */
$floor_order_map = [
    'rdc'                => 0,
    '1er étage'          => 1,
    '1er etage'          => 1,
    '2ème étage'         => 2,
    '2eme etage'         => 2,
    '3ème étage'         => 3,
    '3eme etage'         => 3,
    'mezzanine'          => 4,
    'rdc+mezz'           => 5,
    '2ème & 3ème étage'  => 6,
    '2eme & 3eme etage'  => 6,
];
foreach ($units_raw as &$u) {
    if (empty(trim($u['floor_name']))) {
        $u['floor_name'] = inferFloor($u['ref_code']);
    }
}
unset($u);

usort($units_raw, function($a, $b) use ($floor_order_map) {
    $ka = strtolower(trim($a['floor_name']));
    $kb = strtolower(trim($b['floor_name']));
    $oa = $floor_order_map[$ka] ?? 9;
    $ob = $floor_order_map[$kb] ?? 9;
    if ($oa !== $ob) return $oa - $ob;
    return strcmp($a['ref_code'] ?? '', $b['ref_code'] ?? '');
});

/* ══ Accessoires FLAVA ══════════════════════════════════════════════ */
$stmtA = $pdo->prepare("
    SELECT a.code AS acc_code, a.accessory_type, a.floor_name, a.surface,
           a.price_m2_ht_sale, a.price_ht_sale, a.price_ttc_sale, a.status_code
    FROM unit_accessoires a
    JOIN projects p ON p.id = a.project_id
    WHERE p.code = 'FLAVA' AND a.operation_mode = 'sale'
    ORDER BY a.accessory_type, a.code
");
$stmtA->execute();
$accs = $stmtA->fetchAll(PDO::FETCH_ASSOC);
$parking_data = array_values(array_filter($accs, fn($a) => strtoupper($a['accessory_type']) === 'PARKING'));
$cellier_data = array_values(array_filter($accs, fn($a) => strtoupper($a['accessory_type']) === 'CELLIER'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>FLAVA – Liste des Prix</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/styles.css">
<style>
/* ══ Layout ═════════════════════════════════════════════════ */
.lp-wrap { padding: 20px 28px; max-width: 1440px; margin: 0 auto; }

/* ══ Month bar ══════════════════════════════════════════════ */
.month-bar {
  display: flex; align-items: center; gap: 10px; margin-bottom: 18px;
  flex-wrap: wrap; padding-bottom: 16px; border-bottom: 1px solid #e5e7eb;
}
.month-nav-btn {
  width: 32px; height: 32px; border: 1px solid #e5e7eb; background: #fff;
  border-radius: 7px; cursor: pointer; font-size: 16px; line-height: 1;
  color: #374151; display: flex; align-items: center; justify-content: center;
  transition: all .12s;
}
.month-nav-btn:hover { border-color: #111; background: #f9fafb; }
.month-nav-btn:disabled { opacity: .4; cursor: not-allowed; }
.month-select {
  font: inherit; font-size: 13px; font-weight: 600; padding: 6px 10px;
  border: 1px solid #e5e7eb; border-radius: 7px; background: #fff;
  color: #111; cursor: pointer; min-width: 170px;
}
.month-select:focus { outline: none; border-color: #111; }
.month-as-of { font-size: 12px; color: #9ca3af; margin-left: 4px; }
.month-as-of strong { color: #374151; }
.bar-spacer { flex: 1; }
.lp-print-btn {
  border: 1px solid #e5e7eb; background: #fff; font: inherit; font-size: 12px;
  padding: 6px 14px; border-radius: 6px; cursor: pointer; color: #374151;
  display: flex; align-items: center; gap: 5px;
}
.lp-print-btn:hover { border-color: #111; }
.lp-update-btn {
  border: 1px solid #3b82f6; background: #eff6ff; font: inherit; font-size: 12px;
  padding: 6px 14px; border-radius: 6px; cursor: pointer; color: #1d4ed8; font-weight: 600;
}
.lp-update-btn:hover { background: #dbeafe; }
.lp-update-btn:disabled { opacity: .5; cursor: not-allowed; }

.lp-freeze-btn {
  border: 1px solid #ef4444; background: #fef2f2; font: inherit; font-size: 12px;
  padding: 6px 14px; border-radius: 6px; cursor: pointer; color: #b91c1c; font-weight: 600;
  transition: background .15s;
}
.lp-freeze-btn:hover { background: #fee2e2; }
.lp-freeze-btn.is-frozen {
  border-color: #16a34a; background: #f0fdf4; color: #15803d;
}
.lp-freeze-btn.is-frozen:hover { background: #dcfce7; }

.freeze-banner {
  background: #fef9c3; border: 1px solid #fde047; color: #713f12;
  padding: 9px 16px; border-radius: 8px; font-size: 13px; font-weight: 600;
  margin-bottom: 14px;
}

/* ══ Hero ════════════════════════════════════════════════════ */
.lp-hero {
  text-align: center; margin-bottom: 18px;
  padding: 12px 0 10px; border-bottom: 3px solid #111;
}
.lp-hero h1 {
  font-size: 17px; font-weight: 900; letter-spacing: .08em;
  text-transform: uppercase; margin: 0 0 3px;
}
.lp-hero-date { font-size: 12px; color: #6b7280; font-weight: 600; }

/* ══ Tarif bar ═══════════════════════════════════════════════ */
.tarif-bar {
  display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap;
  padding: 10px 14px; background: #f9fafb; border: 1px solid #e5e7eb;
  border-radius: 10px;
}
.tarif-item { font-size: 11px; color: #374151; }
.tarif-item strong { color: #111; }
.tarif-sep { color: #d1d5db; }

/* ══ Stats bar ══════════════════════════════════════════════ */
.lp-stats { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
.lp-stat { text-align: center; padding: 8px 18px; border-radius: 10px; border: 1px solid #e5e7eb; cursor: pointer; transition: box-shadow .15s, transform .1s; user-select: none; }
.lp-stat:hover { box-shadow: 0 3px 10px rgba(0,0,0,.1); transform: translateY(-1px); }
.lp-stat .sv { font-size: 22px; font-weight: 700; line-height: 1; color: #111; }
.lp-stat .sl { font-size: 11px; color: #9ca3af; margin-top: 2px; }
.lp-stat.dispo  { border-color: #bbf7d0; background: #f0fdf4; }
.lp-stat.dispo  .sv { color: #16a34a; }
.lp-stat.reserve{ border-color: #fde68a; background: #fffbeb; }
.lp-stat.reserve .sv { color: #ca8a04; }
.lp-stat.vendu  { border-color: #fecaca; background: #fff1f2; }
.lp-stat.vendu  .sv { color: #dc2626; }
.lp-stat.active-filter { box-shadow: 0 0 0 3px #111; transform: translateY(-1px); }
.lp-stat.dispo.active-filter  { box-shadow: 0 0 0 3px #16a34a; }
.lp-stat.reserve.active-filter{ box-shadow: 0 0 0 3px #ca8a04; }
.lp-stat.vendu.active-filter  { box-shadow: 0 0 0 3px #dc2626; }

/* ══ Sections ════════════════════════════════════════════════ */
.lp-section { margin-bottom: 26px; }
.lp-section-hd {
  font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
  color: #6b7280; margin-bottom: 8px; padding-bottom: 5px;
  border-bottom: 1px solid #e5e7eb;
}

/* ══ Tables ══════════════════════════════════════════════════ */
.table-scroll { overflow-x: auto; }
table.lp-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
table.lp-tbl thead th {
  position: sticky; top: 0; z-index: 2;
  background: #f9fafb; border-bottom: 2px solid #e5e7eb;
  padding: 8px 10px; font-size: 10px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .06em; color: #6b7280;
  white-space: nowrap; text-align: right;
}
table.lp-tbl thead th.th-left  { text-align: left; }
table.lp-tbl thead th.th-center{ text-align: center; }
table.lp-tbl td {
  padding: 8px 10px; border-bottom: 1px solid #f3f4f6;
  white-space: nowrap; text-align: right;
}
table.lp-tbl td.td-left   { text-align: left; }
table.lp-tbl td.td-center { text-align: center; }
table.lp-tbl tr:hover td  { background: #fafafa; }
table.lp-tbl tr.floor-group td {
  background: #f3f4f6 !important; font-weight: 700; text-align: left;
  color: #374151; font-size: 11px; padding: 6px 10px;
  border-top: 2px solid #d1d5db; border-bottom: 1px solid #d1d5db;
  letter-spacing: .04em; text-transform: uppercase;
}
table.lp-tbl tr.sold-row td { background: #fef2f2; }
table.lp-tbl tr.sold-row td.price-frozen { color: #dc2626; font-weight: 700; }
.lp-badge {
  display: inline-block; font-size: 10px; font-weight: 700;
  padding: 2px 8px; border-radius: 999px; letter-spacing: .02em;
}
.lp-badge.d { background: #dcfce7; color: #16a34a; }
.lp-badge.r { background: #fef9c3; color: #ca8a04; }
.lp-badge.v { background: #fee2e2; color: #dc2626; }
.lp-legend {
  display: flex; gap: 14px; align-items: center; flex-wrap: wrap;
  font-size: 11px; color: #6b7280; margin-top: 10px; margin-bottom: 4px;
}
.legend-item { display: flex; align-items: center; gap: 5px; }

/* ══ Historical ══════════════════════════════════════════════ */
.hist-toggle-btn {
  display: flex; align-items: center; gap: 8px; width: 100%;
  background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;
  padding: 10px 14px; cursor: pointer; font: inherit; font-size: 13px;
  font-weight: 600; color: #374151; transition: background .12s; margin-top: 8px;
}
.hist-toggle-btn:hover { background: #f3f4f6; }
.hist-arrow { font-size: 11px; transition: transform .2s; }
.hist-toggle-btn.open .hist-arrow { transform: rotate(90deg); }
.hist-panel { display: none; margin-top: 12px; }
.hist-panel.open { display: block; }
.hist-scroll { overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 8px; }
table.hist-tbl { border-collapse: collapse; font-size: 11px; }
table.hist-tbl th {
  padding: 7px 12px; background: #f9fafb; border-right: 1px solid #e5e7eb;
  border-bottom: 2px solid #d1d5db; font-size: 10px; font-weight: 700;
  text-transform: uppercase; color: #6b7280; white-space: nowrap;
  text-align: right; min-width: 88px;
}
table.hist-tbl th:first-child {
  text-align: left; min-width: 200px; position: sticky; left: 0;
  background: #f9fafb; z-index: 2;
}
table.hist-tbl td {
  padding: 7px 12px; border-right: 1px solid #f3f4f6;
  border-bottom: 1px solid #f3f4f6; text-align: right; white-space: nowrap;
}
table.hist-tbl td:first-child {
  text-align: left; font-weight: 600; position: sticky; left: 0;
  background: #fff; z-index: 1; border-right: 1px solid #e5e7eb; font-size: 11px;
}
table.hist-tbl .cur-col { background: #fffbeb !important; }
table.hist-tbl thead .cur-col { background: #fef3c7 !important; }
table.hist-tbl tr:hover td { background: #fafafa; }
table.hist-tbl tr:hover td:first-child { background: #f9fafb; }
table.hist-tbl .hist-sep td { border-top: 2px solid #e5e7eb; }

/* ══ Typology filter bar ══════════════════════════════════════ */
.typo-filter {
  display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px;
}
.typo-btn {
  font: inherit; font-size: 12px; font-weight: 600;
  padding: 5px 14px; border-radius: 999px; cursor: pointer;
  border: 1.5px solid #e5e7eb; background: #fff; color: #6b7280;
  transition: all .13s; letter-spacing: .02em;
}
.typo-btn:hover { border-color: #9ca3af; color: #374151; }
.typo-btn.active { background: #111; border-color: #111; color: #fff; }

/* ══ Parking / Cellier button grid ═══════════════════════════ */
.acc-price-bar {
  display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
  padding: 10px 14px; background: #f9fafb; border: 1px solid #e5e7eb;
  border-radius: 10px; margin-bottom: 12px; font-size: 12px;
}
.acc-price-bar .apl { color: #6b7280; }
.acc-price-bar strong { color: #111; font-size: 13px; }
.acc-price-bar .acc-counts { margin-left: auto; display: flex; gap: 10px; }
.acc-count-chip {
  font-size: 11px; font-weight: 700; padding: 2px 10px;
  border-radius: 999px;
}
.acc-count-chip.d { background: #dcfce7; color: #16a34a; }
.acc-count-chip.r { background: #fef9c3; color: #ca8a04; }
.acc-count-chip.v { background: #fee2e2; color: #dc2626; }

.acc-grid {
  display: flex; flex-wrap: wrap; gap: 6px;
}
.acc-btn {
  display: inline-flex; flex-direction: column; align-items: center;
  justify-content: center; min-width: 52px; padding: 5px 8px;
  border-radius: 8px; border: 1.5px solid transparent;
  cursor: default; font: inherit; font-size: 11px; font-weight: 700;
  line-height: 1.3; transition: transform .1s, box-shadow .1s;
  text-align: center;
}
.acc-btn.d { background: #f0fdf4; border-color: #86efac; color: #15803d; }
.acc-btn.r { background: #fffbeb; border-color: #fcd34d; color: #b45309; }
.acc-btn.v { background: #fff1f2; border-color: #fca5a5; color: #b91c1c; }
.acc-btn .acc-sub { font-size: 9px; font-weight: 500; opacity: .75; margin-top: 1px; }

/* Cellier cards (plus grand, plus d'info) */
.cell-grid { display: flex; flex-wrap: wrap; gap: 10px; }
.cell-card {
  display: flex; flex-direction: column; gap: 2px;
  padding: 10px 14px; border-radius: 10px; border: 1.5px solid transparent;
  min-width: 130px; font: inherit; cursor: default;
}
.cell-card.d { background: #f0fdf4; border-color: #86efac; }
.cell-card.r { background: #fffbeb; border-color: #fcd34d; }
.cell-card.v { background: #fff1f2; border-color: #fca5a5; }
.cell-card .cc-code { font-size: 13px; font-weight: 800; color: #111; }
.cell-card .cc-surf { font-size: 11px; color: #6b7280; }
.cell-card .cc-price { font-size: 12px; font-weight: 700; margin-top: 4px; }
.cell-card.d .cc-price { color: #15803d; }
.cell-card.r .cc-price { color: #b45309; }
.cell-card.v .cc-price { color: #b91c1c; }
.cell-card .cc-badge { font-size: 10px; margin-top: 3px; }

/* ══ Print ════════════════════════════════════════════════════ */
@media print {
  .app-header, .month-bar, .hist-toggle-btn, .hist-panel, .lp-legend,
  .tarif-bar, .typo-filter { display: none !important; }
  .lp-wrap { padding: 6px; }
  table.lp-tbl thead th { position: static; }
}
/* ══ Back button ══════════════════════════════════════════════ */
.back-fab {
  position: fixed; bottom: 24px; left: 24px; z-index: 999;
  width: 44px; height: 44px; border-radius: 50%;
  background: #111; color: #fff; border: none;
  font-size: 20px; line-height: 1; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 14px rgba(0,0,0,.25);
  transition: background .15s, transform .15s;
  text-decoration: none;
}
.back-fab:hover { background: #333; transform: translateX(-3px); }
</style>
</head>
<body>
<button class="back-fab" onclick="history.back()" title="Page précédente">&#8592;</button>

<header class="app-header">
  <div class="brand">
    <span class="logo">FLAVA</span>
    <span class="subtitle">Liste des Prix</span>
  </div>
  <div class="user-bar">
    <a class="header-link" href="liste_prix_ava.php">AVA →</a>
    <span class="user-name"><?= $username ?></span>
    <a class="logout-link" href="logout.php">Se déconnecter</a>
  </div>
</header>

<div class="lp-wrap">

  <!-- Month navigation -->
  <div class="month-bar">
    <button class="month-nav-btn" id="prevBtn" title="Mois précédent">&#8249;</button>
    <select class="month-select" id="monthSel"></select>
    <button class="month-nav-btn" id="nextBtn" title="Mois suivant">&#8250;</button>
    <div class="month-as-of">Prix au <strong id="asOfLabel"></strong></div>
    <div class="bar-spacer"></div>
    <button class="lp-print-btn" onclick="window.print()">&#128438; Imprimer</button>
    <?php if ($isAdmin): ?>
    <button class="lp-freeze-btn <?= $flavaPriceFrozen ? 'is-frozen' : '' ?>" id="freezeBtn" onclick="doFreezeToggle()">
      <?= $flavaPriceFrozen
          ? '&#128275; Reprendre l\'augmentation'
          : '&#128274; Bloquer l\'augmentation' ?>
    </button>
    <?php endif; ?>
  </div>
  <?php if ($flavaPriceFrozen): ?>
  <div class="freeze-banner">
    &#9889; Prix bloqués depuis
    <?php
      $months_fr = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
      echo $months_fr[$flavaFreezeMonth] . ' ' . $flavaFreezeYear;
    ?>
     — L'augmentation mensuelle est suspendue.
  </div>
  <?php endif; ?>

  <!-- Résultat mise à jour -->
  <div id="updateResult" style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:8px;font-size:13px;"></div>

  <!-- Hero -->
  <div class="lp-hero">
    <h1>FLAVA Résidences &mdash; Liste des Prix</h1>
    <div class="lp-hero-date" id="heroDate"></div>
  </div>

  <!-- Tarifs du mois -->
  <div class="tarif-bar" id="tarifBar"></div>

  <!-- Stats -->
  <div class="lp-stats" id="statsBar"></div>

  <!-- ═══ APPARTEMENTS, DUPLEX, PENTHOUSE, LOFT ════════════ -->
  <div class="lp-section">
    <div class="lp-section-hd">Appartements — Duplex — Penthouse &amp; Loft</div>
    <div class="typo-filter" id="typoFilter"></div>
    <div class="table-scroll">
      <table class="lp-tbl">
        <thead>
          <tr>
            <th class="th-left">Désignation</th>
            <th class="th-left">Réf.</th>
            <th class="th-left">Type</th>
            <th>Surface (m²)</th>
            <th>Prix/m² HT</th>
            <th>Prix/m² TTC</th>
            <th>Prix Total HT</th>
            <th>Prix Total TTC</th>
            <th class="th-center">Disp.</th>
          </tr>
        </thead>
        <tbody id="aptBody"></tbody>
      </table>
    </div>
    <div class="lp-legend">
      <span class="legend-item"><span class="lp-badge d">D</span> Disponible</span>
      <span class="legend-item"><span class="lp-badge r">R</span> Réservé</span>
      <span class="legend-item"><span class="lp-badge v">V</span> Vendu &mdash; prix de vente figé</span>
    </div>
  </div>

  <!-- ═══ LOCAUX COMMERCIAUX ══════════════════════════════════ -->
  <div class="lp-section" id="secComm">
    <div class="lp-section-hd">Locaux Commerciaux (RDC)</div>
    <div class="table-scroll">
      <table class="lp-tbl">
        <thead>
          <tr>
            <th class="th-left">Réf.</th>
            <th>Surface (m²)</th>
            <th>Prix/m² HT</th>
            <th>Prix/m² TTC</th>
            <th>Prix Total HT</th>
            <th>Prix Total TTC</th>
            <th class="th-center">Disp.</th>
          </tr>
        </thead>
        <tbody id="commBody"></tbody>
      </table>
    </div>
  </div>

  <!-- ═══ PARKING ══════════════════════════════════════════════ -->
  <div class="lp-section" id="secParking">
    <div class="lp-section-hd">Parking (<?= count($parking_data) ?> places)</div>
    <div class="acc-price-bar" id="parkPriceBar">
      <span class="apl">Prix / place :</span>
      <span><strong id="parkPriceHt">—</strong> HT &nbsp;·&nbsp; <strong id="parkPriceTtc">—</strong> TTC</span>
      <div class="acc-counts" id="parkCounts"></div>
    </div>
    <div class="acc-grid" id="parkingGrid"></div>
  </div>

  <!-- ═══ CELLIERS ════════════════════════════════════════════ -->
  <div class="lp-section" id="secCellier">
    <div class="lp-section-hd">Celliers</div>
    <div class="acc-price-bar" id="cellPriceBar">
      <span class="apl">Prix/m² :</span>
      <span><strong id="cellPriceHt">—</strong> HT &nbsp;·&nbsp; <strong id="cellPriceTtc">—</strong> TTC</span>
      <div class="acc-counts" id="cellCounts"></div>
    </div>
    <div class="cell-grid" id="cellierGrid"></div>
  </div>

  <!-- ═══ HISTORIQUE ═══════════════════════════════════════════ -->
  <button class="hist-toggle-btn" id="histBtn" onclick="toggleHist()">
    <span class="hist-arrow" id="histArrow">&#9658;</span>
    Historique des prix &mdash; Évolution mensuelle (sept. 2025 &rarr; déc. 2027)
  </button>
  <div class="hist-panel" id="histPanel">
    <div class="hist-scroll">
      <table class="hist-tbl" id="histTbl"></table>
    </div>
  </div>

</div><!-- .lp-wrap -->

<script>
window.__FLAVA_PRICE_CFG__ = <?= json_encode([
    'frozen' => $flavaPriceFrozen,
    'year'   => $flavaFreezeYear,
    'month'  => $flavaFreezeMonth !== null ? $flavaFreezeMonth - 1 : null, // JS 0-indexed
], JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
/* ══ Data from PHP ══════════════════════════════════════════ */
const UNITS    = <?= json_encode(array_values($units_raw),  JSON_UNESCAPED_UNICODE) ?>;
const PARKINGS = <?= json_encode($parking_data,             JSON_UNESCAPED_UNICODE) ?>;
const CELLIERS = <?= json_encode($cellier_data,             JSON_UNESCAPED_UNICODE) ?>;

/* ══ Price constants (base = septembre 2025) ════════════════ */
const S1_BASE   = 5700;    // TND/m²
const S2_BASE   = 5500;
const S3_BASE   = 5450;    // S+3, S+3,5, S+4,5 et assimilés
const DUP_BASE  = 5350;    // Duplex
const PENT_BASE = 5300;    // Penthouse + Loft
const COMM_BASE = 12000;   // Local commercial (prix fixe, non indexé)
const PARK_BASE = 30000;   // TND/place
const CELL_BASE = 2730;    // TND/m²
const MONTHLY_RATE = 0.005;
const VAT          = 0.19;

const BASE_YEAR = 2025;
const BASE_MO   = 8; // 0-indexed : septembre = 8

/* ══ All months: sept 2025 → déc 2027 ══════════════════════ */
const MONTHS = (() => {
  const m = [];
  for (let y = 2025; y <= 2027; y++) {
    for (let mo = 0; mo < 12; mo++) {
      if (y === 2025 && mo < 8) continue;
      m.push({ y, mo });
    }
  }
  return m;
})();

/* ══ Config prix (freeze injecté par PHP) ════════════════════ */
const PRICE_CFG = window.__FLAVA_PRICE_CFG__ || { frozen: false };

/* ══ State ═══════════════════════════════════════════════════ */
const _now = new Date();
let selY   = PRICE_CFG.frozen ? PRICE_CFG.year  : _now.getFullYear();
let selMo  = PRICE_CFG.frozen ? PRICE_CFG.month : _now.getMonth(); // JS 0-indexed
let activeFilter = null; // null=tous, 'D','R','V'
let selTypo = 'TOUS';
const firstM = MONTHS[0], lastM = MONTHS[MONTHS.length - 1];
if (selY < firstM.y || (selY === firstM.y && selMo < firstM.mo)) { selY = firstM.y; selMo = firstM.mo; }
// Si frozen, ne pas dépasser le mois bloqué
const _maxY  = PRICE_CFG.frozen ? PRICE_CFG.year  : lastM.y;
const _maxMo = PRICE_CFG.frozen ? PRICE_CFG.month : lastM.mo;
if (selY > _maxY  || (selY === _maxY  && selMo > _maxMo))  { selY = _maxY;  selMo = _maxMo; }

/* ══ Helpers ═════════════════════════════════════════════════ */
function elapsed(y, mo) {
  return (y - BASE_YEAR) * 12 + (mo - BASE_MO);
}

/* Base price per unit (September 2025) */
function unitBase(u) {
  const desig = (u.designation || '').trim().toUpperCase();
  const type  = (u.type || '').trim().toUpperCase().replace(',', '.');
  if (desig === 'COMMERCE') return { base: COMM_BASE, fixed: true };
  if (desig === 'DUPLEX')   return { base: DUP_BASE,  fixed: false };
  if (desig === 'PENTHOUSE' || desig === 'LOFT') return { base: PENT_BASE, fixed: false };
  // Apartments
  if (type === 'S+1') return { base: S1_BASE, fixed: false };
  if (type === 'S+2') return { base: S2_BASE, fixed: false };
  return { base: S3_BASE, fixed: false }; // S+3, S+3.5, S+4.5, …
}

function unitM2Ht(u, y, mo) {
  const { base, fixed } = unitBase(u);
  if (fixed) return base;
  const n = Math.max(0, elapsed(y, mo));
  return Math.round(base * Math.pow(1 + MONTHLY_RATE, n) * 1000) / 1000;
}

function parkingHT(y, mo) {
  return Math.round(PARK_BASE * Math.pow(1 + MONTHLY_RATE, Math.max(0, elapsed(y, mo))) * 1000) / 1000;
}
function cellierM2(y, mo) {
  return Math.round(CELL_BASE * Math.pow(1 + MONTHLY_RATE, Math.max(0, elapsed(y, mo))) * 1000) / 1000;
}

function r3(n) { return Math.round((n || 0) * 1000) / 1000; }
function r0(n) { return Math.round(n || 0); }
function fmt(n, dec) {
  return (n || 0).toLocaleString('fr-FR', { minimumFractionDigits: dec, maximumFractionDigits: dec });
}
function fmtMoney(n) { return fmt(r0(n), 0) + ' TND'; }
function fmtM2(n)    { return fmt(r3(n), 3) + ' TND'; }
function badge(s) {
  const map = { D:['d','D'], R:['r','R'], V:['v','V'] };
  const [c, l] = map[s] || ['d', s||'—'];
  return `<span class="lp-badge ${c}">${l}</span>`;
}
function longDate(y, mo) {
  return new Date(y, mo, 1).toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' });
}
function shortMo(y, mo) {
  return new Date(y, mo, 1).toLocaleDateString('fr-FR', { month: 'short', year: '2-digit' });
}

/* ══ Floor labels ════════════════════════════════════════════ */
const FLOOR_LABELS = {
  'rdc': 'RDC', '1er étage': '1er étage', '1er etage': '1er étage',
  '2ème étage': '2ème étage', '2eme etage': '2ème étage',
  '3ème étage': '3ème étage', '3eme etage': '3ème étage',
  'mezzanine': 'Mezzanine',
  'rdc+mezz': 'RDC + Mezzanine (Duplex)',
  '2ème & 3ème étage': '2ème & 3ème étage (Penthouse)',
  '2eme & 3eme etage': '2ème & 3ème étage (Penthouse)',
};
function floorLabel(v) {
  if (!v) return '—';
  return FLOOR_LABELS[v.toLowerCase().trim()] || v;
}

/* Designation display */
function desigLabel(desig) {
  const map = {
    'APARTMENT': 'Appartement', 'APARTEMENT': 'Appartement',
    'DUPLEX': 'Duplex', 'PENTHOUSE': 'Penthouse', 'LOFT': 'Loft',
    'COMMERCE': 'Local Commercial',
  };
  return map[(desig||'').toUpperCase().trim()] || desig || '—';
}

/* ══ Month selector ══════════════════════════════════════════ */
function initSel() {
  const sel = document.getElementById('monthSel');
  MONTHS.forEach(({ y, mo }) => {
    const o = document.createElement('option');
    o.value = `${y}-${mo}`;
    o.textContent = new Date(y, mo, 1).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
    o.textContent = o.textContent.charAt(0).toUpperCase() + o.textContent.slice(1);
    if (y === selY && mo === selMo) o.selected = true;
    sel.appendChild(o);
  });
  sel.addEventListener('change', () => {
    const [y, mo] = sel.value.split('-').map(Number);
    selY = y; selMo = mo;
    renderAll(); updateNavBtns();
  });
  document.getElementById('prevBtn').addEventListener('click', () => navigate(-1));
  document.getElementById('nextBtn').addEventListener('click', () => navigate(+1));
}

function navigate(dir) {
  const idx = MONTHS.findIndex(m => m.y === selY && m.mo === selMo);
  const ni = idx + dir;
  if (ni < 0 || ni >= MONTHS.length) return;
  selY = MONTHS[ni].y; selMo = MONTHS[ni].mo;
  document.getElementById('monthSel').value = `${selY}-${selMo}`;
  renderAll(); updateNavBtns();
}

function updateNavBtns() {
  const idx    = MONTHS.findIndex(m => m.y === selY && m.mo === selMo);
  const maxIdx = PRICE_CFG.frozen
    ? MONTHS.findIndex(m => m.y === PRICE_CFG.year && m.mo === PRICE_CFG.month)
    : MONTHS.length - 1;
  document.getElementById('prevBtn').disabled = idx <= 0;
  document.getElementById('nextBtn').disabled = idx >= Math.max(0, maxIdx);
}

/* ══ Tarif bar ═══════════════════════════════════════════════ */
function renderTarifBar() {
  const items = [
    ['S+1',      fmtM2(unitM2Ht({ designation:'APARTMENT', type:'S+1' }, selY, selMo))],
    ['S+2',      fmtM2(unitM2Ht({ designation:'APARTMENT', type:'S+2' }, selY, selMo))],
    ['S+3/S+3,5',fmtM2(unitM2Ht({ designation:'APARTMENT', type:'S+3' }, selY, selMo))],
    ['Duplex',   fmtM2(unitM2Ht({ designation:'DUPLEX',    type:''    }, selY, selMo))],
    ['Penthouse',fmtM2(unitM2Ht({ designation:'PENTHOUSE', type:''    }, selY, selMo))],
    ['Parking',  fmtMoney(parkingHT(selY, selMo))],
    ['Cellier',  fmtM2(cellierM2(selY, selMo))],
  ];
  document.getElementById('tarifBar').innerHTML = items.map((item, i) =>
    (i > 0 ? '<span class="tarif-sep">|</span>' : '') +
    `<span class="tarif-item"><strong>${item[0]} :</strong> ${item[1]}</span>`
  ).join('');
}

/* ══ Stats ═══════════════════════════════════════════════════ */
function renderStats() {
  const d = UNITS.filter(u => u.status_code === 'D').length;
  const r = UNITS.filter(u => u.status_code === 'R').length;
  const v = UNITS.filter(u => u.status_code === 'V').length;
  const total = UNITS.length;
  const af = activeFilter;
  document.getElementById('statsBar').innerHTML =
    `<div class="lp-stat ${af===null?'active-filter':''}" onclick="setFilter(null)" title="Tout afficher">
      <div class="sv">${total}</div><div class="sl">Total unités</div></div>` +
    `<div class="lp-stat dispo ${af==='D'?'active-filter':''}" onclick="setFilter('D')" title="Disponibles uniquement">
      <div class="sv">${d}</div><div class="sl">Disponible${d>1?'s':''}</div></div>` +
    (r ? `<div class="lp-stat reserve ${af==='R'?'active-filter':''}" onclick="setFilter('R')" title="Réservés uniquement">
      <div class="sv">${r}</div><div class="sl">Réservé${r>1?'s':''}</div></div>` : '') +
    (v ? `<div class="lp-stat vendu ${af==='V'?'active-filter':''}" onclick="setFilter('V')" title="Vendus uniquement">
      <div class="sv">${v}</div><div class="sl">Vendu${v>1?'s':''}</div></div>` : '');
}

function setFilter(f) {
  activeFilter = (activeFilter === f) ? null : f;
  renderStats();
  renderApt();
  renderComm();
  renderParking();
  renderCelliers();
}

/* ══ Typology filter ═════════════════════════════════════════ */
const TYPO_DEFS = [
  { key: 'TOUS',       label: 'Tous' },
  { key: 'S+1',        label: 'S+1' },
  { key: 'S+2',        label: 'S+2' },
  { key: 'S+3',        label: 'S+3 / S+3,5' },
  { key: 'DUPLEX',     label: 'Duplex' },
  { key: 'PENTHOUSE',  label: 'Penthouse / Loft' },
  { key: 'COMMERCE',   label: 'Commerce' },
];

function typoMatch(u, key) {
  if (key === 'TOUS') return true;
  const desig = (u.designation || '').trim().toUpperCase();
  const type  = (u.type || '').trim().toUpperCase().replace(',', '.');
  if (key === 'DUPLEX')    return desig === 'DUPLEX';
  if (key === 'PENTHOUSE') return desig === 'PENTHOUSE' || desig === 'LOFT';
  if (key === 'COMMERCE')  return desig === 'COMMERCE';
  if (key === 'S+1')       return type === 'S+1';
  if (key === 'S+2')       return type === 'S+2';
  if (key === 'S+3')       return ['S+3','S+3.5','S+4.5','S+41/2'].includes(type) ||
                                  (type.startsWith('S+') && !['S+1','S+2'].includes(type) &&
                                   desig !== 'DUPLEX' && desig !== 'PENTHOUSE' && desig !== 'LOFT');
  return false;
}

function initTypoFilter() {
  const bar = document.getElementById('typoFilter');
  TYPO_DEFS.forEach(({ key, label }) => {
    const btn = document.createElement('button');
    btn.className = 'typo-btn' + (key === selTypo ? ' active' : '');
    btn.textContent = label;
    btn.dataset.key = key;
    btn.onclick = () => {
      selTypo = key;
      bar.querySelectorAll('.typo-btn').forEach(b => b.classList.toggle('active', b.dataset.key === key));
      renderApt();
    };
    bar.appendChild(btn);
  });
}

/* ══ Appartements + Duplex + Penthouse + Loft ════════════════ */
function renderApt() {
  const aptUnits = UNITS.filter(u => {
    const desig = (u.designation || '').toUpperCase();
    const typoOk = selTypo === 'TOUS'     ? desig !== 'COMMERCE' :
                   selTypo === 'COMMERCE' ? desig === 'COMMERCE' :
                   desig !== 'COMMERCE' && typoMatch(u, selTypo);
    return typoOk && (!activeFilter || u.status_code === activeFilter);
  });

  if (!aptUnits.length) {
    document.getElementById('aptBody').innerHTML =
      '<tr><td colspan="9" style="text-align:center;padding:28px;color:#9ca3af;font-style:italic">Aucune unité pour ce filtre</td></tr>';
    return;
  }

  let lastFloor = null, html = '';

  aptUnits.forEach(u => {
    const flr = floorLabel(u.floor_name);
    if (flr !== lastFloor) {
      html += `<tr class="floor-group"><td colspan="9">${flr}</td></tr>`;
      lastFloor = flr;
    }

    const isSold = u.status_code === 'V';
    const surf   = parseFloat(u.surface || 0);
    const m2Ht   = unitM2Ht(u, selY, selMo);
    const m2Ttc  = r3(m2Ht * (1 + VAT));
    const totHt  = r0(m2Ht  * surf);
    const totTtc = r0(m2Ttc * surf);
    const tr = isSold ? 'sold-row' : '';
    const pc = '';
    const navUrl = 'dashboard_flava.php?unit_code=' + encodeURIComponent(u.ref_code);
    html += `<tr class="${tr}" style="cursor:pointer" onclick="window.location='${navUrl}'">
      <td class="td-left">${desigLabel(u.designation)}</td>
      <td class="td-left"><strong>${u.ref_code}</strong></td>
      <td class="td-left">${u.type || '—'}</td>
      <td>${fmt(surf, 2)} m²</td>
      <td class="${pc}">${fmtM2(m2Ht)}</td>
      <td>${fmtM2(m2Ttc)}</td>
      <td class="${pc}">${fmtMoney(totHt)}</td>
      <td>${fmtMoney(totTtc)}</td>
      <td class="td-center">${badge(u.status_code)}</td>
    </tr>`;
  });

  document.getElementById('aptBody').innerHTML = html;
}

/* ══ Locaux Commerciaux ══════════════════════════════════════ */
function renderComm() {
  const commUnits = UNITS.filter(u =>
    (u.designation||'').toUpperCase() === 'COMMERCE' &&
    (!activeFilter || u.status_code === activeFilter)
  );
  if (!commUnits.length) {
    document.getElementById('secComm').style.display = 'none';
    return;
  }

  let html = '';
  commUnits.forEach(u => {
    const isSold = u.status_code === 'V';
    const surf   = parseFloat(u.surface || 0);
    const m2Ht   = COMM_BASE;
    const m2Ttc  = r3(m2Ht * (1 + VAT));
    const totHt  = r0(m2Ht * surf);
    const totTtc = r0(m2Ttc * surf);
    const tr = isSold ? 'sold-row' : '';
    const pc = '';
    const navUrl = 'dashboard_flava.php?unit_code=' + encodeURIComponent(u.ref_code);
    html += `<tr class="${tr}" style="cursor:pointer" onclick="window.location='${navUrl}'">
      <td class="td-left"><strong>${u.ref_code}</strong></td>
      <td>${fmt(surf, 2)} m²</td>
      <td class="${pc}">${fmtM2(m2Ht)}</td>
      <td>${fmtM2(m2Ttc)}</td>
      <td class="${pc}">${fmtMoney(totHt)}</td>
      <td>${fmtMoney(totTtc)}</td>
      <td class="td-center">${badge(u.status_code)}</td>
    </tr>`;
  });
  document.getElementById('commBody').innerHTML = html;
}

/* ══ Parking ═════════════════════════════════════════════════ */
function renderParking() {
  const prHt  = parkingHT(selY, selMo);
  const prTtc = r0(prHt * (1 + VAT));

  document.getElementById('parkPriceHt').textContent  = fmtMoney(prHt);
  document.getElementById('parkPriceTtc').textContent = fmtMoney(prTtc);

  const nd = PARKINGS.filter(p => p.status_code === 'D').length;
  const nr = PARKINGS.filter(p => p.status_code === 'R').length;
  const nv = PARKINGS.filter(p => p.status_code === 'V').length;
  document.getElementById('parkCounts').innerHTML =
    `<span class="acc-count-chip d">${nd} dispo</span>` +
    (nr ? `<span class="acc-count-chip r">${nr} réservé${nr>1?'s':''}</span>` : '') +
    (nv ? `<span class="acc-count-chip v">${nv} vendu${nv>1?'s':''}</span>` : '');

  const grid = document.getElementById('parkingGrid');
  const filteredPark = activeFilter ? PARKINGS.filter(p => p.status_code === activeFilter) : PARKINGS;
  if (!filteredPark.length) {
    grid.innerHTML = '<span style="font-size:12px;color:#9ca3af;font-style:italic">Aucun parking pour ce filtre</span>';
    return;
  }
  grid.innerHTML = filteredPark.map(p => {
    const s = (p.status_code || 'D').toUpperCase();
    const cls = s === 'V' ? 'v' : s === 'R' ? 'r' : 'd';
    return `<div class="acc-btn ${cls}" title="${fmtMoney(prHt)} HT">
      <span>${p.acc_code}</span>
      <span class="acc-sub">${fmtMoney(prHt)}</span>
    </div>`;
  }).join('');
}

/* ══ Celliers ════════════════════════════════════════════════ */
function renderCelliers() {
  if (!CELLIERS.length) {
    document.getElementById('secCellier').style.display = 'none';
    return;
  }
  const cm2Ht  = cellierM2(selY, selMo);
  const cm2Ttc = r3(cm2Ht * (1 + VAT));

  document.getElementById('cellPriceHt').textContent  = fmtM2(cm2Ht);
  document.getElementById('cellPriceTtc').textContent = fmtM2(cm2Ttc);

  const nd = CELLIERS.filter(c => c.status_code === 'D').length;
  const nr = CELLIERS.filter(c => c.status_code === 'R').length;
  const nv = CELLIERS.filter(c => c.status_code === 'V').length;
  document.getElementById('cellCounts').innerHTML =
    `<span class="acc-count-chip d">${nd} dispo</span>` +
    (nr ? `<span class="acc-count-chip r">${nr} réservé${nr>1?'s':''}</span>` : '') +
    (nv ? `<span class="acc-count-chip v">${nv} vendu${nv>1?'s':''}</span>` : '');

  const grid = document.getElementById('cellierGrid');
  const filteredCell = activeFilter ? CELLIERS.filter(c => c.status_code === activeFilter) : CELLIERS;
  if (!filteredCell.length) {
    grid.innerHTML = '<span style="font-size:12px;color:#9ca3af;font-style:italic">Aucun cellier pour ce filtre</span>';
    return;
  }
  grid.innerHTML = filteredCell.map(c => {
    const s = (c.status_code || 'D').toUpperCase();
    const cls = s === 'V' ? 'v' : s === 'R' ? 'r' : 'd';
    const surf  = parseFloat(c.surface || 0);
    const cM2   = cm2Ht;
    const totHt = r0(cm2Ht * surf);
    return `<div class="cell-card ${cls}">
      <span class="cc-code">${c.acc_code}</span>
      <span class="cc-surf">${fmt(surf, 2)} m²</span>
      <span class="cc-price">${fmtMoney(totHt)} HT</span>
      <span class="cc-badge">${badge(s)}</span>
    </div>`;
  }).join('');
}

/* ══ Meta labels ══════════════════════════════════════════════ */
function renderMeta() {
  const lbl = longDate(selY, selMo);
  document.getElementById('asOfLabel').textContent = lbl;
  document.getElementById('heroDate').textContent  = 'Arrêté au ' + lbl + '  ·  TVA ' + (VAT * 100) + '%';
}

/* ══ Historique ═══════════════════════════════════════════════ */
let histBuilt = false;
function renderHistory() {
  if (histBuilt) return;
  histBuilt = true;

  const nowY = _now.getFullYear(), nowMo = _now.getMonth();
  let th = '<thead><tr><th>Désignation</th>';
  MONTHS.forEach(({ y, mo }) => {
    const cur = (y === nowY && mo === nowMo) ? ' cur-col' : '';
    th += `<th class="${cur.trim()}">${shortMo(y, mo)}</th>`;
  });
  th += '</tr></thead>';

  const rowDefs = [
    { label: 'Prix/m² HT — S+1 (TND)',         sep: true,  fn: (y,mo) => fmtM2(unitM2Ht({ designation:'APARTMENT',  type:'S+1'  }, y, mo)) },
    { label: 'Prix/m² TTC — S+1 (TND)',         sep: false, fn: (y,mo) => fmtM2(r3(unitM2Ht({ designation:'APARTMENT', type:'S+1'  }, y, mo)*(1+VAT))) },
    { label: 'Prix/m² HT — S+2 (TND)',          sep: true,  fn: (y,mo) => fmtM2(unitM2Ht({ designation:'APARTMENT',  type:'S+2'  }, y, mo)) },
    { label: 'Prix/m² TTC — S+2 (TND)',         sep: false, fn: (y,mo) => fmtM2(r3(unitM2Ht({ designation:'APARTMENT', type:'S+2'  }, y, mo)*(1+VAT))) },
    { label: 'Prix/m² HT — S+3/S+3,5 (TND)',   sep: true,  fn: (y,mo) => fmtM2(unitM2Ht({ designation:'APARTMENT',  type:'S+3'  }, y, mo)) },
    { label: 'Prix/m² TTC — S+3/S+3,5 (TND)',  sep: false, fn: (y,mo) => fmtM2(r3(unitM2Ht({ designation:'APARTMENT', type:'S+3'  }, y, mo)*(1+VAT))) },
    { label: 'Prix/m² HT — Duplex (TND)',       sep: true,  fn: (y,mo) => fmtM2(unitM2Ht({ designation:'DUPLEX',     type:''     }, y, mo)) },
    { label: 'Prix/m² HT — Penthouse/Loft (TND)', sep: true, fn: (y,mo) => fmtM2(unitM2Ht({ designation:'PENTHOUSE', type:''    }, y, mo)) },
    { label: 'Prix Parking HT (TND)',            sep: true,  fn: (y,mo) => fmtMoney(parkingHT(y,mo)) },
    { label: 'Prix/m² HT — Cellier (TND)',       sep: true,  fn: (y,mo) => fmtM2(cellierM2(y,mo)) },
    { label: 'Prix/m² HT — Commerce (TND)',      sep: true,  fn: ()     => fmtM2(COMM_BASE) + ' (fixe)' },
  ];

  let tb = '<tbody>';
  rowDefs.forEach(({ label, sep, fn }) => {
    tb += `<tr class="${sep ? 'hist-sep' : ''}"><td>${label}</td>`;
    MONTHS.forEach(({ y, mo }) => {
      const cur = (y === nowY && mo === nowMo) ? ' cur-col' : '';
      tb += `<td class="${cur.trim()}">${fn(y, mo)}</td>`;
    });
    tb += '</tr>';
  });
  tb += '</tbody>';

  document.getElementById('histTbl').innerHTML = th + tb;

  setTimeout(() => {
    const first = document.querySelector('#histTbl .cur-col');
    if (first) first.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'center' });
  }, 80);
}

function toggleHist() {
  const panel = document.getElementById('histPanel');
  const btn   = document.getElementById('histBtn');
  const open  = panel.classList.toggle('open');
  btn.classList.toggle('open', open);
  if (open) renderHistory();
}

/* ══ Render all ═══════════════════════════════════════════════ */
function renderAll() {
  renderMeta();
  renderTarifBar();
  renderApt();
  renderComm();
  renderParking();
  renderCelliers();
}

/* ══ Mise à jour DB ═══════════════════════════════════════════ */
async function doUpdatePrices() {
  const now = new Date();
  if (selY !== now.getFullYear() || selMo !== now.getMonth()) {
    showResult('error', 'Vous ne pouvez enregistrer que le mois courant. Sélectionnez ' +
      now.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }) + '.');
    return;
  }
  const moisLabel = new Date(selY, selMo, 1).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
  const s1  = unitM2Ht({ designation:'APARTMENT', type:'S+1' }, selY, selMo);
  const s2  = unitM2Ht({ designation:'APARTMENT', type:'S+2' }, selY, selMo);
  const s3  = unitM2Ht({ designation:'APARTMENT', type:'S+3' }, selY, selMo);
  const dup = unitM2Ht({ designation:'DUPLEX',    type:''    }, selY, selMo);
  const pnt = unitM2Ht({ designation:'PENTHOUSE', type:''    }, selY, selMo);
  if (!confirm(
    'Mettre à jour les prix en base de données pour ' + moisLabel + ' ?\n\n' +
    '• S+1 : '         + fmt(s1,  3) + ' TND/m² HT\n' +
    '• S+2 : '         + fmt(s2,  3) + ' TND/m² HT\n' +
    '• S+3/S+3,5 : '   + fmt(s3,  3) + ' TND/m² HT\n' +
    '• Duplex : '      + fmt(dup, 3) + ' TND/m² HT\n' +
    '• Penthouse/Loft : ' + fmt(pnt, 3) + ' TND/m² HT\n' +
    '• Parking : '     + fmtMoney(parkingHT(selY, selMo)) + '\n' +
    '• Cellier : '     + fmtM2(cellierM2(selY, selMo)) + '/m²\n\n' +
    'Les unités vendues (V) ne seront pas modifiées.'
  )) return;

  const btn = document.getElementById('updateBtn');
  btn.disabled = true;
  btn.textContent = 'Mise à jour en cours…';
  hideResult();

  try {
    const res  = await fetch('api/flava_update_prices.php', { method: 'POST' });
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    const l = data.lignes;
    showResult('success',
      '✓ Base mise à jour pour ' + moisLabel + ' — ' +
      'S+1: ' + l.s1 + ', S+2: ' + l.s2 + ', S+3+: ' + l.s3plus +
      ', Duplex: ' + l.duplex + ', Penthouse: ' + l.penthouse +
      ', Parking: ' + l.parking + ', Celliers: ' + l.celliers + ' ligne(s). ' +
      'S+1/m² HT : ' + data.s1_m2_ht.toLocaleString('fr-FR', {minimumFractionDigits:3}) + ' TND'
    );
    btn.textContent = '✓ Base à jour (' + data.mois + ')';
  } catch (e) {
    showResult('error', 'Erreur : ' + e.message);
    btn.disabled = false;
    btn.textContent = '↑ Enregistrer prix en base';
  }
}

function showResult(type, msg) {
  const el = document.getElementById('updateResult');
  el.style.display = '';
  el.style.background = type === 'success' ? '#f0fdf4' : '#fff1f2';
  el.style.border     = '1px solid ' + (type === 'success' ? '#bbf7d0' : '#fecaca');
  el.style.color      = type === 'success' ? '#16a34a' : '#dc2626';
  el.textContent = msg;
}
function hideResult() { document.getElementById('updateResult').style.display = 'none'; }

/* ══ Freeze / Dégel des prix (admin) ═════════════════════════ */
async function doFreezeToggle() {
  const btn      = document.getElementById('freezeBtn');
  const isFrozen = PRICE_CFG.frozen;
  const action   = isFrozen ? 'Reprendre l\'augmentation des prix FLAVA ?' : 'Bloquer l\'augmentation des prix FLAVA à partir de ce mois ?';
  if (!confirm(action)) return;

  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('project', 'flava');
    const res  = await fetch('api/price_freeze.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    // Recharger la page pour refléter le nouvel état
    window.location.reload();
  } catch (e) {
    alert('Erreur : ' + e.message);
    btn.disabled = false;
  }
}

/* ══ Init ═════════════════════════════════════════════════════ */
initSel();
initTypoFilter();
updateNavBtns();
renderStats();
renderAll();
</script>

</body>
</html>
