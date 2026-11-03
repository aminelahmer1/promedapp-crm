<?php
/* Fiche client récapitulative pour promesse de location
   Données du locataire et du bien lues dans le dossier ; conditions du bail saisies ici pour
   l'impression (gardées dans ce navigateur seulement, rien n'est écrit en base). */
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/locaux_lib.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$dossierId = (int)($_GET['dossier_id'] ?? 0);
$isNew = false;
if ($dossierId) {
    $st = $pdo->prepare("SELECT d.*, p.code AS project_code FROM location_dossiers d
                         LEFT JOIN projects p ON p.id = d.project_id WHERE d.id = ? LIMIT 1");
    $st->execute([$dossierId]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) { http_response_code(404); exit('Dossier introuvable'); }
} else {
    /* Nouvelle réservation : la fiche s'ouvre directement depuis la liste (comme en vente) ;
       le dossier est créé au clic sur « Enregistrer la réservation » */
    $unitId   = (int)($_GET['unit_id'] ?? 0);
    $unitType = ($_GET['type'] ?? '') === 'accessoire' ? 'accessoire' : 'unit';
    if ($unitType === 'accessoire') {
        $st = $pdo->prepare("SELECT a.id, p.code AS project_code FROM unit_accessoires a LEFT JOIN projects p ON p.id = a.project_id WHERE a.id = ?");
    } else {
        $st = $pdo->prepare("SELECT u.id, p.code AS project_code FROM units u LEFT JOIN projects p ON p.id = u.project_id WHERE u.id = ?");
    }
    $st->execute([$unitId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); exit('Bien introuvable'); }
    if (($row['project_code'] ?? '') === '' && !empty($_GET['project'])) $row['project_code'] = (string)$_GET['project'];

    /* Déjà un dossier actif sur ce local ? → ouvrir sa fiche */
    $ids = $unitType === 'unit' ? (array_keys(local_part_rows($pdo, $unitId)) ?: [$unitId]) : [$unitId];
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $chk = $pdo->prepare("SELECT id FROM location_dossiers WHERE unit_id IN ($ph) AND unit_type = ? AND statut IN ('reserve','loue') ORDER BY id DESC LIMIT 1");
    $chk->execute(array_merge($ids, [$unitType]));
    if ($ex = $chk->fetchColumn()) { header('Location: location_fiche.php?dossier_id=' . (int)$ex); exit; }

    $isNew = true;
    $d = ['id' => 0, 'unit_id' => $unitId, 'unit_type' => $unitType, 'project_code' => $row['project_code'],
          'locataire_type' => 'physique', 'remarques' => '', 'statut' => ''];
}

/* Bien loué : le local entier (toutes ses parties) */
$parts = [];
if ($d['unit_type'] === 'accessoire') {
    $q = $pdo->prepare("SELECT code AS ref_code, floor_name, surface, NULL AS total_surface, NULL AS designation, NULL AS type,
                               price_month_ht_rent, NULL AS price_m2_ht_rent, accessory_type FROM unit_accessoires WHERE id = ?");
    $q->execute([(int)$d['unit_id']]);
    $parts = $q->fetchAll(PDO::FETCH_ASSOC);
} else {
    $ids = array_keys(local_part_rows($pdo, (int)$d['unit_id'])) ?: [(int)$d['unit_id']];
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT id, ref_code, floor_name, surface, total_surface, designation, type,
                               price_month_ht_rent, price_m2_ht_rent FROM units WHERE id IN ($ph) ORDER BY id");
    $q->execute($ids);
    $parts = $q->fetchAll(PDO::FETCH_ASSOC);
}
$lead = null;
foreach ($parts as $p) if ($p['total_surface'] !== null) { $lead = $p; break; }
$lead = $lead ?? ($parts[0] ?? []);
$group = local_group_of((string)$d['project_code'], (string)($lead['ref_code'] ?? ''));
$localNom   = $group['nom'] ?? ($lead['ref_code'] ?? '');
$surfTotale = $lead['total_surface'] ?? array_sum(array_map(fn($p) => (float)$p['surface'], $parts));

/* Parkings : une ligne par place (P23, P24…). Pour un local, on peut en ajouter au bail. */
$isLocal  = $d['unit_type'] === 'unit';
/* Parking loué seul : fiche simple (une ou plusieurs places, sans local) */
$isPk     = !$isLocal && strtoupper((string)($parts[0]['accessory_type'] ?? '')) === 'PARKING';
$pkMainId = $isPk ? (int)$d['unit_id'] : 0;
$pkAll    = ($isLocal || $isPk) ? project_location_parkings($pdo, (string)$d['project_code']) : [];
$pkLinked = (!$isNew && ($isLocal || $isPk)) ? array_values(array_filter(local_parking_dossiers($pdo, (int)$d['id']), fn($r) => $r['statut'] !== 'annule')) : [];
$pkLinkedIds = array_map(fn($r) => (int)$r['unit_id'], $pkLinked);
if ($isPk) {
    $pkLinkedIds[] = $pkMainId;
    if (!array_filter($pkAll, fn($r) => (int)$r['id'] === $pkMainId))   // place hors liste (ex. ancienne ligne générique)
        array_unshift($pkAll, ['id' => $pkMainId, 'code' => $parts[0]['ref_code'], 'status_code' => 'R', 'price_month_ht_rent' => $parts[0]['price_month_ht_rent']]);
}
$pkLabels = ['D' => 'Disponible', 'R' => 'Réservé', 'L' => 'Loué', 'V' => 'Vendu'];
$listRent = ($lead['price_month_ht_rent'] ?? null) !== null ? (float)$lead['price_month_ht_rent'] : null;

$respName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? '';
$e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$isMorale = $d['locataire_type'] === 'morale';
$ro = $isNew ? '' : ' readonly';
/* « Loué » demandé depuis la liste : après l'enregistrement, joindre contrat + état des lieux puis confirmer */
$wantLoue = $isNew && ($_GET['status'] ?? '') === 'L';
$docs = [];
if (!$isNew) {
    $q = $pdo->prepare("SELECT id, doc_type, original_name, file_path FROM location_documents WHERE dossier_id = ? ORDER BY id");
    $q->execute([(int)$d['id']]);
    $docs = $q->fetchAll(PDO::FETCH_ASSOC);
}
$docTypes = ['contrat_signe' => 'Contrat signé', 'etat_des_lieux' => 'État des lieux', 'autre' => 'Autre document'];
$hasDoc = fn($t) => (bool)array_filter($docs, fn($x) => $x['doc_type'] === $t);
$canConfirm = !$isNew && ($d['statut'] ?? '') === 'reserve' && $hasDoc('contrat_signe') && $hasDoc('etat_des_lieux');
$statutLbl = ['reserve' => 'Réservé', 'loue' => 'Loué', 'annule' => 'Annulé'][$d['statut'] ?? ''] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $isPk ? 'Fiche location parking' : 'Fiche récapitulative location' ?> – <?= $e($localNom) ?></title>
<style>
  :root{--bg:#f4f4f4;--line:#bdbdbd;--soft:#efefef;--brand:#145f7d;--muted:#5e5e5e}
  *{box-sizing:border-box} html,body{margin:0;background:var(--bg);color:#151515;font-family:Arial,Helvetica,sans-serif} body{padding:24px}
  .app{display:grid;grid-template-columns:min(460px,38%) minmax(0,1fr);gap:16px;align-items:start;max-width:1600px;margin:0 auto}
  .panel{background:#fff;border:1px solid #ddd;border-radius:18px;padding:18px;position:sticky;top:18px;max-height:calc(100vh - 36px);overflow:auto}
  .panel h1{font-size:18px;margin:0 0 4px}.panel .sub{color:var(--muted);font-size:12px;margin-bottom:12px}
  .form-section{border-top:1px solid #e5e5e5;padding-top:12px;margin-top:12px}.form-section h2{font-size:13px;margin:0 0 10px;text-transform:uppercase;letter-spacing:.4px}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  label{display:block;font-size:12px;font-weight:700;margin-bottom:4px}
  input,select,textarea,button{font:inherit} input,select,textarea{width:100%;border:1px solid #d7d7d7;border-radius:10px;padding:9px 11px;font-size:13px;background:#fff}
  textarea{min-height:60px;resize:vertical}
  .btn{border:0;border-radius:12px;padding:11px 14px;font-weight:700;cursor:pointer}.btn-primary{background:#111;color:#fff}.btn-secondary{background:#ececec;color:#111;text-decoration:none;display:inline-block}
  .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
  .note{font-size:11px;color:var(--muted);margin-top:8px}
  .doc-row{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding:7px 0;border-bottom:1px dashed #e5e5e5;font-size:12px}.doc-row a{color:#145f7d;font-size:12px}
  .btn.up{padding:6px 10px;font-size:12px;cursor:pointer;white-space:nowrap}.muted{color:#888}
  .btn-green{background:#16a34a;color:#fff}.btn-danger{background:#fdecec;color:#b42323}.btn[disabled]{opacity:.5;cursor:not-allowed}
  .st{padding:2px 8px;border-radius:8px;font-size:11px}.st-reserve{background:#fff4e2;color:#a35d00}.st-loue{background:#e8efff;color:#1d4ed8}.st-annule{background:#eee;color:#666}
  .loc-type{display:flex;gap:14px;margin-bottom:10px}.loc-type label{display:flex;align-items:center;gap:6px;font-weight:600;margin:0}.loc-type input{width:auto}
  input[readonly]{background:#f6f6f6;color:#444}
  .warn{display:none;background:#fff7e6;color:#7a4a00;border:1px solid #f1d39a;border-radius:10px;padding:8px 10px;font-size:12px;margin-top:12px}.warn ul{margin:4px 0 0 16px;padding:0}
  .pk-list{display:flex;flex-wrap:wrap;gap:6px}.pk-legend{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.pk-legend .pk{cursor:default;font-size:10px;padding:2px 7px}
  .pk{border:1px solid;border-radius:8px;padding:5px 9px;font-size:12px;font-weight:700;cursor:pointer;background:#fff}
  .pk-D{border-color:#7cc59a;color:#17723f;background:#eefaf2}.pk-R{border-color:#f0b35a;color:#a35d00;background:#fff4e2}
  .pk-L,.pk-V{border-color:#e48a8a;color:#b42323;background:#fdecec}.pk.sel{border-color:#145f7d;color:#fff;background:#145f7d}
  .pk[disabled]{cursor:not-allowed}.pk[disabled]:not(.sel){opacity:.85}
  .bad{border-color:#d33!important;background:#fff5f5!important}
  .err{display:none;background:#fdecec;color:#a11;border:1px solid #f3c0c0;border-radius:10px;padding:8px 10px;font-size:12px;margin-top:12px}
  .paper{width:210mm;min-height:297mm;margin:0 auto;background:#fff;box-shadow:0 12px 40px rgba(0,0,0,.08);padding:16mm 15mm 14mm}
  .doc-head-logos{display:grid;grid-template-columns:150px 1fr 150px;align-items:center;gap:8px;border-bottom:2px solid #222;padding-bottom:6px;margin-bottom:4px}
  .logo-left,.logo-right,.logo-mid{height:74px;display:flex;align-items:center;justify-content:center}
  .logo-left img,.logo-right img{max-height:68px;max-width:138px;object-fit:contain}
  .logo-mid{font-size:20px;font-weight:800;letter-spacing:.08em;color:var(--brand);text-transform:uppercase}
  .doc-date{text-align:right;font-size:11px;color:#555;margin:-2px 0 6px}
  .doc-head-title{font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--brand);padding:2px 0;margin-bottom:10px}
  .section-title{margin-top:10px;padding-top:4px;border-top:2px solid #454545;font-size:13px;font-weight:800;margin-bottom:8px}
  table{border-collapse:collapse;width:100%}
  .kv td,.tbl td,.tbl th,.sign-table td,.sign-table th{border:1px solid var(--line);padding:3px 6px;font-size:12px;vertical-align:middle}
  .kv td.label{width:30%;font-weight:700}.kv td.value{background:var(--soft)}
  .bloc-head-row{background:#eef4f8!important;color:var(--brand);font-weight:800;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
  .project-badge{display:inline-block;background:var(--brand);color:#fff;font-weight:800;min-width:140px;padding:1px 8px}
  .tbl th{font-weight:800;text-align:center;background:#fff}.tbl td{background:var(--soft)}.tbl td.lbl{background:#fff;font-weight:700}
  .tbl tr.total td{background:var(--brand);color:#fff;font-weight:800}
  .r{text-align:right}.c{text-align:center}
  .small-note{font-size:10px;line-height:1.45;margin:10px 0 6px}
  .sign-table th{background:#fff;font-weight:800;text-align:center}.sign-table td{background:var(--soft);height:28px}
  .sign-table .who{width:16%;font-weight:800;background:#fff}.sign-box{height:72px!important;vertical-align:top!important;padding-top:10px!important}
  .keep{break-inside:avoid;page-break-inside:avoid}
  @media (max-width:900px){.app{display:block}.panel{position:relative;max-height:none;margin-bottom:20px}.paper{width:100%}}
  @media print{@page{size:A4 portrait;margin:14mm 12mm}html,body{background:#fff}body{padding:0}.panel{display:none!important}.app{display:block;max-width:none}.paper{box-shadow:none;width:100%;min-height:0;padding:0}}
</style>
</head>
<body>
<div class="app">
  <aside class="panel">
    <h1><?= $isPk ? 'Fiche location – parking' : 'Fiche récapitulative – location' ?></h1>
    <div class="sub"><?= $e($d['project_code']) ?> · <?= $e($localNom) ?> · <?= $isNew ? 'nouvelle réservation' : 'dossier n°' . (int)$d['id'] ?></div>

    <div class="form-section" style="border-top:none;margin-top:0">
      <h2>Locataire</h2>
      <div class="loc-type">
        <label><input type="radio" name="locType" value="physique"<?= $isMorale ? '' : ' checked' ?><?= $isNew ? '' : ' disabled' ?>> Personne physique</label>
        <label><input type="radio" name="locType" value="morale"<?= $isMorale ? ' checked' : '' ?><?= $isNew ? '' : ' disabled' ?>> Personne morale</label>
      </div>
      <div class="grid-2" id="secPhysique">
        <div><label>Nom complet *</label><input type="text" class="loc-f" id="pp_nom_complet" value="<?= $e($d['pp_nom_complet'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>CIN / Passeport</label><input type="text" class="loc-f" id="pp_cin_passeport" value="<?= $e($d['pp_cin_passeport'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Téléphone *</label><input type="text" class="loc-f" id="pp_tel" value="<?= $e($d['pp_tel'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Email</label><input type="text" class="loc-f" id="pp_email" value="<?= $e($d['pp_email'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Adresse</label><input type="text" class="loc-f" id="pp_adresse" value="<?= $e($d['pp_adresse'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Ville</label><input type="text" class="loc-f" id="pp_ville" value="<?= $e($d['pp_ville'] ?? '') ?>"<?= $ro ?>></div>
      </div>
      <div class="grid-2" id="secMorale">
        <div><label>Dénomination sociale *</label><input type="text" class="loc-f" id="pm_denomination" value="<?= $e($d['pm_denomination'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Forme juridique</label><input type="text" class="loc-f" id="pm_forme_juridique" value="<?= $e($d['pm_forme_juridique'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Matricule fiscal</label><input type="text" class="loc-f" id="pm_matricule_fiscal" value="<?= $e($d['pm_matricule_fiscal'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Registre de commerce</label><input type="text" class="loc-f" id="pm_registre_commerce" value="<?= $e($d['pm_registre_commerce'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Capital social</label><input type="text" class="loc-f" id="pm_capital_social" value="<?= $e($d['pm_capital_social'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Date de création</label><input type="date" class="loc-f" id="pm_date_creation" value="<?= $e($d['pm_date_creation'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Siège social</label><input type="text" class="loc-f" id="pm_adresse_siege" value="<?= $e($d['pm_adresse_siege'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Téléphone</label><input type="text" class="loc-f" id="pm_tel" value="<?= $e($d['pm_tel'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Email</label><input type="text" class="loc-f" id="pm_email" value="<?= $e($d['pm_email'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Secteur d'activité</label><input type="text" class="loc-f" id="pm_secteur_activite" value="<?= $e($d['pm_secteur_activite'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Description activité</label><input type="text" class="loc-f" id="pm_description_activite" value="<?= $e($d['pm_description_activite'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Représentant légal *</label><input type="text" class="loc-f" id="pm_representant_nom" value="<?= $e($d['pm_representant_nom'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Fonction</label><input type="text" class="loc-f" id="pm_representant_fonction" value="<?= $e($d['pm_representant_fonction'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>CIN représentant</label><input type="text" class="loc-f" id="pm_representant_cin" value="<?= $e($d['pm_representant_cin'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Tél. représentant</label><input type="text" class="loc-f" id="pm_representant_tel" value="<?= $e($d['pm_representant_tel'] ?? '') ?>"<?= $ro ?>></div>
        <div><label>Email représentant</label><input type="text" class="loc-f" id="pm_representant_email" value="<?= $e($d['pm_representant_email'] ?? '') ?>"<?= $ro ?>></div>
      </div>
      <?php if (!$isNew): ?><div class="note">Locataire enregistré dans le dossier n°<?= (int)$d['id'] ?>.</div><?php endif; ?>
    </div>

    <div class="form-section">
      <h2>Bail</h2>
      <div class="grid-2">
        <div><label>Date d'effet *</label><input type="date" id="f_start"></div>
        <div><label>Durée</label>
          <div style="display:flex;gap:6px"><input type="number" id="f_years" min="1" step="1" value="3" style="flex:1" inputmode="numeric">
          <select id="f_unit" style="width:105px"><option value="12" selected>années</option><option value="1">mois</option></select></div></div>
        <div<?= $isPk ? ' style="display:none"' : '' ?>><label>Usage</label><input type="text" id="f_usage" value="<?= $e($isPk ? 'Parking' : ($lead['designation'] ?? '')) ?>"></div>
        <div><label>Paiement du loyer</label>
          <select id="f_period"><option value="1">Mensuel</option><option value="3" selected>Trimestriel</option><option value="6">Semestriel</option><option value="12">Annuel</option></select></div>
      </div>
    </div>

    <div class="form-section">
      <h2>Loyer</h2>
      <div class="grid-2">
        <div<?= $isPk ? ' style="display:none"' : '' ?>><label><?= $isLocal ? 'Loyer du local' : 'Loyer' ?> TTC / mois (DT) *</label><input type="number" id="f_rent" min="0" step="0.001" value="<?= $listRent !== null ? number_format($listRent, 3, '.', '') : '' ?>"></div>
        <div><label>TVA (%)</label><input type="number" id="f_vat" min="0" max="100" step="0.01" value="19"></div>
        <div><label>Hausse annuelle (%)</label><input type="number" id="f_increase" min="0" max="100" step="0.01" value="5"></div>
        <div></div>
      </div>
      <div class="note"<?= $isPk ? ' style="display:none"' : '' ?>>Loyer de la liste des prix : <strong><?= $listRent !== null ? number_format($listRent, 3, ',', ' ') . ' DT TTC / mois (TVA comprise)' : 'non renseigné' ?></strong></div>
      <?php if ($isLocal || $isPk): ?>
      <div style="margin-top:12px"><label><?= $isPk ? 'Places de parking louées' . ($isNew ? ' (cliquez pour en ajouter)' : '') : 'Parkings loués avec le local' . ($isNew ? ' (cliquez pour choisir)' : '') ?></label>
        <?php if (!$pkAll): ?><div class="note">Aucune place de parking en location pour ce projet.</div>
        <?php else: ?>
        <div class="pk-list" id="pkList">
          <?php foreach ($pkAll as $pk): $mine = in_array((int)$pk['id'], $pkLinkedIds, true); $isMain = (int)$pk['id'] === $pkMainId; $st = $pk['status_code'] ?: 'D'; ?>
          <button type="button" class="pk pk-<?= $e($st) ?><?= $mine ? ' sel' : '' ?>" data-id="<?= (int)$pk['id'] ?>" data-code="<?= $e($pk['code']) ?>" data-rent="<?= $e($pk['price_month_ht_rent']) ?>"
            title="<?= $e($pk['code'] . ' – ' . ($isMain ? 'place choisie' : ($mine ? ($isPk ? 'dans cette location' : 'avec ce local') : ($pkLabels[$st] ?? $st))) . ' – ' . number_format((float)$pk['price_month_ht_rent'], 3, ',', ' ') . ' DT TTC/mois') ?>"
            <?= (!$isNew || $isMain || $st !== 'D') ? 'disabled' : '' ?>><?= $e($pk['code']) ?></button>
          <?php endforeach; ?>
        </div>
        <div class="pk-legend"><span class="pk pk-D">Disponible</span><span class="pk pk-R">Réservé</span><span class="pk pk-L">Loué</span><span class="pk sel"><?= $isPk ? 'Dans cette location' : 'Avec ce local' ?></span></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="form-section">
      <h2><?= $isPk ? 'Signature' : 'Garanties et signature' ?></h2>
      <div class="grid-2">
        <div<?= $isPk ? ' style="display:none"' : '' ?>><label>Caution (nombre de mois)</label><input type="number" id="f_depositMonths" min="0" step="1" value="<?= $isPk ? 0 : 3 ?>"></div>
        <div<?= $isPk ? ' style="display:none"' : '' ?>><label>Avance (nombre de mois)</label><input type="number" id="f_advanceMonths" min="0" step="1" value="<?= $isPk ? 0 : 3 ?>"></div>
        <div<?= $isPk ? ' style="display:none"' : '' ?>><label>Frais d'enregistrement (DT)</label><input type="number" id="f_fees" step="0.001" value=""></div>
        <div><label>Responsable</label><input type="text" id="f_resp" value="<?= $e($respName) ?>"></div>
      </div>
      <div style="margin-top:10px"><label>Remarques</label><textarea id="f_notes"><?= $e($d['remarques'] ?? '') ?></textarea></div>
    </div>

    <?php if (!$isNew): ?>
    <div class="form-section" id="dossierBox">
      <h2>Dossier n°<?= (int)$d['id'] ?> – <span class="st st-<?= $e($d['statut']) ?>"><?= $e($statutLbl) ?></span></h2>
      <?php if (($d['statut'] ?? '') !== 'annule'): ?>
      <div class="doc-list">
        <?php foreach ($docTypes as $t => $lbl): $list = array_values(array_filter($docs, fn($x) => $x['doc_type'] === $t)); ?>
        <div class="doc-row">
          <div><strong><?= $e($lbl) ?></strong><?= $t !== 'autre' && ($d['statut'] ?? '') === 'reserve' ? ' *' : '' ?>
            <?php foreach ($list as $x): ?><br><a href="<?= $e($x['file_path']) ?>" target="_blank">📎 <?= $e($x['original_name']) ?></a><?php endforeach; ?>
            <?php if (!$list): ?><span class="muted"> – aucun</span><?php endif; ?></div>
          <label class="btn btn-secondary up">Joindre<input type="file" accept=".pdf,image/*" onchange="uploadDoc('<?= $t ?>', this)" hidden></label>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if (($d['statut'] ?? '') === 'reserve'): ?>
      <div class="actions">
        <button class="btn btn-green" onclick="confirmLoue()" <?= $canConfirm ? '' : 'disabled title="Joindre d\'abord le contrat signé et l\'état des lieux"' ?>>✅ Confirmer la location (Loué)</button>
        <button class="btn btn-danger" onclick="cancelResa()">Annuler la réservation</button>
      </div>
      <?php if (!$canConfirm): ?><div class="note">Pour passer en « Loué » : joindre le contrat signé et l'état des lieux.</div><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="warn" id="checks"></div>
    <div class="err" id="saveErr"></div>
    <div class="actions">
      <?php if ($isNew): ?><button class="btn btn-primary" id="btnSave" onclick="saveReservation()"><?= $wantLoue ? '💾 Enregistrer puis joindre les documents' : '💾 Enregistrer la réservation' ?></button><?php endif; ?>
      <button class="btn <?= $isNew ? 'btn-secondary' : 'btn-primary' ?>" onclick="printFiche()">🖨 Imprimer</button>
      <a class="btn btn-secondary" href="prix.php?mode=location&project=<?= urlencode((string)$d['project_code']) ?><?= $isPk ? '&cat=parking' : '' ?>">← Retour à la liste</a>
    </div>
    <div class="note">Les conditions du bail servent à l'impression. Elles sont gardées sur cet ordinateur seulement, pas dans le CRM.</div>
  </aside>

  <main>
    <section class="paper">
      <div class="doc-head-logos">
        <div class="logo-left"><img src="assets/images/LogoPROMED.png" alt="Promed" onerror="this.style.visibility='hidden'"></div>
        <div class="logo-mid"><?= $e($d['project_code']) ?></div>
        <div class="logo-right"><img src="assets/images/PromedGroup.png" alt="Promed Group" onerror="this.style.visibility='hidden'"></div>
      </div>
      <div class="doc-date" id="pv_date"></div>
      <div class="doc-head-title"><?= $isPk ? 'Fiche client – location de parking' : 'Fiche client récapitulative pour promesse de location' ?></div>

      <div class="section-title" style="border-top:none">1. Informations sur le locataire</div>
      <table class="kv" id="pv_loc"></table>

<?php if ($isPk): ?>
      <div class="section-title">2. Places de parking louées</div>
      <table class="kv" style="margin-bottom:8px">
        <tr><td class="label">Projet</td><td class="value"><span class="project-badge"><?= $e($d['project_code']) ?></span></td><td class="label">Nombre de places</td><td class="value" id="pv_pkCount"></td></tr>
      </table>
      <table class="tbl">
        <thead><tr><th>Place</th><th>Loyer mensuel HT</th><th>TVA</th><th>Loyer mensuel TTC</th></tr></thead>
        <tbody id="pv_pkTable"></tbody>
      </table>
<?php else: ?>
      <div class="section-title">2. Informations sur le bien loué</div>
      <table class="kv" style="margin-bottom:8px">
        <tr><td class="label">Projet</td><td class="value"><span class="project-badge"><?= $e($d['project_code']) ?></span></td><td class="label">Local</td><td class="value"><span class="project-badge"><?= $e($localNom) ?></span></td></tr>
        <tr><td class="label">Usage</td><td class="value" id="pv_usage"></td><td class="label">Surface totale (m²)</td><td class="value r"><?= number_format((float)$surfTotale, 2, ',', ' ') ?></td></tr>
        <tr><td class="label">Parkings</td><td class="value" colspan="3" id="pv_parkings"></td></tr>
      </table>
      <table class="tbl">
        <tr><th>Partie</th><th>Niveau</th><th>Type</th><th>Surface (m²)</th></tr>
        <?php foreach ($parts as $p): ?>
        <tr><td class="lbl"><?= $e($p['ref_code']) ?></td><td class="c"><?= $e($p['floor_name']) ?></td><td class="c"><?= $e($p['type']) ?></td><td class="r"><?= number_format((float)$p['surface'], 2, ',', ' ') ?></td></tr>
        <?php endforeach; ?>
      </table>
<?php endif; ?>

      <div class="keep">
      <div class="section-title">3. Conditions du bail</div>
      <table class="kv">
        <tr><td class="label">Date d'effet</td><td class="value" id="pv_start"></td><td class="label">Durée</td><td class="value" id="pv_years"></td></tr>
        <tr><td class="label">Date de fin</td><td class="value" id="pv_end"></td><td class="label">Paiement du loyer</td><td class="value" id="pv_period"></td></tr>
      </table>
      <table class="tbl" style="margin-top:8px">
        <tr><th style="width:34%"></th><th>HT</th><th>TVA (<span id="pv_vatLbl"></span>%)</th><th>TTC</th></tr>
        <tr<?= $isPk ? ' style="display:none"' : '' ?>><td class="lbl"><?= $isLocal ? 'Loyer mensuel du local' : 'Loyer mensuel' ?></td><td class="r" id="pv_lHt"></td><td class="r" id="pv_lVat"></td><td class="r" id="pv_lTtc"></td></tr>
        <tr id="pv_pkRow"><td class="lbl" id="pv_pkLbl">Loyer mensuel des parkings</td><td class="r" id="pv_kHt"></td><td class="r" id="pv_kVat"></td><td class="r" id="pv_kTtc"></td></tr>
        <tr><td class="lbl"><strong>Loyer mensuel total</strong></td><td class="r" id="pv_mHt"></td><td class="r" id="pv_mVat"></td><td class="r" id="pv_mTtc"></td></tr>
        <tr><td class="lbl">Loyer par échéance (<span id="pv_perLbl"></span>)</td><td class="r" id="pv_pHt"></td><td class="r" id="pv_pVat"></td><td class="r" id="pv_pTtc"></td></tr>
        <tr class="total"><td id="pv_yLbl">LOYER ANNUEL (1re année)</td><td class="r" id="pv_yHt"></td><td class="r" id="pv_yVat"></td><td class="r" id="pv_yTtc"></td></tr>
        <tr class="total" id="pv_bRow"><td id="pv_bLbl">TOTAL DU BAIL</td><td class="r" id="pv_bHt"></td><td class="r" id="pv_bVat"></td><td class="r" id="pv_bTtc"></td></tr>
      </table>
      </div>

      <div class="keep"<?= $isPk ? ' style="display:none"' : '' ?>>
      <div class="section-title">4. Garanties et montant à la signature</div>
      <table class="tbl">
        <tr><th style="width:34%"></th><th>Nombre de mois</th><th>Montant (DT)</th></tr>
        <tr><td class="lbl">Caution (dépôt de garantie, loyer HT)</td><td class="c" id="pv_depM"></td><td class="r" id="pv_dep"></td></tr>
        <tr><td class="lbl">Avance sur loyer (TTC)</td><td class="c" id="pv_advM"></td><td class="r" id="pv_adv"></td></tr>
        <tr><td class="lbl">Frais d'enregistrement</td><td class="c">—</td><td class="r" id="pv_fees"></td></tr>
        <tr class="total"><td>TOTAL À PAYER À LA SIGNATURE</td><td></td><td class="r" id="pv_sign"></td></tr>
      </table>
      </div>

      <div class="keep" id="pv_evoWrap">
      <div class="section-title"><?= $isPk ? 4 : 5 ?>. Évolution du loyer (hausse de <span id="pv_incLbl"></span>% par an)</div>
      <table class="tbl">
        <thead><tr><th>Année</th><th>Période</th><th>Mois</th><th>Loyer mensuel HT</th><th>Loyer mensuel TTC</th><th>Montant de la période TTC</th></tr></thead>
        <tbody id="pv_years_body"></tbody>
      </table>
      </div>

      <div class="small-note" id="pv_notes_wrap"><strong>Remarques</strong><br><span id="pv_notes"></span></div>

      <div class="keep">
      <div class="section-title" style="margin-top:18px"><span id="pv_signNum"><?= $isPk ? 5 : 6 ?></span>. Validation et signatures</div>
      <table class="sign-table">
        <tr><th class="who"></th><th>Nom et prénom</th><th>Date et Signature</th></tr>
        <tr><td class="who">Locataire</td><td class="c" id="pv_locName"></td><td class="sign-box">-- / -- / ----</td></tr>
        <tr><td class="who">Responsable</td><td class="c" id="pv_resp"></td><td class="sign-box">-- / -- / ----</td></tr>
        <tr><td class="who">Validation</td><td class="c"></td><td class="sign-box">-- / -- / ----</td></tr>
      </table>
      </div>
    </section>
  </main>
</div>

<script>
const PK_ONLY = <?= $isPk ? 'true' : 'false' ?>;
const PK_MAIN = <?= (int)$pkMainId ?>;
const LIST_RENT = PK_ONLY ? null : <?= json_encode($listRent) ?>;
const IS_NEW = <?= $isNew ? 'true' : 'false' ?>;
const WANT_LOUE = <?= $wantLoue ? 'true' : 'false' ?>;
const DOSSIER_ID = <?= (int)($d['id'] ?? 0) ?>;
function postJson(url, body) {
  return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
    .then(r => r.json().catch(() => ({ error: 'Réponse invalide du serveur' })));
}
function uploadDoc(type, input) {
  if (!input.files.length) return;
  const fd = new FormData(); fd.append('dossier_id', DOSSIER_ID); fd.append('doc_type', type); fd.append('file', input.files[0]);
  fetch('api/location_upload.php', { method: 'POST', body: fd }).then(r => r.json().catch(() => ({ error: 'Réponse invalide' })))
    .then(d => { if (d.error) { alert('Erreur : ' + d.error); return; } location.href = 'location_fiche.php?dossier_id=' + DOSSIER_ID + '&r=' + Date.now() + '#dossierBox'; })
    .catch(() => alert('Erreur réseau'));
}
function confirmLoue() {
  if (!confirm('Confirmer la location ? Le bien passera en « Loué ».')) return;
  postJson('api/location_confirm.php', { dossier_id: DOSSIER_ID }).then(d => { if (d.error) { alert('Erreur : ' + d.error); return; } location.reload(); });
}
function cancelResa() {
  if (!confirm('Annuler la réservation ? Le bien redeviendra disponible.')) return;
  postJson('api/location_cancel.php', { dossier_id: DOSSIER_ID }).then(d => { if (d.error) { alert('Erreur : ' + d.error); return; } location.reload(); });
}
const KEY = IS_NEW ? 'location_fiche_new_<?= $e($d['unit_type']) ?>_<?= (int)$d['unit_id'] ?>' : 'location_fiche_<?= (int)$d['id'] ?>';
const LOC_FIELDS = [...document.querySelectorAll('.loc-f')].map(el => el.id);
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const locType = () => document.querySelector('input[name=locType]:checked').value;
const v = id => ($(id)?.value || '').trim();

function renderLocataire() {
  const t = locType();
  $('secPhysique').style.display = t === 'physique' ? '' : 'none';
  $('secMorale').style.display   = t === 'morale'   ? '' : 'none';
  const kv = (l1, v1, l2, v2) => `<tr><td class="label">${l1}</td>` + (l2 ? `<td class="value">${esc(v1)}</td><td class="label">${l2}</td><td class="value">${esc(v2)}</td>` : `<td class="value" colspan="3">${esc(v1)}</td>`) + '</tr>';
  const head = l => `<tr><td colspan="4" class="bloc-head-row">${l}</td></tr>`;
  let h;
  if (t === 'morale') {
    h = head('Société')
      + kv('Dénomination', v('pm_denomination'), 'Forme juridique', v('pm_forme_juridique'))
      + kv('Matricule fiscal', v('pm_matricule_fiscal'), 'Registre de commerce', v('pm_registre_commerce'))
      + kv('Capital social', v('pm_capital_social'), 'Date de création', v('pm_date_creation'))
      + kv('Siège social', v('pm_adresse_siege'))
      + kv('Téléphone', v('pm_tel'), 'Email', v('pm_email'))
      + kv('Activité', (v('pm_secteur_activite') + ' ' + v('pm_description_activite')).trim())
      + head('Représentant légal')
      + kv('Nom et prénom', v('pm_representant_nom'), 'Fonction', v('pm_representant_fonction'))
      + kv('CIN / Passeport', v('pm_representant_cin'), 'Téléphone', v('pm_representant_tel'))
      + kv('Email', v('pm_representant_email'));
    set('pv_locName', [v('pm_denomination'), v('pm_representant_nom')].filter(Boolean).join(' – '));
  } else {
    h = head('Identité')
      + kv('Nom complet', v('pp_nom_complet'), 'CIN / Passeport', v('pp_cin_passeport'))
      + kv('Téléphone', v('pp_tel'), 'Email', v('pp_email'))
      + kv('Adresse', v('pp_adresse'), 'Ville', v('pp_ville'));
    set('pv_locName', v('pp_nom_complet'));
  }
  $('pv_loc').innerHTML = h;
}

function printFiche() {
  render();
  if ((window.FICHE_ERRORS || []).length) { alert('Corrigez d\'abord :\n- ' + window.FICHE_ERRORS.join('\n- ')); return; }
  window.print();
}

function saveReservation() {
  const err = $('saveErr'); err.style.display = 'none';
  const t = locType();
  const body = { unit_id: <?= (int)$d['unit_id'] ?>, unit_type: <?= json_encode($d['unit_type']) ?>,
                 project_code: <?= json_encode((string)$d['project_code']) ?>, locataire_type: t, remarques: v('f_notes'),
                 parking_ids: [...document.querySelectorAll('#pkList .pk.sel')].map(b => +b.dataset.id).filter(id => id !== PK_MAIN) };
  LOC_FIELDS.filter(f => f.startsWith(t === 'morale' ? 'pm_' : 'pp_')).forEach(f => body[f] = v(f));
  $('btnSave').disabled = true;
  fetch('api/location_reserve.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
    .then(r => r.json().catch(() => ({ error: 'Réponse invalide du serveur' })))
    .then(d => {
      if (d.error) { err.textContent = d.error; err.style.display = 'block'; $('btnSave').disabled = false; return; }
      try { localStorage.setItem('location_fiche_' + d.dossier_id, localStorage.getItem(KEY) || '{}'); localStorage.removeItem(KEY); } catch (e) {}
      window.location.replace('location_fiche.php?dossier_id=' + encodeURIComponent(d.dossier_id) + (WANT_LOUE ? '#dossierBox' : ''));
    })
    .catch(() => { err.textContent = 'Erreur réseau. Veuillez réessayer.'; err.style.display = 'block'; $('btnSave').disabled = false; });
}
const FIELDS = ['f_start','f_years','f_unit','f_usage','f_period','f_rent','f_vat','f_increase','f_depositMonths','f_advanceMonths','f_fees','f_resp','f_notes'];
const PERIODS = {1:'Mensuel',3:'Trimestriel',6:'Semestriel',12:'Annuel'};
const $ = id => document.getElementById(id);
const num = id => parseFloat(String($(id).value).replace(',', '.')) || 0;
const money = v => new Intl.NumberFormat('fr-FR',{minimumFractionDigits:3,maximumFractionDigits:3}).format(Math.round(v*1000)/1000);
const set = (id, v) => { const el = $(id); if (el) el.textContent = v; };
const fdate = d => d ? d.toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit',year:'numeric'}) : '—';
const addMonths = (d, n) => { const x = new Date(d); x.setMonth(x.getMonth() + n); return x; };
const dayBefore = d => new Date(d.getTime() - 86400000);

function render() {
  renderLocataire();
  const errors = [], warns = [];
  /* Lecture stricte des nombres : un champ vide ou mal saisi est signalé, jamais remplacé par 0 en silence */
  const field = (id, label, opt = {}) => {
    const el = $(id); el.classList.remove('bad');
    if (el.disabled) return 0;
    const raw = String(el.value).trim();
    if (el.validity && el.validity.badInput) { errors.push(label + ' : valeur incorrecte'); el.classList.add('bad'); return 0; }
    if (raw === '') { if (opt.required) { errors.push(label + ' : à renseigner'); el.classList.add('bad'); } return 0; }
    const x = Number(raw.replace(/\s/g, '').replace(',', '.'));
    if (!isFinite(x) || x < 0 || (opt.max !== undefined && x > opt.max) || (opt.int && !Number.isInteger(x)) || (opt.min !== undefined && x < opt.min)) {
      errors.push(label + ' : valeur incorrecte'); el.classList.add('bad'); return 0;
    }
    return x;
  };
  const r3 = x => Math.round((x + Number.EPSILON) * 1000) / 1000;

  const rentLocal = PK_ONLY ? 0 : r3(field('f_rent', 'Loyer du local', { required: true, min: 0.001 }));
  const vatPct = field('f_vat', 'TVA', { required: true, max: 100 }), vat = vatPct / 100;
  const incPct = field('f_increase', 'Hausse annuelle', { max: 100 }), inc = incPct / 100;
  const n = field('f_years', 'Durée', { required: true, int: true, min: 1 }) || 0;
  const unit = parseInt($('f_unit').value, 10);
  const months = n * unit, per = parseInt($('f_period').value, 10);
  const startEl = $('f_start'); startEl.classList.toggle('bad', !startEl.value);
  if (!startEl.value) errors.push("Date d'effet : à renseigner");
  const start = startEl.value ? new Date(startEl.value + 'T00:00:00') : null;
  const end = start && months ? dayBefore(addMonths(start, months)) : null;
  if (months && per > months) errors.push('Paiement ' + PERIODS[per].toLowerCase() + ' impossible sur un bail de ' + months + ' mois');
  else if (months && months % per) warns.push('La dernière échéance ne couvre que ' + (months % per) + ' mois');

  /* Parkings choisis : loyer de chaque place (liste des prix), ajouté au loyer du local */
  const places = [...document.querySelectorAll('#pkList .pk.sel')].map(b => ({ id: +b.dataset.id, code: b.dataset.code, rent: r3(Number(b.dataset.rent) || 0) }));
  const noPrice = places.filter(p => !p.rent).map(p => p.code);
  if (noPrice.length) errors.push('Parking ' + noPrice.join(', ') + ' : loyer non renseigné dans la liste des prix');
  const rentPk = r3(places.reduce((t, p) => t + p.rent, 0));
  const rent = r3(rentLocal + rentPk);
  if (LIST_RENT !== null && rentLocal && Math.abs(rentLocal - LIST_RENT) > 0.0005)
    warns.push('Loyer du local différent de la liste des prix (' + money(LIST_RENT) + ' DT TTC / mois)');

  /* Montants arrondis au millime ; TVA = HT × taux, TTC = HT + TVA (les colonnes s'additionnent toujours) */
  /* Les loyers de la liste sont des prix finaux TTC (TVA comprise) : HT = TTC / (1 + TVA), TVA = TTC − HT */
  const tri = ttc => { ttc = r3(ttc); const ht = r3(ttc / (1 + vat)); return [ht, r3(ttc - ht), ttc]; };
  const put = (ids, v) => ids.forEach((id, i) => set(id, money(v[i])));

  set('pv_date', 'Fait le ' + new Date().toLocaleDateString('fr-FR',{day:'2-digit',month:'long',year:'numeric'}));
  set('pv_usage', $('f_usage').value || '—');
  set('pv_parkings', places.length ? places.map(p => p.code + ' (' + money(p.rent) + ')').join(', ') + ' – ' + places.length + ' place' + (places.length > 1 ? 's' : '') + ', loyer TTC / mois' : '—');
  set('pv_start', fdate(start)); set('pv_end', fdate(end));
  set('pv_years', !n ? '—' : unit === 12 ? n + ' an' + (n > 1 ? 's' : '') : n + ' mois');
  set('pv_period', PERIODS[per]); set('pv_perLbl', PERIODS[per].toLowerCase());
  set('pv_vatLbl', +vatPct.toFixed(2)); set('pv_incLbl', +incPct.toFixed(2));

  const mLocal = tri(rentLocal), mPk = tri(rentPk);
  const m = tri(rentLocal + rentPk);
  put(['pv_lHt','pv_lVat','pv_lTtc'], mLocal);
  $('pv_pkRow').style.display = places.length ? '' : 'none';
  set('pv_pkLbl', 'Loyer mensuel des parkings (' + places.length + ' place' + (places.length > 1 ? 's' : '') + ')');
  put(['pv_kHt','pv_kVat','pv_kTtc'], mPk);
  if (PK_ONLY) {
    $('pv_pkRow').style.display = 'none';
    if (!places.length) errors.push('Choisissez au moins une place de parking');
    set('pv_pkCount', places.length);
    $('pv_pkTable').innerHTML = places.map(p => { const t = tri(p.rent); return `<tr><td class="lbl">${esc(p.code)}</td><td class="r">${money(t[0])}</td><td class="r">${money(t[1])}</td><td class="r">${money(t[2])}</td></tr>`; }).join('');
  }
  /* Parking seul : l'évolution n'apparaît que si le bail dépasse 12 mois */
  $('pv_evoWrap').style.display = PK_ONLY && months <= 12 ? 'none' : '';
  if (PK_ONLY) set('pv_signNum', months <= 12 ? 4 : 5);
  put(['pv_mHt','pv_mVat','pv_mTtc'], m);
  const k = Math.min(per, months || per), firstYear = Math.min(12, months || 12);
  put(['pv_pHt','pv_pVat','pv_pTtc'], tri(m[2] * k));
  set('pv_yLbl', months && months < 12 ? 'LOYER TOTAL DU BAIL (' + months + ' mois)' : 'LOYER ANNUEL (1re année)');
  put(['pv_yHt','pv_yVat','pv_yTtc'], tri(m[2] * firstYear));

  const depM = field('f_depositMonths', 'Caution', { int: true }), advM = field('f_advanceMonths', 'Avance', { int: true });
  const fees = r3(field('f_fees', "Frais d'enregistrement"));
  if (months && advM > months) warns.push("L'avance (" + advM + ' mois) dépasse la durée du bail (' + months + ' mois)');
  const dep = r3(m[0] * depM), adv = r3(m[2] * advM);
  set('pv_depM', depM); set('pv_dep', money(dep));
  set('pv_advM', advM); set('pv_adv', money(adv));
  set('pv_fees', fees ? money(fees) : '—');
  set('pv_sign', money(r3(dep + adv + fees)));

  /* Hausse de X % à chaque anniversaire du bail, sur le loyer de l'année précédente (local + parkings) */
  let rows = '', total = 0, totalHt = 0, mt = m[2];
  for (let y = 0; months && y * 12 < months; y++) {
    if (y > 0) mt = r3(mt * (1 + inc));   // hausse sur le loyer TTC de l'année précédente
    const mo = Math.min(12, months - y * 12), mh = tri(mt)[0], amount = r3(mt * mo);
    const from = start ? addMonths(start, y * 12) : null, to = start ? dayBefore(addMonths(start, y * 12 + mo)) : null;
    total = r3(total + amount); totalHt = r3(totalHt + tri(amount)[0]);
    rows += `<tr><td class="c lbl">${y + 1}</td><td class="c">${start ? fdate(from) + ' → ' + fdate(to) : '—'}</td><td class="c">${mo}</td><td class="r">${money(mh)}</td><td class="r">${money(mt)}</td><td class="r">${money(amount)}</td></tr>`;
  }
  rows += `<tr class="total"><td colspan="5">TOTAL DU BAIL TTC (${months || 0} mois)</td><td class="r">${money(total)}</td></tr>`;
  $('pv_years_body').innerHTML = rows;
  /* Total de tout le bail (toutes les années, hausse comprise) : affiché dès que le bail dépasse 12 mois */
  $('pv_bRow').style.display = months > 12 ? '' : 'none';
  set('pv_bLbl', 'TOTAL DU BAIL (' + months + ' mois' + (inc > 0 ? ', hausse de ' + (+incPct.toFixed(2)) + ' % par an comprise' : '') + ')');
  put(['pv_bHt','pv_bVat','pv_bTtc'], [totalHt, r3(total - totalHt), total]);

  const box = $('checks');
  box.style.display = errors.length || warns.length ? 'block' : 'none';
  box.innerHTML = (errors.length ? '<strong style="color:#a11">À corriger avant impression :</strong><ul>' + errors.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '')
                + (warns.length ? '<strong>À vérifier :</strong><ul>' + warns.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '');
  window.FICHE_ERRORS = errors;

  set('pv_resp', $('f_resp').value);
  set('pv_notes', $('f_notes').value);
  $('pv_notes_wrap').style.display = $('f_notes').value.trim() ? '' : 'none';

  const data = Object.fromEntries(FIELDS.map(f => [f, $(f).value]));
  if (IS_NEW) { LOC_FIELDS.forEach(f => data[f] = $(f).value); data.locType = locType(); data.pk = [...document.querySelectorAll('#pkList .pk.sel')].map(b => b.dataset.id); }
  try { localStorage.setItem(KEY, JSON.stringify(data)); } catch (e) {}
}

try {
  const saved = JSON.parse(localStorage.getItem(KEY) || 'null');
  if (saved) FIELDS.forEach(f => { if (saved[f] !== undefined && saved[f] !== '') $(f).value = saved[f]; });
  if (saved && IS_NEW) {
    LOC_FIELDS.forEach(f => { if (saved[f]) $(f).value = saved[f]; });
    if (saved.locType) document.querySelector(`input[name=locType][value=${saved.locType}]`).checked = true;
    (saved.pk || []).forEach(id => { const b = document.querySelector(`#pkList .pk[data-id="${id}"]`); if (b && !b.disabled) b.classList.add('sel'); });
  }
} catch (e) {}
/* Date d'effet : aujourd'hui par défaut (date locale) */
const LOUE_DEPUIS = <?= json_encode(($d['statut'] ?? '') === 'loue' && !empty($d['date_confirmation']) ? substr((string)$d['date_confirmation'], 0, 10) : null) ?>;
if (!$('f_start').value && LOUE_DEPUIS) $('f_start').value = LOUE_DEPUIS;   // bail en cours : date de la location
if (!$('f_start').value) { const t = new Date(); $('f_start').value = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); }
[...FIELDS, ...LOC_FIELDS].forEach(f => { $(f).addEventListener('input', render); $(f).addEventListener('change', render); });
document.querySelectorAll('input[name=locType]').forEach(r => r.addEventListener('change', render));
document.querySelectorAll('#pkList .pk:not([disabled])').forEach(b => b.addEventListener('click', () => { b.classList.toggle('sel'); render(); }));
render();
</script>
</body>
</html>
