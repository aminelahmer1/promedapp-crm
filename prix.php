<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$username = htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');

/* ══ Paramètres ══════════════════════════════════════════════ */
$mode    = ($_GET['mode'] ?? 'sale') === 'location' ? 'location' : 'sale';
$selProj = trim($_GET['project'] ?? '');
$cat     = in_array($_GET['cat'] ?? '', ['units','parking','celliers']) ? $_GET['cat'] : 'units';

if (!$selProj) { header('Location: select_project.php'); exit; }

/* ══ Unités ═════════════════════════════════════════════════ */
$stmt = $pdo->prepare("
    SELECT u.id, u.ref_code, u.block_name, u.floor_name, u.type,
           u.surface, u.total_surface, u.designation,
           u.price_m2_ht_sale, u.price_ht_sale, u.price_ttc_sale,
           u.price_m2_ht_rent, u.price_year_ht_rent, u.price_month_ht_rent,
           u.status_code
    FROM units u
    JOIN projects p ON p.id = u.project_id
    WHERE p.code = :code AND u.operation_mode = :mode
    ORDER BY u.floor_name, u.ref_code
");
$stmt->execute([':code' => $selProj, ':mode' => $mode]);
$units = $stmt->fetchAll();

/* EVOLO : bloc et étage lus dans la référence (A4-7 = Bloc A, 4ème étage),
   puis tri Bloc A → Bloc B, par étage puis par numéro */
if (strtoupper($selProj) === 'EVOLO') {
    foreach ($units as &$u) {
        if (preg_match('/^([A-Z])(\d+)-(\d+)$/i', trim((string)$u['ref_code']), $m)) {
            $n = (int)$m[2];
            $u['block_name'] = 'Bloc ' . strtoupper($m[1]);
            $u['floor_name'] = $n === 0 ? 'RDC' : ($n === 1 ? '1er étage' : $n . 'ème étage');
            $u['_sort'] = [strtoupper($m[1]), $n, (int)$m[3]];
        } else {
            $u['_sort'] = ['ZZ', 999, 999];
        }
    }
    unset($u);
    usort($units, fn($x, $y) => $x['_sort'] <=> $y['_sort'] ?: strcmp($x['ref_code'], $y['ref_code']));
}

/* ══ Accessoires ════════════════════════════════════════════ */
$parking = $celliers = [];
$stmtA = $pdo->prepare("
    SELECT a.id, a.accessory_type, a.code AS acc_code,
           a.linked_unit_text, a.block_name, a.floor_name, a.surface,
           a.price_m2_ht_sale, a.price_ht_sale, a.price_ttc_sale,
           a.price_year_ht_rent, a.price_month_ht_rent, a.status_code
    FROM unit_accessoires a
    JOIN projects p ON p.id = a.project_id
    WHERE p.code = :code AND a.operation_mode = :mode
    ORDER BY a.accessory_type, a.code
");
$stmtA->execute([':code' => $selProj, ':mode' => $mode]);
foreach ($stmtA->fetchAll() as $a) {
    if ($a['accessory_type'] === 'PARKING') $parking[] = $a;
    else                                    $celliers[] = $a;
}

/* Catégorie active */
if ($cat === 'parking'  && empty($parking))  $cat = 'units';
if ($cat === 'celliers' && empty($celliers)) $cat = 'units';
$rows  = $cat === 'parking' ? $parking : ($cat === 'celliers' ? $celliers : $units);
$total = count($rows);

/* ══ Helpers ═════════════════════════════════════════════════ */
function fmt($v, $d = 3) {
    if ($v === null || $v === '') return '<span class="muted">—</span>';
    return number_format((float)$v, $d, ',', ' ');
}
function esc($v) {
    if ($v === null || $v === '') return '<span class="muted">—</span>';
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
function countSt($arr, $s) { return count(array_filter($arr, fn($r) => ($r['status_code'] ?? '') === $s)); }
function badge($s, $id, $tbl) {
    $map = ['D'=>['d','Disponible'],'V'=>['v','Vendu'],'R'=>['r','Réservé'],'L'=>['l','Loué']];
    [$cls,$lbl] = $map[$s] ?? ['other', $s ?: '—'];
    return '<span class="badge '.$cls.'" data-id="'.(int)$id.'" data-table="'.htmlspecialchars($tbl).'" data-status="'.htmlspecialchars($s).'">'
         . htmlspecialchars($lbl).'</span>';
}

$d = countSt($rows,'D'); $r = countSt($rows,'R'); $v = countSt($rows,'V');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8"/>
  <title><?= htmlspecialchars($selProj) ?> – Prix <?= $mode==='sale'?'vente':'location' ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <link rel="stylesheet" href="assets/styles.css"/>
  <style>
    .px-wrap { padding: 24px 28px; }

    /* Hero */
    .px-eyebrow { font-size:10px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:var(--accent); margin-bottom:4px; }
    .px-hero { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; gap:16px; }
    .px-hero h2 { font-size:22px; font-weight:700; margin:0; }

    /* Stats */
    .px-stats { display:flex; gap:10px; flex-shrink:0; }
    .stat-chip { text-align:right; padding:6px 14px; border-radius:10px; border:1px solid #e5e7eb; }
    .stat-chip .val { font-size:22px; font-weight:700; line-height:1; }
    .stat-chip .lbl { font-size:11px; color:#9ca3af; margin-top:2px; }
    .stat-chip.dispo  { border-color:#bbf7d0; background:#f0fdf4; }
    .stat-chip.dispo .val  { color:#16a34a; }
    .stat-chip.reserve{ border-color:#fde68a; background:#fffbeb; }
    .stat-chip.reserve .val{ color:#ca8a04; }
    .stat-chip.vendu  { border-color:#fecaca; background:#fff1f2; }
    .stat-chip.vendu .val  { color:#dc2626; }

    /* Toolbar */
    .px-toolbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; gap:12px; flex-wrap:wrap; }
    .cat-tabs { display:flex; gap:6px; }
    .cat-tab {
      font:inherit; font-size:12px; font-weight:600; padding:5px 16px;
      border-radius:6px; border:1px solid #e5e7eb; background:#fff;
      color:#6b7280; cursor:pointer; text-decoration:none; display:inline-block; transition:all .12s;
    }
    .cat-tab:hover  { border-color:#111; color:#111; }
    .cat-tab.active { background:#111; color:#fff; border-color:#111; }
    .toolbar-right { display:flex; align-items:center; gap:10px; }
    .search-input {
      font:inherit; font-size:12px; padding:6px 10px; width:180px;
      border:1px solid #e5e7eb; border-radius:6px; background:#fff; color:#111; outline:none;
    }
    .search-input:focus { border-color:#111; }
    .px-count { font-size:12px; color:#9ca3af; white-space:nowrap; }
    .print-btn {
      border:1px solid #e5e7eb; background:#fff; font:inherit; font-size:12px;
      padding:6px 14px; border-radius:6px; cursor:pointer; color:#374151;
    }
    .print-btn:hover { border-color:#111; }

    /* Table */
    .table-wrap { overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    th {
      position:sticky; top:0; background:#fff;
      border-bottom:2px solid var(--line); padding:9px 10px 7px;
      text-align:left; font-size:11px; font-weight:700; text-transform:uppercase;
      letter-spacing:.05em; color:#6b7280; white-space:nowrap;
    }
    td { padding:9px 10px; border-bottom:1px solid #f3f4f6; white-space:nowrap; }
    tr:hover td { background:#fafafa; }

    .badge {
      display:inline-block; font-size:11px; font-weight:600; padding:2px 9px;
      border-radius:999px; cursor:pointer; user-select:none; transition:opacity .15s;
    }
    .badge:hover { opacity:.75; }
    .badge.d { background:#dcfce7; color:#16a34a; }
    .badge.v { background:#fee2e2; color:#dc2626; }
    .badge.r { background:#fef9c3; color:#ca8a04; }
    .badge.l { background:#dbeafe; color:#1d4ed8; }
    .muted { color:#d1d5db; }

    .px-empty { padding:60px 20px; text-align:center; color:#9ca3af; }
    .px-empty h3 { font-size:16px; color:#374151; margin:0 0 6px; }

    /* Popup statut */
    .status-popup {
      position:fixed; z-index:9999; background:#fff; border:1px solid #e5e7eb;
      border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,.12);
      padding:6px; display:none; flex-direction:column; gap:3px; min-width:130px;
    }
    .status-popup.open { display:flex; }
    .status-popup button {
      border:none; background:transparent; font:inherit; font-size:12px;
      padding:7px 12px; border-radius:7px; cursor:pointer; text-align:left; font-weight:600;
    }
    .status-popup button:hover  { background:#f3f4f6; }
    .status-popup button.active { background:#f3f4f6; }

    .back-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font: inherit;
      font-size: 13px;
      font-weight: 600;
      color: #374151;
      text-decoration: none;
      padding: 7px 14px;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      background: #fff;
      margin-bottom: 18px;
      transition: all .15s;
    }
    .back-btn:hover { border-color: #111; color: #111; background: #f9fafb; }

    @media print {
      .app-header, .px-toolbar, .back-btn { display:none!important; }
    }

    /* ══ MODALS ══════════════════════════════════════════════ */
    .modal-overlay {
      position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000;
      display:flex; align-items:center; justify-content:center; padding:20px;
      opacity:0; pointer-events:none; transition:opacity .2s;
    }
    .modal-overlay.open { opacity:1; pointer-events:all; }
    .modal-box {
      background:#fff; border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,.2);
      width:100%; max-width:640px; max-height:90vh; overflow-y:auto;
      transform:translateY(12px); transition:transform .2s;
    }
    .modal-overlay.open .modal-box { transform:translateY(0); }
    .modal-box.wide { max-width:820px; }
    .modal-header {
      display:flex; align-items:center; justify-content:space-between;
      padding:18px 22px 14px; border-bottom:1px solid #f0f0f0; position:sticky; top:0; background:#fff; z-index:1;
    }
    .modal-header h3 { font-size:16px; font-weight:700; margin:0; }
    .modal-close { background:none; border:none; font-size:20px; cursor:pointer; color:#9ca3af; line-height:1; padding:2px 6px; }
    .modal-close:hover { color:#111; }
    .modal-body { padding:20px 22px 24px; }
    .modal-footer { padding:14px 22px; border-top:1px solid #f0f0f0; display:flex; gap:10px; justify-content:flex-end; }

    /* Formulaire fiche */
    .form-section { margin-bottom:20px; }
    .form-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#9ca3af; margin-bottom:10px; padding-bottom:6px; border-bottom:1px solid #f0f0f0; }
    .form-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px; }
    .form-row.full { grid-template-columns:1fr; }
    .form-group { display:flex; flex-direction:column; gap:4px; }
    .form-group label { font-size:12px; font-weight:600; color:#374151; }
    .form-group input, .form-group select, .form-group textarea {
      font:inherit; font-size:13px; padding:7px 10px;
      border:1px solid #e5e7eb; border-radius:7px; background:#fff; color:#111; outline:none;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:#111; }
    .form-group textarea { resize:vertical; min-height:70px; }
    .type-seg { display:flex; gap:0; margin-bottom:18px; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; }
    .type-seg label { flex:1; display:flex; align-items:center; justify-content:center; gap:6px; font-size:13px; font-weight:600; padding:9px; cursor:pointer; transition:background .12s; }
    .type-seg label:first-child { border-right:1px solid #e5e7eb; }
    .type-seg input[type=radio] { display:none; }
    .type-seg label:has(input:checked) { background:#111; color:#fff; }

    /* Dossier */
    .dossier-section { margin-bottom:22px; }
    .dossier-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#9ca3af; margin-bottom:10px; padding-bottom:6px; border-bottom:1px solid #f0f0f0; }
    .dossier-info-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px 20px; }
    .dossier-info-item .lbl { font-size:11px; color:#9ca3af; }
    .dossier-info-item .val { font-size:13px; font-weight:600; color:#111; }

    /* Upload zones */
    .doc-zones { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .doc-zone {
      border:2px dashed #e5e7eb; border-radius:10px; padding:14px 12px;
      text-align:center; cursor:pointer; transition:border-color .15s; position:relative;
    }
    .doc-zone:hover { border-color:#111; }
    .doc-zone.has-file { border-style:solid; border-color:#bbf7d0; background:#f0fdf4; cursor:default; }
    .doc-zone.required-missing { border-color:#fecaca; background:#fff1f2; }
    .doc-zone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
    .doc-zone.has-file input[type=file] { display:none; }
    .doc-zone-icon { font-size:22px; margin-bottom:4px; }
    .doc-zone-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; }
    .doc-zone-sub { font-size:11px; color:#9ca3af; margin-top:2px; }
    .doc-file-name { font-size:12px; font-weight:600; color:#16a34a; word-break:break-all; }
    .doc-file-date { font-size:11px; color:#9ca3af; margin-top:2px; }
    .doc-remove-btn { background:none; border:none; color:#dc2626; font-size:11px; cursor:pointer; margin-top:4px; }

    /* Upload spinner */
    .uploading { opacity:.6; pointer-events:none; }

    /* Dossier locked */
    .dossier-locked { display:flex; align-items:center; gap:8px; padding:10px 14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; margin-bottom:16px; font-size:13px; color:#16a34a; font-weight:600; }

    /* Btn styles */
    .btn { font:inherit; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; cursor:pointer; border:none; transition:all .15s; }
    .btn-primary { background:#111; color:#fff; }
    .btn-primary:hover { background:#333; }
    .btn-primary:disabled { background:#d1d5db; color:#9ca3af; cursor:not-allowed; }
    .btn-danger  { background:#fff; color:#dc2626; border:1px solid #fecaca; }
    .btn-danger:hover  { background:#fff1f2; border-color:#dc2626; }
    .btn-secondary { background:#fff; color:#374151; border:1px solid #e5e7eb; }
    .btn-secondary:hover { border-color:#111; }
    .form-error { font-size:12px; color:#dc2626; margin-top:6px; display:none; }
  </style>
</head>
<body>

<header class="app-header">
  <div class="brand">
    <span class="logo"><?= htmlspecialchars($selProj) ?></span>
    <span class="subtitle">Prix – <?= $mode==='sale'?'Vente':'Location' ?></span>
  </div>
  <div class="user-bar">
    <a class="header-link" href="select_project.php">← Projets</a>
    <?php if (strtoupper($selProj) === 'EVOLO' && $mode === 'sale' && strtolower(trim((string)($_SESSION['role'] ?? ''))) !== 'agence'): ?>
    <!-- Changer de projet : AVA / FLAVA -->
    <details class="evo-switch" style="position:relative;display:inline-block">
      <summary class="header-link" style="cursor:pointer;list-style:none">Changer de projet ▾</summary>
      <div style="position:absolute;right:0;top:calc(100% + 6px);z-index:50;min-width:170px;background:#fff;border:1px solid #e6e6e6;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.08);padding:6px">
        <div style="font-size:10px;font-weight:700;letter-spacing:.06em;color:#9ca3af;text-transform:uppercase;padding:6px 10px">Basculer vers</div>
        <a href="switch_project.php?code=AVA" style="display:block;padding:8px 10px;border-radius:7px;color:#111;text-decoration:none;font-size:13px;font-weight:600">AVA</a>
        <a href="switch_project.php?code=FLAVA" style="display:block;padding:8px 10px;border-radius:7px;color:#111;text-decoration:none;font-size:13px;font-weight:600">FLAVA</a>
      </div>
    </details>
    <?php endif; ?>
    <span class="user-name">Connecté : <?= $username ?></span>
    <a class="logout-link" href="logout.php">Se déconnecter</a>
  </div>
</header>

<div class="status-popup" id="statusPopup">
  <button data-val="D">🟢 Disponible</button>
  <button data-val="R">🟡 Réservé</button>
  <button data-val="V">🔴 Vendu</button>
</div>

<?php if ($mode === 'location'): ?>
<!-- ══ MODAL FICHE LOCATAIRE ════════════════════════════════ -->
<div class="modal-overlay" id="ficheOverlay">
  <div class="modal-box">
    <div class="modal-header">
      <h3>📋 Fiche locataire</h3>
      <button class="modal-close" onclick="closeFiche()">×</button>
    </div>
    <div class="modal-body">
      <div id="ficheError" class="form-error" style="display:none;margin-bottom:12px;font-size:13px;padding:8px 12px;background:#fff1f2;border-radius:7px;border:1px solid #fecaca;"></div>

      <!-- Type locataire -->
      <div class="type-seg">
        <label><input type="radio" name="locType" value="physique" checked onchange="toggleLocType('physique')"> 👤 Personne physique</label>
        <label><input type="radio" name="locType" value="morale" onchange="toggleLocType('morale')"> 🏢 Personne morale</label>
      </div>

      <!-- PERSONNE PHYSIQUE -->
      <div id="secPhysique">
        <div class="form-section">
          <div class="form-section-title">Identité</div>
          <div class="form-row">
            <div class="form-group"><label>Nom complet *</label><input type="text" id="pp_nom_complet" placeholder="Prénom Nom"></div>
            <div class="form-group"><label>CIN / Passeport</label><input type="text" id="pp_cin_passeport"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Téléphone *</label><input type="tel" id="pp_tel"></div>
            <div class="form-group"><label>Email</label><input type="email" id="pp_email"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Adresse</label><input type="text" id="pp_adresse"></div>
            <div class="form-group"><label>Ville</label><input type="text" id="pp_ville"></div>
          </div>
        </div>
      </div>

      <!-- PERSONNE MORALE -->
      <div id="secMorale" style="display:none;">
        <div class="form-section">
          <div class="form-section-title">1. Identification de la société</div>
          <div class="form-row">
            <div class="form-group"><label>Dénomination sociale *</label><input type="text" id="pm_denomination"></div>
            <div class="form-group"><label>Forme juridique</label><input type="text" id="pm_forme_juridique" placeholder="SARL, SA, SUARL…"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Matricule fiscal</label><input type="text" id="pm_matricule_fiscal"></div>
            <div class="form-group"><label>Registre de commerce</label><input type="text" id="pm_registre_commerce"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Capital social (DT)</label><input type="number" id="pm_capital_social" min="0" step="0.001"></div>
            <div class="form-group"><label>Date de création</label><input type="date" id="pm_date_creation"></div>
          </div>
        </div>
        <div class="form-section">
          <div class="form-section-title">2. Coordonnées</div>
          <div class="form-row full">
            <div class="form-group"><label>Adresse du siège social</label><input type="text" id="pm_adresse_siege"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Téléphone</label><input type="tel" id="pm_tel"></div>
            <div class="form-group"><label>Email</label><input type="email" id="pm_email"></div>
          </div>
        </div>
        <div class="form-section">
          <div class="form-section-title">3. Représentant légal *</div>
          <div class="form-row">
            <div class="form-group"><label>Nom et prénom *</label><input type="text" id="pm_representant_nom"></div>
            <div class="form-group"><label>Fonction</label><input type="text" id="pm_representant_fonction"></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>CIN / Passeport</label><input type="text" id="pm_representant_cin"></div>
            <div class="form-group"><label>Téléphone</label><input type="tel" id="pm_representant_tel"></div>
          </div>
          <div class="form-row full">
            <div class="form-group"><label>Email</label><input type="email" id="pm_representant_email"></div>
          </div>
        </div>
        <div class="form-section">
          <div class="form-section-title">4. Activité</div>
          <div class="form-row full">
            <div class="form-group"><label>Secteur d'activité</label><input type="text" id="pm_secteur_activite"></div>
          </div>
          <div class="form-row full">
            <div class="form-group"><label>Description de l'activité</label><textarea id="pm_description_activite" rows="3"></textarea></div>
          </div>
        </div>
      </div>

      <!-- Remarques (commun) -->
      <div class="form-section">
        <div class="form-row full">
          <div class="form-group"><label>Remarques</label><textarea id="remarques" rows="2"></textarea></div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeFiche()">Annuler</button>
      <button class="btn btn-primary" onclick="submitFiche()">Enregistrer la réservation</button>
    </div>
  </div>
</div>

<!-- ══ MODAL DOSSIER LOCATION ══════════════════════════════ -->
<div class="modal-overlay" id="dossierOverlay">
  <div class="modal-box wide">
    <div class="modal-header">
      <h3 id="dossierTitle">📁 Dossier location</h3>
      <button class="modal-close" onclick="closeDossier()">×</button>
    </div>
    <div class="modal-body" id="dossierBody">
      <div style="text-align:center;padding:40px;color:#9ca3af;">Chargement…</div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="px-wrap">

  <!-- Retour -->
  <a href="select_project.php" class="back-btn">← Retour aux projets</a>

  <!-- Hero -->
  <div class="px-hero">
    <div>
      <div class="px-eyebrow"><?= $mode==='sale'?'VENTE':'LOCATION' ?></div>
      <h2><?= htmlspecialchars($selProj) ?> – Biens <?= $mode==='sale'?'à vendre':'en location' ?></h2>
    </div>
    <div class="px-stats">
      <div class="stat-chip"><div class="val"><?= $total ?></div><div class="lbl">Total</div></div>
      <div class="stat-chip dispo"><div class="val"><?= $d ?></div><div class="lbl">Disponible<?= $d>1?'s':'' ?></div></div>
      <?php if ($r): ?><div class="stat-chip reserve"><div class="val"><?= $r ?></div><div class="lbl">Réservé<?= $r>1?'s':'' ?></div></div><?php endif; ?>
      <?php if ($v): ?><div class="stat-chip vendu"><div class="val"><?= $v ?></div><div class="lbl">Vendu<?= $v>1?'s':'' ?></div></div><?php endif; ?>
    </div>
  </div>

  <!-- Toolbar -->
  <div class="px-toolbar">
    <div class="cat-tabs">
      <a class="cat-tab <?= $cat==='units'?'active':'' ?>"
         href="prix.php?mode=<?= $mode ?>&project=<?= urlencode($selProj) ?>&cat=units">
        Unités <span style="font-weight:400;opacity:.6;">(<?= count($units) ?>)</span>
      </a>
      <?php if (!empty($parking)): ?>
      <a class="cat-tab <?= $cat==='parking'?'active':'' ?>"
         href="prix.php?mode=<?= $mode ?>&project=<?= urlencode($selProj) ?>&cat=parking">
        Parking <span style="font-weight:400;opacity:.6;">(<?= count($parking) ?>)</span>
      </a>
      <?php endif; ?>
      <?php if (!empty($celliers)): ?>
      <a class="cat-tab <?= $cat==='celliers'?'active':'' ?>"
         href="prix.php?mode=<?= $mode ?>&project=<?= urlencode($selProj) ?>&cat=celliers">
        Celliers <span style="font-weight:400;opacity:.6;">(<?= count($celliers) ?>)</span>
      </a>
      <?php endif; ?>
    </div>
    <div class="toolbar-right">
      <input class="search-input" id="searchInput" type="search" placeholder="Recherche…" oninput="filterTable()"/>
      <span class="px-count" id="tableCount"><?= $total ?> bien<?= $total>1?'s':'' ?></span>
      <button class="print-btn" onclick="window.print()">Imprimer</button>
    </div>
  </div>

  <!-- Table -->
  <div class="table-wrap">
    <?php if (empty($rows)): ?>
      <div class="px-empty">
        <h3>Aucune donnée</h3>
        <p>Aucun bien trouvé pour <?= htmlspecialchars($selProj) ?> en <?= $mode==='sale'?'vente':'location' ?>.</p>
      </div>

    <?php elseif ($cat === 'units'): ?>
    <table id="mainTable">
      <thead><tr>
        <th>Réf.</th>
        <th>Bloc</th>
        <th>Étage</th>
        <th>Type</th>
        <th>Surface (m²)</th>
        <?php if ($mode==='location'): ?><th>Surface totale</th><?php endif; ?>
        <th>Désignation</th>
        <?php if ($mode==='sale'): ?>
          <th>Prix m² HT</th><th>Prix HT</th><th>Prix TTC</th>
        <?php else: ?>
          <th>Prix m²/an HT</th><th>Loyer HT / an</th><th>Loyer HT / mois</th>
        <?php endif; ?>
        <th>État</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $u): ?>
      <tr data-search="<?= htmlspecialchars(strtolower(implode(' ',[$u['ref_code'],$u['type'],$u['floor_name'],$u['block_name'],$u['designation']]))) ?>">
        <td><strong><?= esc($u['ref_code']) ?></strong></td>
        <td><?= esc($u['block_name']) ?></td>
        <td><?= esc($u['floor_name']) ?></td>
        <td><?= esc($u['type']) ?></td>
        <td><?= fmt($u['surface']) ?></td>
        <?php if ($mode==='location'): ?><td><?= fmt($u['total_surface']) ?></td><?php endif; ?>
        <td><?= esc($u['designation']) ?></td>
        <?php if ($mode==='sale'): ?>
          <td><?= fmt($u['price_m2_ht_sale']) ?></td>
          <td><?= fmt($u['price_ht_sale']) ?></td>
          <td><?= fmt($u['price_ttc_sale']) ?></td>
        <?php else: ?>
          <td><?= fmt($u['price_m2_ht_rent']) ?></td>
          <td><?= fmt($u['price_year_ht_rent']) ?></td>
          <td><?= fmt($u['price_month_ht_rent']) ?></td>
        <?php endif; ?>
        <td><?= badge($u['status_code'], $u['id'], 'unit') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php elseif ($cat === 'parking'): ?>
    <table id="mainTable">
      <thead><tr>
        <th>Parking</th><th>Bloc</th><th>Niveau</th><th>Unité liée</th>
        <?php if ($mode==='sale'): ?>
          <th>Prix TTC</th>
        <?php else: ?>
          <th>Loyer HT / an</th><th>Loyer HT / mois</th>
        <?php endif; ?>
        <th>État</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $a): ?>
      <tr data-search="<?= htmlspecialchars(strtolower(implode(' ',[$a['acc_code'],$a['linked_unit_text'],$a['floor_name']]))) ?>">
        <td><strong><?= esc($a['acc_code']) ?></strong></td>
        <td><?= esc($a['block_name']) ?></td>
        <td><?= esc($a['floor_name']) ?></td>
        <td><?= esc($a['linked_unit_text']) ?></td>
        <?php if ($mode==='sale'): ?>
          <td><?= fmt($a['price_ttc_sale']) ?></td>
        <?php else: ?>
          <td><?= fmt($a['price_year_ht_rent']) ?></td>
          <td><?= fmt($a['price_month_ht_rent']) ?></td>
        <?php endif; ?>
        <td><?= badge($a['status_code'], $a['id'], 'accessoire') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php elseif ($cat === 'celliers'): ?>
    <table id="mainTable">
      <thead><tr>
        <th>Cellier</th><th>Bloc</th><th>Étage</th><th>Unité liée</th>
        <th>Surface (m²)</th><th>Prix m² HT</th><th>Prix HT</th><th>Prix TTC</th>
        <th>État</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $a): ?>
      <tr data-search="<?= htmlspecialchars(strtolower(implode(' ',[$a['acc_code'],$a['linked_unit_text'],$a['floor_name']]))) ?>">
        <td><strong><?= esc($a['acc_code']) ?></strong></td>
        <td><?= esc($a['block_name']) ?></td>
        <td><?= esc($a['floor_name']) ?></td>
        <td><?= esc($a['linked_unit_text']) ?></td>
        <td><?= fmt($a['surface']) ?></td>
        <td><?= fmt($a['price_m2_ht_sale']) ?></td>
        <td><?= fmt($a['price_ht_sale']) ?></td>
        <td><?= fmt($a['price_ttc_sale']) ?></td>
        <td><?= badge($a['status_code'], $a['id'], 'accessoire') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>

<script>
const MODE = <?= json_encode($mode) ?>;
const PROJECT_CODE = <?= json_encode($selProj) ?>;
const LABELS = { D:'Disponible', R:'Réservé', V:'Vendu', L:'Loué' };
const CLS    = { D:'d', R:'r', V:'v', L:'l' };

/* ── Filtre texte ── */
function filterTable(){
  const q = document.getElementById('searchInput').value.toLowerCase();
  const rows = Array.from(document.querySelectorAll('#mainTable tbody tr'));
  let v = 0;
  rows.forEach(tr => {
    const show = !q || tr.dataset.search.includes(q);
    tr.style.display = show ? '' : 'none';
    if (show) v++;
  });
  const c = document.getElementById('tableCount');
  if (c) c.textContent = v + ' bien' + (v > 1 ? 's' : '');
}

/* ── Popup statut ── */
(function(){
  const popup = document.getElementById('statusPopup');
  if (!popup) return;
  let cur = null;

  document.addEventListener('click', e => {
    const badge = e.target.closest('.badge[data-id]');
    if (badge) {
      e.stopPropagation();
      const st = badge.dataset.status;

      /* En mode location, R/L → ouvrir dossier au lieu du popup */
      if (MODE === 'location' && (st === 'R' || st === 'L')) {
        openDossier(badge);
        return;
      }

      cur = badge;
      const rect = badge.getBoundingClientRect();
      popup.style.top  = (rect.bottom + window.scrollY + 4) + 'px';
      popup.style.left = (rect.left   + window.scrollX)     + 'px';
      popup.querySelectorAll('button').forEach(b =>
        b.classList.toggle('active', b.dataset.val === st)
      );
      popup.classList.add('open');
      return;
    }
    if (!popup.contains(e.target)) popup.classList.remove('open');
  });

  popup.querySelectorAll('button').forEach(btn => {
    btn.addEventListener('click', function(){
      if (!cur) return;
      const ns = this.dataset.val;
      popup.classList.remove('open');

      /* En mode location, clic sur Réservé → ouvrir fiche locataire */
      if (MODE === 'location' && ns === 'R') {
        pendingBadge = cur;
        openFiche();
        return;
      }

      /* EVOLO : Réservé / Vendu → ouvrir la fiche ; le statut est enregistré par la fiche */
      if (String(PROJECT_CODE).toUpperCase() === 'EVOLO' && MODE === 'sale' && cur.dataset.table !== 'accessoire' && (ns === 'R' || ns === 'V')) {
        const ref = cur.closest('tr')?.querySelector('td strong')?.textContent.trim();
        if (ref) { window.location.href = 'fiche_client.php?project=EVOLO&unit_code=' + encodeURIComponent(ref) + '&status=' + ns; return; }
      }

      /* EVOLO : Disponible sur un appartement réservé / vendu → annuler la promesse en cours */
      if (String(PROJECT_CODE).toUpperCase() === 'EVOLO' && MODE === 'sale' && cur.dataset.table !== 'accessoire' && ns === 'D' && (cur.dataset.status === 'R' || cur.dataset.status === 'V')) {
        const ref = cur.closest('tr')?.querySelector('td strong')?.textContent.trim();
        const badge = cur;
        if (!ref || !confirm('Annuler la réservation de ' + ref + ' ?')) return;
        fetch('api/evolo_cancel.php', {
          method : 'POST',
          headers: { 'Content-Type': 'application/json' },
          body   : JSON.stringify({ code: ref })
        }).then(r => r.json().catch(() => ({ error: 'réponse invalide' }))).then(d => {
          if (d.error) { alert('Erreur : ' + d.error); return; }
          badge.textContent    = LABELS.D;
          badge.className      = 'badge ' + CLS.D;
          badge.dataset.status = 'D';
        }).catch(err => alert('Erreur : ' + err.message));
        return;
      }

      applyStatus(cur, ns);
    });
  });
})();

/* Retour depuis la fiche (bouton Précédent) : recharger pour afficher le statut enregistré */
window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });

function applyStatus(badge, ns) {
  badge.textContent    = LABELS[ns];
  badge.className      = 'badge ' + CLS[ns];
  badge.dataset.status = ns;

  fetch('update_unit_status.php', {
    method : 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body   : 'id='     + encodeURIComponent(badge.dataset.id)
           + '&status='+ encodeURIComponent(ns)
           + '&table=' + encodeURIComponent(badge.dataset.table)
  }).then(r => r.json()).then(d => {
    if (d.error) { alert('Erreur : ' + d.error); return; }
  });
}

/* ════════════════════════════════════════════════════════════
   FICHE LOCATAIRE
   ════════════════════════════════════════════════════════════ */
let pendingBadge = null;

function openFiche() {
  document.getElementById('ficheOverlay')?.classList.add('open');
  document.getElementById('ficheError').style.display = 'none';
  document.querySelector('input[name=locType][value=physique]').checked = true;
  toggleLocType('physique');
  // Reset all inputs (exclure les radios pour ne pas effacer leur valeur)
  document.querySelectorAll('#ficheOverlay input:not([type=radio]):not([type=checkbox]), #ficheOverlay textarea, #ficheOverlay select').forEach(el => el.value = '');
}

function closeFiche() {
  document.getElementById('ficheOverlay')?.classList.remove('open');
  pendingBadge = null;
}

function toggleLocType(t) {
  document.getElementById('secPhysique').style.display = t === 'physique' ? '' : 'none';
  document.getElementById('secMorale').style.display   = t === 'morale'   ? '' : 'none';
}

function ficheVal(id) { return (document.getElementById(id)?.value || '').trim(); }

function submitFiche() {
  const errEl = document.getElementById('ficheError');
  errEl.style.display = 'none';

  const locType = document.querySelector('input[name=locType]:checked').value;

  if (!pendingBadge) return;

  const body = {
    unit_id      : parseInt(pendingBadge.dataset.id),
    unit_type    : pendingBadge.dataset.table === 'accessoire' ? 'accessoire' : 'unit',
    project_code : PROJECT_CODE,
    locataire_type: locType,
    remarques    : ficheVal('remarques'),
  };

  if (locType === 'physique') {
    Object.assign(body, {
      pp_nom_complet  : ficheVal('pp_nom_complet'),
      pp_cin_passeport: ficheVal('pp_cin_passeport'),
      pp_tel          : ficheVal('pp_tel'),
      pp_email        : ficheVal('pp_email'),
      pp_adresse      : ficheVal('pp_adresse'),
      pp_ville        : ficheVal('pp_ville'),
    });
  } else {
    Object.assign(body, {
      pm_denomination        : ficheVal('pm_denomination'),
      pm_forme_juridique     : ficheVal('pm_forme_juridique'),
      pm_capital_social      : ficheVal('pm_capital_social'),
      pm_matricule_fiscal    : ficheVal('pm_matricule_fiscal'),
      pm_registre_commerce   : ficheVal('pm_registre_commerce'),
      pm_date_creation       : ficheVal('pm_date_creation'),
      pm_adresse_siege       : ficheVal('pm_adresse_siege'),
      pm_tel                 : ficheVal('pm_tel'),
      pm_email               : ficheVal('pm_email'),
      pm_representant_nom    : ficheVal('pm_representant_nom'),
      pm_representant_fonction: ficheVal('pm_representant_fonction'),
      pm_representant_cin    : ficheVal('pm_representant_cin'),
      pm_representant_tel    : ficheVal('pm_representant_tel'),
      pm_representant_email  : ficheVal('pm_representant_email'),
      pm_secteur_activite    : ficheVal('pm_secteur_activite'),
      pm_description_activite: ficheVal('pm_description_activite'),
    });
  }

  fetch('api/location_reserve.php', {
    method : 'POST',
    headers: { 'Content-Type': 'application/json' },
    body   : JSON.stringify(body)
  })
  .then(r => r.json())
  .then(d => {
    if (d.error) {
      errEl.textContent    = d.error;
      errEl.style.display  = 'block';
      return;
    }
    /* Succès : mettre à jour le badge */
    pendingBadge.textContent    = 'Réservé';
    pendingBadge.className      = 'badge r';
    pendingBadge.dataset.status = 'R';
    pendingBadge.dataset.dossierId = d.dossier_id;
    closeFiche();
  })
  .catch(() => {
    errEl.textContent   = 'Erreur réseau. Veuillez réessayer.';
    errEl.style.display = 'block';
  });
}

/* ════════════════════════════════════════════════════════════
   DOSSIER LOCATION
   ════════════════════════════════════════════════════════════ */
let currentDossier = null;

function openDossier(badge) {
  const overlay = document.getElementById('dossierOverlay');
  if (!overlay) return;
  document.getElementById('dossierBody').innerHTML = '<div style="text-align:center;padding:40px;color:#9ca3af;">Chargement…</div>';
  overlay.classList.add('open');

  const unit_id   = badge.dataset.id;
  const unit_type = badge.dataset.table === 'accessoire' ? 'accessoire' : 'unit';

  fetch(`api/location_dossier_get.php?unit_id=${encodeURIComponent(unit_id)}&unit_type=${encodeURIComponent(unit_type)}`)
  .then(r => r.json())
  .then(data => {
    if (data.error) {
      document.getElementById('dossierBody').innerHTML = `<p style="color:#dc2626;text-align:center;padding:40px;">${data.error}</p>`;
      return;
    }
    currentDossier = { ...data, badge };
    renderDossier(data);
  })
  .catch(() => {
    document.getElementById('dossierBody').innerHTML = '<p style="color:#dc2626;text-align:center;padding:40px;">Erreur réseau</p>';
  });
}

function closeDossier() {
  document.getElementById('dossierOverlay')?.classList.remove('open');
  currentDossier = null;
}

function renderDossier(data) {
  const { dossier, unit, documents, can_confirm } = data;
  const locked   = dossier.statut === 'loue';
  const annule   = dossier.statut === 'annule';
  const d = dossier;

  document.getElementById('dossierTitle').textContent = '📁 Dossier – ' + (unit?.ref_code || '');

  /* ── Locataire ── */
  let locHtml = '';
  if (d.locataire_type === 'physique') {
    locHtml = infoGrid([
      ['Nom complet',    d.pp_nom_complet],
      ['CIN / Passeport',d.pp_cin_passeport],
      ['Téléphone',      d.pp_tel],
      ['Email',          d.pp_email],
      ['Adresse',        d.pp_adresse],
      ['Ville',          d.pp_ville],
    ]);
  } else {
    locHtml = infoGrid([
      ['Dénomination',     d.pm_denomination],
      ['Forme juridique',  d.pm_forme_juridique],
      ['Matricule fiscal', d.pm_matricule_fiscal],
      ['Registre commerce',d.pm_registre_commerce],
      ['Téléphone',        d.pm_tel],
      ['Email',            d.pm_email],
      ['Représentant',     d.pm_representant_nom],
      ['Fonction',         d.pm_representant_fonction],
      ['Secteur activité', d.pm_secteur_activite],
    ]);
  }

  /* ── Documents ── */
  const docTypes = [
    { key:'contrat_signe',  label:'Contrat signé',    icon:'📄', required:true },
    { key:'etat_des_lieux', label:'État des lieux',   icon:'🏠', required:true },
    { key:'image_edl',      label:'Photos état des lieux', icon:'📷', required:false },
    { key:'autre',          label:'Autre document',   icon:'📎', required:false },
  ];

  const docsByType = {};
  documents.forEach(doc => {
    if (!docsByType[doc.doc_type]) docsByType[doc.doc_type] = [];
    docsByType[doc.doc_type].push(doc);
  });

  let docsHtml = '<div class="doc-zones">';
  docTypes.forEach(dt => {
    const files = docsByType[dt.key] || [];
    const hasFile = files.length > 0;
    const missing = dt.required && !hasFile;
    const zoneClass = hasFile ? 'has-file' : (missing ? 'required-missing' : '');

    if (hasFile) {
      const f = files[files.length - 1]; // dernier upload
      docsHtml += `
        <div class="doc-zone ${zoneClass}">
          <div class="doc-zone-icon">${dt.icon}</div>
          <div class="doc-zone-label">${dt.label}${dt.required?' *':''}</div>
          <div class="doc-file-name">✓ ${escHtml(f.original_name)}</div>
          <div class="doc-file-date">${f.uploaded_at ? f.uploaded_at.substring(0,10) : ''}</div>
          ${!locked ? `<button class="doc-remove-btn" onclick="removeDoc(${f.id},'${dt.key}')">× Supprimer</button>` : ''}
        </div>`;
    } else if (!locked) {
      docsHtml += `
        <div class="doc-zone ${zoneClass}" id="zone_${dt.key}">
          <div class="doc-zone-icon">${dt.icon}</div>
          <div class="doc-zone-label">${dt.label}${dt.required?' *':''}</div>
          <div class="doc-zone-sub" id="zoneSub_${dt.key}">PDF ou image, max 10 Mo</div>
          <input type="file" accept=".pdf,.jpg,.jpeg,.png" onchange="uploadDoc(this,'${dt.key}')">
        </div>`;
    } else {
      docsHtml += `
        <div class="doc-zone" style="opacity:.5;">
          <div class="doc-zone-icon">${dt.icon}</div>
          <div class="doc-zone-label">${dt.label}</div>
          <div class="doc-zone-sub">Non fourni</div>
        </div>`;
    }
  });
  docsHtml += '</div>';

  /* ── Actions ── */
  let actionsHtml = '';
  if (!locked && !annule) {
    actionsHtml = `
      <div class="modal-footer" style="border-top:none;padding-top:0;">
        <button class="btn btn-danger"    onclick="cancelReservation(${d.id})">Annuler la réservation</button>
        <button class="btn btn-primary" id="btnConfirm" ${can_confirm ? '' : 'disabled'} onclick="confirmLocation(${d.id})">
          ✓ Confirmer la location
        </button>
      </div>
      ${!can_confirm ? '<p style="font-size:11px;color:#9ca3af;text-align:right;margin-top:6px;">Le contrat signé et l\'état des lieux sont requis pour confirmer.</p>' : ''}`;
  }

  document.getElementById('dossierBody').innerHTML = `
    ${locked ? `<div class="dossier-locked">🔒 Location confirmée le ${d.date_confirmation ? d.date_confirmation.substring(0,10) : ''}</div>` : ''}
    <div class="dossier-section">
      <div class="dossier-section-title">Bien</div>
      ${infoGrid([
        ['Référence', unit?.ref_code],
        ['Étage', unit?.floor_name],
        ['Surface', unit?.surface ? unit.surface + ' m²' : null],
        ['Loyer HT / mois', unit?.price_month_ht_rent ? formatMoney(unit.price_month_ht_rent) + ' DT' : null],
      ])}
    </div>
    <div class="dossier-section">
      <div class="dossier-section-title">Locataire (${d.locataire_type === 'morale' ? 'Personne morale' : 'Personne physique'})</div>
      ${locHtml}
    </div>
    <div class="dossier-section">
      <div class="dossier-section-title">Documents${locked ? '' : ' <span style="font-weight:400;color:#9ca3af;">— * obligatoires pour confirmation</span>'}</div>
      ${docsHtml}
    </div>
    ${actionsHtml}
  `;
}

/* ── Helpers render ── */
function infoGrid(pairs) {
  const items = pairs.filter(([,v]) => v != null && v !== '');
  if (!items.length) return '<p style="font-size:13px;color:#9ca3af;">—</p>';
  return '<div class="dossier-info-grid">' +
    items.map(([l,v]) => `<div class="dossier-info-item"><div class="lbl">${l}</div><div class="val">${escHtml(String(v))}</div></div>`).join('') +
    '</div>';
}
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function formatMoney(v) { return new Intl.NumberFormat('fr-TN',{maximumFractionDigits:3}).format(v); }

/* ── Upload document ── */
function uploadDoc(input, docType) {
  if (!input.files[0] || !currentDossier) return;
  const zone = document.getElementById('zone_' + docType);
  const sub  = document.getElementById('zoneSub_' + docType);
  if (zone) zone.classList.add('uploading');
  if (sub)  sub.textContent = 'Envoi en cours…';

  const fd = new FormData();
  fd.append('dossier_id', currentDossier.dossier.id);
  fd.append('doc_type',   docType);
  fd.append('file',       input.files[0]);

  fetch('api/location_upload.php', { method:'POST', body:fd })
  .then(r => r.json())
  .then(d => {
    if (d.error) { alert('Erreur upload : ' + d.error); if (zone) zone.classList.remove('uploading'); return; }
    // Mettre à jour les documents locaux et re-render
    if (!currentDossier.documents) currentDossier.documents = [];
    currentDossier.documents.push({ id:d.doc_id, doc_type:docType, original_name:d.original_name, file_path:d.file_path, uploaded_at: new Date().toISOString() });
    currentDossier.can_confirm = d.can_confirm;
    renderDossier(currentDossier);
  })
  .catch(() => { alert('Erreur réseau lors de l\'upload'); if (zone) zone.classList.remove('uploading'); });
}

/* ── Confirmer location ── */
function confirmLocation(dossierId) {
  if (!confirm('Confirmer définitivement la location ? Cette action est irréversible.')) return;
  fetch('api/location_confirm.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ dossier_id: dossierId })
  })
  .then(r => r.json())
  .then(d => {
    if (d.error) { alert('Erreur : ' + d.error); return; }
    if (currentDossier?.badge) {
      currentDossier.badge.textContent    = 'Loué';
      currentDossier.badge.className      = 'badge l';
      currentDossier.badge.dataset.status = 'L';
    }
    closeDossier();
  });
}

/* ── Annuler réservation ── */
function cancelReservation(dossierId) {
  if (!confirm('Annuler la réservation ? Le bien repassera en Disponible.')) return;
  fetch('api/location_cancel.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ dossier_id: dossierId })
  })
  .then(r => r.json())
  .then(d => {
    if (d.error) { alert('Erreur : ' + d.error); return; }
    if (currentDossier?.badge) {
      currentDossier.badge.textContent    = 'Disponible';
      currentDossier.badge.className      = 'badge d';
      currentDossier.badge.dataset.status = 'D';
      delete currentDossier.badge.dataset.dossierId;
    }
    closeDossier();
  });
}

/* ── Supprimer document (optionnel) ── */
function removeDoc(docId, docType) {
  // Simple : on peut juste recharger le dossier après suppression
  // Pour l'instant, on informe l'utilisateur qu'il peut re-uploader
  alert('Pour remplacer ce document, fermez le dossier et uploadez un nouveau fichier après réouverture.');
}
</script>
</body>
</html>
