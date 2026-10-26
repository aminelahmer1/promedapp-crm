<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$COMPANY = require __DIR__ . '/company_config.php';

$promise_id = (int)($_GET['promise_id'] ?? 0);
$mode       = trim((string)($_GET['mode'] ?? ''));

// ── Mode direct : données passées par GET (depuis fiche_client.php) ──
if ($mode === 'direct') {
    $promise = [
        'id'             => 0,
        'unit_code'      => trim((string)($_GET['unit_code']      ?? '')),
        'parking_codes'  => trim((string)($_GET['parking']        ?? '')),
        'cellier_codes'  => trim((string)($_GET['cellier']        ?? '')),
        'sale_price'     => (float)($_GET['price_ht']             ?? 0),
        'vat_rate'       => (float)($_GET['vat_rate']             ?? 19),
        'advance_amount' => (float)($_GET['advance']              ?? 0),
        'client_nom'     => trim((string)($_GET['client_nom']     ?? '')),
        'client_tel'     => trim((string)($_GET['client_tel']     ?? '')),
        'client_email'   => trim((string)($_GET['client_email']   ?? '')),
        'client_cin'     => trim((string)($_GET['client_cin']     ?? '')),
        'client_address' => trim((string)($_GET['client_address'] ?? '')),
        'project_code'   => strtoupper(trim((string)($_GET['project'] ?? ''))),
        'project_nom'    => trim((string)($_GET['project_nom']    ?? $_GET['project'] ?? '')),
    ];
    // TTC peut être passé directement
    $ttc_direct = (float)($_GET['price_ttc'] ?? 0);
    // Co-acquéreurs passés en JSON
    $coClientsRaw = trim((string)($_GET['co_clients'] ?? ''));
    $coClients = [];
    if ($coClientsRaw !== '') {
        $decoded = json_decode($coClientsRaw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $co) {
                if (!empty($co['nom'])) {
                    $coClients[] = [
                        'nom'     => trim((string)($co['nom']     ?? '')),
                        'cin'     => trim((string)($co['cin']     ?? '')),
                        'tel'     => trim((string)($co['tel']     ?? '')),
                        'email'   => trim((string)($co['email']   ?? '')),
                        'address' => trim((string)($co['address'] ?? '')),
                    ];
                }
            }
        }
    }

// ── Mode promise_id : chargement depuis la DB ──
} elseif ($promise_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.*,
                   c.nom AS client_nom, c.tel AS client_tel, c.email AS client_email,
                   c.cin AS client_cin, c.address AS client_address, c.nationality,
                   pj.code AS project_code, pj.nom AS project_nom
            FROM promises p
            LEFT JOIN clients c ON c.id = p.client_id
            LEFT JOIN projects pj ON pj.id = p.project_id
            WHERE p.id = ?
        ");
        $stmt->execute([$promise_id]);
        $promise = $stmt->fetch();
        if (!$promise) { header('Location: promises.php'); exit; }
    } catch (Throwable $e) {
        die('Erreur chargement promesse: ' . htmlspecialchars($e->getMessage()));
    }
    $ttc_direct = 0;
    // Extraire les détails financiers et co-acquéreurs depuis details_json
    $coClients = [];
    $promise_details = [];
    if (!empty($promise['details_json'])) {
        $dj = json_decode($promise['details_json'], true) ?: [];
        foreach ($dj['co_clients'] ?? [] as $co) {
            if (!empty($co['nom'])) {
                $coClients[] = [
                    'nom'     => trim((string)($co['nom']     ?? '')),
                    'cin'     => trim((string)($co['cin']     ?? $co['id'] ?? '')),
                    'tel'     => trim((string)($co['tel']     ?? '')),
                    'email'   => trim((string)($co['email']   ?? '')),
                    'address' => trim((string)($co['address'] ?? '')),
                ];
            }
        }
        // Restaurer taux TVA depuis details_json si disponible
        if (!empty($dj['vatRate'])) {
            $promise['vat_rate'] = (float)$dj['vatRate'];
        }
        $promise_details = [
            'discountApartment' => (float)($dj['discountApartment'] ?? 0),
            'discountParking'   => (float)($dj['discountParking']   ?? 0),
            'discountCellier'   => (float)($dj['discountCellier']   ?? 0),
            'extraWorks'        => (float)($dj['extraWorks']        ?? 0),
            'selectedParkings'  => $dj['selectedParkings'] ?? [],
            'selectedCelliers'  => $dj['selectedCelliers'] ?? [],
            'tranches'          => $dj['tranches']          ?? [],
        ];
        // Surcharger les parking/cellier codes si disponibles dans details_json
        if (!empty($promise_details['selectedParkings'])) {
            $promise['parking_codes'] = implode(', ', $promise_details['selectedParkings']);
        }
        if (!empty($promise_details['selectedCelliers'])) {
            $promise['cellier_codes'] = implode(', ', $promise_details['selectedCelliers']);
        }
    }
} else {
    header('Location: promises.php'); exit;
}

// Generate option reference number
try {
    $seq = (int)$pdo->query("SELECT COUNT(*)+1 FROM option_ventes WHERE YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())")->fetchColumn();
} catch (Throwable $e) {
    $seq = 1;
}
$optionRef = 'OPT-' . date('Ym') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

// Price calculations
// In promise_id mode: sale_price is stored as TTC (fiche_client.php saves c2.totalTtc)
// In direct mode: price_ht and price_ttc are passed explicitly via GET
$vat = (float)($promise['vat_rate'] ?? 19);
if ($mode === 'direct' && $ttc_direct > 0) {
    $ttc = $ttc_direct;
    $ht  = ($vat > 0) ? round($ttc / (1 + $vat / 100), 3) : $ttc;
} else {
    $ttc = (float)$promise['sale_price'];
    $ht  = ($vat > 0) ? round($ttc / (1 + $vat / 100), 3) : $ttc;
}

// Number to words — French, Dinars Tunisiens
$FR_UNITS = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf',
             'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize',
             'dix-sept', 'dix-huit', 'dix-neuf'];
$FR_TENS  = ['', '', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante',
             'soixante', 'quatre-vingt', 'quatre-vingt'];

function fr_below100(int $n, array $units, array $tens): string {
    if ($n < 20) return $units[$n];
    $d = intdiv($n, 10);
    $u = $n % 10;
    if ($d === 7 || $d === 9) {
        $u2 = $u + 10;
        return $tens[$d] . '-' . $units[$u2];
    }
    $t = $tens[$d];
    if ($u === 0) return $t . ($d === 8 ? 's' : '');
    if ($u === 1 && $d !== 8) return $t . '-et-un';
    return $t . '-' . $units[$u];
}

function fr_below1000(int $n, array $units, array $tens): string {
    if ($n < 100) return fr_below100($n, $units, $tens);
    $h = intdiv($n, 100);
    $r = $n % 100;
    $cent = ($h === 1 ? 'cent' : $units[$h] . ' cent') . ($r === 0 && $h > 1 ? 's' : '');
    return $r > 0 ? $cent . ' ' . fr_below100($r, $units, $tens) : $cent;
}

function numberToWordsFr(float $amount): string {
    global $FR_UNITS, $FR_TENS;
    $n = (int)round($amount);
    if ($n === 0) return 'Zéro dinar';
    $parts    = [];
    $millions = intdiv($n, 1000000);
    $thousands= intdiv($n % 1000000, 1000);
    $remainder= $n % 1000;
    if ($millions > 0) {
        $parts[] = ($millions === 1 ? 'un' : fr_below1000($millions, $FR_UNITS, $FR_TENS)) . ' million' . ($millions > 1 ? 's' : '');
    }
    if ($thousands > 0) {
        $parts[] = $thousands === 1 ? 'mille' : fr_below1000($thousands, $FR_UNITS, $FR_TENS) . ' mille';
    }
    if ($remainder > 0) {
        $parts[] = fr_below1000($remainder, $FR_UNITS, $FR_TENS);
    }
    return ucfirst(implode(' ', $parts)) . ' dinar' . ($n > 1 ? 's' : '');
}

$priceWordsHt  = numberToWordsFr($ht);
$priceWordsTtc = numberToWordsFr($ttc);

// Today's date formatted in French
$months = ['', 'janvier','février','mars','avril','mai','juin',
           'juillet','août','septembre','octobre','novembre','décembre'];
$todayFr = date('j') . ' ' . $months[(int)date('n')] . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Option de Vente – <?=htmlspecialchars($promise['unit_code']??'')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--bg:#f4f4f4;--paper:#fff;--line:#bdbdbd;--text:#151515;--muted:#5e5e5e;--soft:#efefef;--brand:#145f7d;--brand2:#a56d2d}
  *{box-sizing:border-box} html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif} body{padding:24px}
  .app{display:grid;grid-template-columns:300px 1fr;gap:24px;align-items:start;max-width:1600px;margin:0 auto}
  .panel{background:#fff;border:1px solid #ddd;border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.05);position:sticky;top:18px;max-height:calc(100vh - 36px);overflow:auto}
  .panel h1{font-size:17px;margin:0 0 2px}
  .panel .sub{color:var(--muted);font-size:13px;margin-bottom:14px}
  .form-section{border-top:1px solid #e5e5e5;padding-top:14px;margin-top:14px}
  .form-section h2{font-size:12px;margin:0 0 10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700}
  label{display:block;font-size:12px;font-weight:700;color:#202020;margin-bottom:3px}
  input,select,textarea,button{font:inherit}
  input[type=text],input[type=number],input[type=date],input[type=time],select,textarea{
    width:100%;border:1px solid #d7d7d7;border-radius:10px;padding:9px 12px;background:#fff;font-size:13px;margin-bottom:8px}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--brand)}
  .btn{border:0;border-radius:12px;padding:11px 14px;font-weight:700;cursor:pointer;font-size:13px;width:100%;display:block;text-align:center;text-decoration:none;margin-top:4px}
  .btn-brand{background:var(--brand);color:#fff}
  .btn-secondary{background:var(--soft);color:var(--text)}
  .btn-print{background:#19733e;color:#fff}
  .nav-link{display:block;color:var(--brand);font-size:13px;text-decoration:none;padding:5px 0;border-bottom:1px solid #f0f0f0}
  .nav-link:hover{text-decoration:underline}
  .nav-link:last-child{border:none}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:8px}

  /* ── DOCUMENT ── */
  .doc-wrap{background:#fff;border:1px solid #ddd;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.05);padding:0}
  .doc-actions{padding:14px 18px;border-bottom:1px solid #eee;display:flex;gap:10px;align-items:center;justify-content:space-between}
  .doc-actions span{font-size:13px;color:var(--muted)}
  .doc-actions button{border:0;border-radius:10px;padding:9px 18px;font-weight:700;cursor:pointer;font-size:13px}
  .doc-actions .btn-p{background:var(--brand);color:#fff}
  .doc-actions .btn-dl{background:var(--brand2);color:#fff}
  .document{padding:30px 40px;font-family:'Times New Roman',Times,serif;font-size:11pt;line-height:1.45;color:#000;max-width:800px;margin:0 auto}
  .doc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-bottom:10px;border-bottom:3px double #000;gap:16px}
  .doc-header .header-logo img{max-height:60px;max-width:180px;object-fit:contain}
  .doc-header .header-info{text-align:right;font-size:8.5pt;color:#444;line-height:1.5}
  .doc-title{text-align:center;margin:14px 0 12px}
  .doc-title h2{font-size:13pt;font-weight:bold;text-transform:uppercase;letter-spacing:1px;border:2px solid #000;display:inline-block;padding:6px 18px;margin:0}
  .doc-title .ref{font-size:10pt;margin-top:5px;color:#333}
  .doc-title .date-line{font-size:10pt;margin-top:2px}
  .section-title{font-size:10pt;font-weight:bold;text-transform:uppercase;background:#f0f0f0;border-left:4px solid #145f7d;padding:5px 10px;margin:14px 0 8px}
  .parties-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px}
  .party-box{border:1px solid #ccc;border-radius:4px;padding:10px}
  .party-box .party-label{font-size:8pt;font-weight:bold;text-transform:uppercase;color:#555;margin-bottom:5px;border-bottom:1px solid #eee;padding-bottom:3px}
  .party-box .party-name{font-size:10.5pt;font-weight:bold;margin-bottom:4px}
  .party-row{font-size:9.5pt;margin-bottom:2px}
  .party-row .lbl{color:#555;font-size:8.5pt}
  table.details-table{width:100%;border-collapse:collapse;margin-bottom:10px}
  table.details-table th{background:#145f7d;color:#fff;padding:5px 10px;font-size:9pt;text-align:left;font-weight:bold}
  table.details-table td{padding:5px 10px;font-size:9.5pt;border-bottom:1px solid #eee;vertical-align:top}
  table.details-table tr:nth-child(even) td{background:#f9f9f9}
  table.details-table td:first-child{font-weight:bold;width:45%;color:#333}
  .price-box{text-align:center;padding:6px 0;margin:6px 0}
  .price-box .price-num{font-size:13pt;font-weight:bold;color:#000;letter-spacing:.5px}
  .price-box .price-words{font-size:9.5pt;font-style:italic;color:#222;margin-top:3px}
  .price-box .price-note{font-size:8.5pt;color:#444;margin-top:3px}
  .validity-box{padding:6px 0;margin:6px 0}
  .validity-box .vd{font-size:11pt;font-weight:bold;color:#000}
  .reservation-box{padding:6px 0;margin:6px 0}
  .checkbox-row{margin-bottom:4px;display:flex;align-items:flex-start;gap:8px}
  .checkbox-sq{width:12px;height:12px;border:1.5px solid #000;flex-shrink:0;margin-top:2px}
  .article-text{font-size:9.5pt;color:#222;margin-bottom:4px}
  .article-text ul{margin:3px 0 3px 16px}
  .signatures-grid{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-top:18px;padding-top:14px;border-top:1px solid #ccc}
  .sig-block{text-align:center}
  .sig-block .sig-label{font-size:8pt;font-weight:bold;text-transform:uppercase;color:#555;margin-bottom:3px}
  .sig-block .sig-name{font-size:10pt;font-weight:bold;margin-bottom:2px}
  .sig-block .sig-pos{font-size:8.5pt;color:#555;margin-bottom:16px}
  .sig-block .sig-line{border-bottom:1px solid #aaa;margin-top:50px}
  .sig-block .sig-note{font-size:8pt;color:#666;margin-top:5px;font-style:italic}
  .doc-footer{text-align:center;margin-top:16px;padding-top:10px;border-top:2px solid #000;font-size:8pt;color:#555}
  .page-break{page-break-before:always}

  /* Print — 2 pages exactes */
  @media print {
    body{background:#fff;padding:0;margin:0}
    .app{display:block}
    .panel,.doc-actions,.no-print{display:none!important}
    .doc-wrap{border:none;box-shadow:none;border-radius:0}
    @page{size:A4;margin:7mm 12mm}

    .document{padding:0;font-size:9.5pt;line-height:1.3;max-width:none;font-family:'Times New Roman',Times,serif}

    /* En-tête */
    .doc-header{margin-bottom:6px;padding-bottom:6px;border-bottom:2px solid #000}
    .doc-header .header-logo img{max-height:44px}
    .doc-header .header-info{font-size:7.5pt;line-height:1.4}

    /* Titre */
    .doc-title{margin:5px 0 7px}
    .doc-title h2{font-size:11pt;padding:4px 14px;letter-spacing:.5px}
    .doc-title .ref,.doc-title .date-line{font-size:8.5pt;margin-top:1px}

    /* Sections */
    .section-title{font-size:8.5pt;padding:3px 8px;margin:7px 0 5px}

    /* Parties */
    .parties-grid{gap:8px;margin-bottom:6px}
    .party-box{padding:7px}
    .party-box .party-label{font-size:7.5pt;margin-bottom:3px;padding-bottom:2px}
    .party-box .party-name{font-size:9.5pt;margin-bottom:3px}
    .party-row{font-size:8.5pt;margin-bottom:1px}
    .party-row .lbl{font-size:7.5pt}

    /* Tables */
    table.details-table{margin-bottom:5px}
    table.details-table th{padding:3px 7px;font-size:8pt}
    table.details-table td{padding:3px 7px;font-size:8.5pt}

    /* Prix */
    .price-box{padding:4px 0;margin:4px 0}
    .price-box .price-num{font-size:11pt}
    .price-box .price-words{font-size:8pt;margin-top:1px}

    /* Validité / Réservation */
    .validity-box{padding:4px 0;margin:4px 0}
    .validity-box .vd{font-size:10pt}
    .reservation-box{padding:4px 0;margin:4px 0}

    /* Texte */
    .article-text{font-size:8.5pt;margin-bottom:2px;line-height:1.3}
    ul.article-text,ul{margin:2px 0 2px 16px}

    /* Signatures */
    .signatures-grid{gap:16px;margin-top:10px;padding-top:8px}
    .sig-block .sig-line{margin-top:30px}
    .sig-block .sig-label{font-size:7.5pt}
    .sig-block .sig-name{font-size:9.5pt}
    .sig-block .sig-note{font-size:7.5pt;margin-top:3px}

    /* Pied */
    .doc-footer{margin-top:8px;padding-top:6px;font-size:7pt}

    /* Coupure de page */
    .page-break-art5{page-break-before:always;margin-top:0}
  }
  @media(max-width:1100px){.app{grid-template-columns:1fr}.panel{position:relative;top:auto;max-height:none}}
</style>
</head>
<body>
<div class="app">

  <!-- ══ PANEL GAUCHE ══ -->
  <aside class="panel no-print">
    <h1>Option de Vente</h1>
    <p class="sub"><?=htmlspecialchars($promise['unit_code']??'')?> — <?=htmlspecialchars($promise['client_nom']??'')?></p>

    <div class="form-section">
      <h2>Navigation</h2>
      <?php if($mode === 'direct'): ?>
      <a class="nav-link" href="javascript:history.back()">← Retour à la fiche client</a>
      <?php else: ?>
      <a class="nav-link" href="promise_view.php?id=<?=$promise_id?>">← Retour à la promesse</a>
      <?php endif; ?>
      <a class="nav-link" href="promises.php">Promesses de vente</a>
    </div>

    <div class="form-section">
      <h2>Référence document</h2>
      <label>N° Option</label>
      <input type="text" id="f_option_ref" value="<?=htmlspecialchars($optionRef)?>">
      <label>Date du document</label>
      <input type="date" id="f_doc_date" value="<?=date('Y-m-d')?>">
    </div>

    <div class="form-section">
      <h2>Représentant société</h2>
      <label>Nom du représentant</label>
      <input type="text" id="f_representative" value="<?=htmlspecialchars($COMPANY['representative'])?>">
      <label>Fonction</label>
      <input type="text" id="f_rep_position" value="<?=htmlspecialchars($COMPANY['rep_position'])?>">
    </div>

    <div class="form-section">
      <h2>Bien immobilier</h2>
      <label>Type de bien</label>
      <select id="f_property_type">
        <?php $assetType = htmlspecialchars($_GET['asset_type'] ?? 'Appartement'); ?>
        <option value="Appartement" <?=$assetType==='Appartement'?'selected':''?>>Appartement</option>
        <option value="Villa">Villa</option>
        <option value="Duplex">Duplex</option>
        <option value="Studio">Studio</option>
        <option value="Local commercial">Local commercial</option>
        <option value="Bureau">Bureau</option>
      </select>
      <label>Statut du programme</label>
      <select id="f_project_status">
        <option value="Programme en cours de construction">En cours de construction</option>
        <option value="Programme achevé / déjà construit">Achevé / déjà construit</option>
        <option value="Programme en cours d'étude ou de développement">En cours d'étude / développement</option>
      </select>
      <label>Superficie (m²)</label>
      <?php $areaVal = (float)($_GET['area'] ?? 0); ?>
      <input type="number" id="f_area" step="0.01" placeholder="0.00" value="<?=$areaVal>0?$areaVal:''?>">
    </div>

    <div class="form-section">
      <h2>Validité de l'option</h2>
      <label>Date d'expiration</label>
      <input type="date" id="f_expiry_date" value="<?=date('Y-m-d', strtotime('+15 days'))?>">
    </div>

    <div class="form-section">
      <h2>Modalités de réservation</h2>
      <label>Type de réservation</label>
      <select id="f_reservation_mode" onchange="toggleReservationFields()">
        <option value="1">Paiement effectué</option>
        <option value="2">Paiement différé</option>
        <option value="3">Sans paiement</option>
      </select>
      <div id="field_amount">
        <div style="display:grid;grid-template-columns:1fr auto;gap:6px;align-items:end">
          <div><label>Montant de réservation</label>
          <input type="number" id="f_reservation_amount" step="0.001" placeholder="0.000" value="<?=number_format((float)($promise['advance_amount']??0),3,'.','')?>"></div>
          <div><label>Devise</label>
          <select id="f_currency" style="height:38px">
            <option value="TND">TND</option>
            <option value="EUR">EUR</option>
            <option value="USD">USD</option>
          </select></div>
        </div>
        <label>Montant en lettres</label>
        <input type="text" id="f_reservation_words" placeholder="Ex : Dix mille dinars">
      </div>
      <div id="field_payment_method">
        <label>Méthode de paiement</label>
        <select id="f_payment_method">
          <option value="virement bancaire">Virement bancaire</option>
          <option value="chèque">Chèque</option>
          <option value="espèces">Espèces</option>
          <option value="traite">Traite</option>
        </select>
        <label>Référence</label>
        <input type="text" id="f_payment_ref" placeholder="N° chèque / virement…">
      </div>
      <div id="field_deadline" style="display:none">
        <label>Date limite de paiement</label>
        <input type="date" id="f_deadline">
      </div>
    </div>

    <div class="form-section">
      <h2>Coordonnées bancaires</h2>
      <label>Banque</label>
      <input type="text" id="f_bank_name" value="<?=htmlspecialchars($COMPANY['bank_name']??'')?>">
      <label>Titulaire du compte</label>
      <input type="text" id="f_bank_holder" value="<?=htmlspecialchars($COMPANY['bank_holder']??'')?>">
      <label>RIB</label>
      <input type="text" id="f_bank_rib" value="<?=htmlspecialchars($COMPANY['bank_rib']??'')?>">
      <label>IBAN</label>
      <input type="text" id="f_bank_iban" value="<?=htmlspecialchars($COMPANY['bank_iban']??'')?>">
      <label>Code BIC (SWIFT)</label>
      <input type="text" id="f_bank_bic" value="<?=htmlspecialchars($COMPANY['bank_bic']??'')?>">
      <label>Référence virement</label>
      <input type="text" id="f_bank_ref" placeholder="Ex : OPT-202605-0001 – Nom Client">
    </div>

    <div class="form-section">
      <h2>Signature</h2>
      <label>Ville de signature</label>
      <input type="text" id="f_sig_city" value="<?=htmlspecialchars($COMPANY['signature_city'])?>">
      <label>Nom du signataire</label>
      <input type="text" id="f_signatory" value="<?=htmlspecialchars($COMPANY['signatory'] ?: $COMPANY['representative'])?>">
      <label>Fonction du signataire</label>
      <input type="text" id="f_sig_position" value="<?=htmlspecialchars($COMPANY['sig_position'])?>">
    </div>

    <div class="form-section">
      <div id="flashBox" style="display:none;padding:10px 12px;border-radius:10px;font-size:13px;margin-bottom:10px"></div>
      <button class="btn btn-brand" onclick="saveOption()" id="btnSave">💾 Enregistrer l'option</button>
      <button class="btn btn-print" onclick="window.print()" style="margin-top:6px">🖨️ Imprimer / PDF</button>
      <button class="btn btn-secondary" onclick="updateDoc()" style="margin-top:6px">🔄 Mettre à jour</button>
      <a class="btn btn-secondary" href="promise_view.php?id=<?=$promise_id?>" style="margin-top:6px">← Retour</a>
    </div>
  </aside>

  <!-- ══ DOCUMENT ══ -->
  <div class="doc-wrap">
    <div class="doc-actions no-print">
      <span>Document généré — modifiez les champs et cliquez <strong>Mettre à jour</strong></span>
      <div style="display:flex;gap:8px">
        <button class="btn-p" onclick="updateDoc()">🔄 Actualiser</button>
        <button class="btn-dl" onclick="window.print()">🖨️ Imprimer</button>
      </div>
    </div>

    <!-- THE DOCUMENT -->
    <div class="document" id="theDocument">

      <!-- En-tête société -->
      <div class="doc-header">
        <div class="header-logo">
          <img src="assets/images/PromedGroup.png" alt="PROMED GROUP">
        </div>
        <div class="header-info">
          <strong><?=htmlspecialchars($COMPANY['name'])?></strong> — <?=htmlspecialchars($COMPANY['legal_form'])?><br>
          Tél : <?=htmlspecialchars($COMPANY['phone'])?><br>
          Email : <?=htmlspecialchars($COMPANY['email'])?>
        </div>
      </div>

      <!-- Titre -->
      <div class="doc-title">
        <h2>Option de Vente Immobilière</h2>
        <div class="ref">Option N° <strong id="d_option_ref"><?=htmlspecialchars($optionRef)?></strong></div>
        <div class="date-line">Date : <span id="d_doc_date"><?=$todayFr?></span></div>
      </div>

      <!-- Article 1 - Parties -->
      <div class="section-title">1 — Parties</div>
      <div class="parties-grid">
        <div class="party-box">
          <div class="party-label">Le Promoteur</div>
          <div class="party-name"><?=htmlspecialchars($COMPANY['name'])?></div>
          <div class="party-row">représentée par :</div>
          <div class="party-row"><strong id="d_representative"><?=htmlspecialchars($COMPANY['representative'] ?: '[Représentant]')?></strong></div>
          <div class="party-row">Fonction : <span id="d_rep_position"><?=htmlspecialchars($COMPANY['rep_position'])?></span></div>
        </div>
        <div class="party-box">
          <div class="party-label">Le<?=count($coClients)>0?'s Bénéficiaires':' Bénéficiaire'?></div>
          <div class="party-name"><?=htmlspecialchars($promise['client_nom']??'—')?></div>
          <div class="party-row"><span class="lbl">CIN / Passeport : </span><?=htmlspecialchars($promise['client_cin']??'—')?></div>
          <div class="party-row"><span class="lbl">Téléphone : </span><?=htmlspecialchars($promise['client_tel']??'—')?></div>
          <div class="party-row"><span class="lbl">Email : </span><?=htmlspecialchars($promise['client_email']??'—')?></div>
          <div class="party-row"><span class="lbl">Adresse : </span><?=htmlspecialchars($promise['client_address']??'—')?></div>
          <?php foreach ($coClients as $i => $co): ?>
          <div style="margin-top:10px;padding-top:10px;border-top:1px dashed #b8d4f0">
            <div style="font-size:11px;font-weight:700;color:#145f7d;margin-bottom:4px">Co-acquéreur <?=$i+1?></div>
            <div class="party-name"><?=htmlspecialchars($co['nom'])?></div>
            <?php if($co['cin']): ?><div class="party-row"><span class="lbl">CIN / Passeport : </span><?=htmlspecialchars($co['cin'])?></div><?php endif; ?>
            <?php if($co['tel']): ?><div class="party-row"><span class="lbl">Téléphone : </span><?=htmlspecialchars($co['tel'])?></div><?php endif; ?>
            <?php if($co['email']): ?><div class="party-row"><span class="lbl">Email : </span><?=htmlspecialchars($co['email'])?></div><?php endif; ?>
            <?php if($co['address']): ?><div class="party-row"><span class="lbl">Adresse : </span><?=htmlspecialchars($co['address'])?></div><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Article 2 - Bien -->
      <div class="section-title">2 — Bien objet de l'option</div>
      <table class="details-table">
        <thead><tr><th>Élément</th><th>Détails</th></tr></thead>
        <tbody>
          <tr><td>Programme</td><td><?=htmlspecialchars($promise['project_nom'] ?? $promise['project_code'] ?? '—')?></td></tr>
          <tr><td>Statut du programme</td><td id="d_project_status">Programme en cours de construction</td></tr>
          <tr><td>Type de bien</td><td id="d_property_type">Appartement</td></tr>
          <tr><td>Référence</td><td><?=htmlspecialchars($promise['unit_code']??'—')?></td></tr>
          <tr><td>Superficie</td><td><span id="d_area">—</span></td></tr>
          <tr><td>Parking</td><td><?=htmlspecialchars($promise['parking_codes']??'—')?></td></tr>
          <tr><td>Cellier</td><td><?=htmlspecialchars($promise['cellier_codes']??'—')?></td></tr>
        </tbody>
      </table>

      <!-- Article 3 - Prix -->
      <div class="section-title">3 — Prix de vente</div>
      <p class="article-text">Le prix convenu pour le bien objet de la présente option est fixé à :</p>
      <div class="price-box">
        <div class="price-num"><span id="d_price_ht_num"><?=number_format($ht, 3, ',', ' ')?></span> TND HTVA</div>
        <div class="price-words">(<span id="d_price_ht_words"><?=$priceWordsHt?></span> hors taxes)</div>
      </div>
      <p class="article-text" style="text-align:center;font-weight:600;margin:8px 0">Soit :</p>
      <div class="price-box">
        <div class="price-num"><span id="d_price_ttc_num"><?=number_format($ttc, 3, ',', ' ')?></span> TND TTC</div>
        <div class="price-words">(<span id="d_price_ttc_words"><?=$priceWordsTtc?></span> toutes taxes comprises)</div>
      </div>
      <p class="article-text">Ce prix s'entend hors frais d'enregistrement, frais d'abonnement aux différents concessionnaires et réseaux, frais de morcellement et d'extraction du titre foncier individuel, frais de syndic, frais d'acte et honoraires, ainsi que tous frais, droits ou taxes exigibles conformément à la réglementation en vigueur.</p>

      <!-- Article 4 - Validité -->
      <div class="section-title">4 — Validité de l'option</div>
      <p class="article-text">La présente option est valable jusqu'au :</p>
      <div class="validity-box">
        <div class="vd">Le <span id="d_expiry_date"><?=date('d/m/Y', strtotime('+15 days'))?></span></div>
      </div>
      <p class="article-text">
        Pendant cette période, le bien est réservé au profit exclusif du Bénéficiaire.<br>
        À défaut de régularisation dans les délais convenus, la présente option deviendra automatiquement caduque.
      </p>

      <!-- Article 5 - Réservation (page 2) -->
      <div class="section-title page-break-art5">5 — Modalités de réservation</div>
      <p class="article-text">Type de réservation : <strong id="d_reservation_mode_label">Paiement effectué</strong></p>

      <div id="d_case1" class="reservation-box">
        <div class="checkbox-row"><div>
          <p class="article-text" style="margin:0">Un montant de réservation de :<br>
          <strong><span id="d_reservation_amount"><?=number_format((float)($promise['advance_amount']??0),3,',',' ')?></span> <span id="d_currency1">TND</span></strong><br>
          (<em><span id="d_reservation_words1">—</span></em>)<br>
          a été versé ce jour par : <strong><span id="d_payment_method">virement bancaire</span></strong><br>
          Référence : <span id="d_payment_ref">—</span></p>
          <p class="article-text" style="margin:6px 0 0;font-size:10pt">
          Cette somme sera imputée sur le prix de vente définitif en cas de concrétisation de l'opération.
          </p>
        </div></div>
      </div>

      <div id="d_case2" class="reservation-box" style="display:none">
        <div class="checkbox-row"><div>
          <p class="article-text" style="margin:0">Le Bénéficiaire s'engage à verser le montant de réservation de :<br>
          <strong><span id="d_reservation_amount2">—</span> <span id="d_currency2">TND</span></strong><br>
          (<em><span id="d_reservation_words2">—</span></em>)<br>
          au plus tard le : <strong><span id="d_deadline">—</span></strong></p>
          <p class="article-text" style="margin:6px 0 0;font-size:10pt">
          À défaut de paiement dans le délai convenu, la présente option pourra être annulée de plein droit par le Promoteur.
          </p>
        </div></div>
      </div>

      <div id="d_case3" class="reservation-box" style="display:none">
        <div class="checkbox-row"><div>
          <p class="article-text" style="margin:0">La présente option est accordée exceptionnellement sans versement d'un montant de réservation.<br>
          Le Promoteur conserve néanmoins le droit de mettre fin à l'option à l'expiration du délai prévu à l'Article 4.
          </p>
        </div></div>
      </div>

      <!-- Article 6 - Coordonnées bancaires -->
      <div class="section-title">6 — Coordonnées bancaires du promoteur</div>
      <p class="article-text">Pour tout règlement par virement bancaire :</p>
      <table class="details-table">
        <tbody>
          <tr><td>Banque</td><td id="d_bank_name"><?=htmlspecialchars($COMPANY['bank_name']??'')?></td></tr>
          <tr><td>Titulaire du compte</td><td id="d_bank_holder"><?=htmlspecialchars($COMPANY['bank_holder']??'')?></td></tr>
          <tr><td>RIB</td><td id="d_bank_rib" style="font-family:monospace;letter-spacing:.05em"><?=htmlspecialchars($COMPANY['bank_rib']??'')?></td></tr>
          <tr><td>IBAN</td><td id="d_bank_iban" style="font-family:monospace;letter-spacing:.05em"><?=htmlspecialchars($COMPANY['bank_iban']??'')?></td></tr>
          <tr><td>Code BIC (SWIFT)</td><td id="d_bank_bic" style="font-family:monospace;letter-spacing:.05em"><?=htmlspecialchars($COMPANY['bank_bic']??'')?></td></tr>
        </tbody>
      </table>
      <p class="article-text">Le(s) Bénéficiaire(s) devra(ont) mentionner la référence suivante lors du virement : <strong id="d_bank_ref">—</strong></p>

      <!-- Article 7 - Déclarations -->
      <div class="section-title">7 — Déclarations</div>
      <p class="article-text">Le Bénéficiaire reconnaît :</p>
      <ul class="article-text">
        <li>avoir reçu les informations disponibles relatives au bien ;</li>
        <li>être informé de l'état d'avancement du programme ;</li>
        <li>accepter les conditions de la présente option ;</li>
        <li>reconnaître le caractère temporaire et limité de la réservation accordée.</li>
      </ul>
      <p class="article-text">La présente option constitue une base contractuelle pouvant :</p>
      <ul class="article-text">
        <li>être annulée ;</li>
        <li>être transformée en promesse de vente ;</li>
        <li>ou être régularisée directement par contrat de vente définitif.</li>
      </ul>

      <!-- Article 8 - Signatures -->
      <div class="section-title">8 — Signatures</div>
      <p class="article-text">
        Fait à : <strong><span id="d_sig_city"><?=htmlspecialchars($COMPANY['signature_city'])?></span></strong><br>
        Le : <span id="d_sig_date"><?=$todayFr?></span>
      </p>
      <div class="signatures-grid">
        <div class="sig-block">
          <div class="sig-label">Pour <?=htmlspecialchars($COMPANY['name'])?></div>
          <div class="sig-name" id="d_signatory"><?=htmlspecialchars($COMPANY['signatory'] ?: '[Signataire]')?></div>
          <div class="sig-pos" id="d_sig_position"><?=htmlspecialchars($COMPANY['sig_position'])?></div>
          <div class="sig-line"></div>
          <div class="sig-note">Signature &amp; Cachet</div>
        </div>
        <div class="sig-block">
          <div class="sig-label">Le Bénéficiaire</div>
          <div class="sig-name"><?=htmlspecialchars($promise['client_nom']??'—')?></div>
          <div class="sig-pos" style="color:transparent">—</div>
          <div class="sig-line"></div>
          <div class="sig-note">Signature précédée de la mention :<br>« Lu et approuvé »</div>
        </div>
        <?php foreach ($coClients as $i => $co): ?>
        <div class="sig-block">
          <div class="sig-label">Co-acquéreur <?=$i+1?></div>
          <div class="sig-name"><?=htmlspecialchars($co['nom'])?></div>
          <div class="sig-pos" style="color:transparent">—</div>
          <div class="sig-line"></div>
          <div class="sig-note">Signature précédée de la mention :<br>« Lu et approuvé »</div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Pied de page -->
      <div class="doc-footer">
        <?=htmlspecialchars($COMPANY['name'])?> — <?=htmlspecialchars($COMPANY['legal_form'])?><br>
        Tél : <?=htmlspecialchars($COMPANY['phone'])?> — Email : <?=htmlspecialchars($COMPANY['email'])?>
      </div>

    </div><!-- /document -->
  </div><!-- /doc-wrap -->

</div><!-- /app -->

<script>
// French months for date formatting
const FR_MONTHS = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
function fmtDateFr(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr);
  if (isNaN(d)) return dateStr;
  return d.getDate() + ' ' + FR_MONTHS[d.getMonth()+1] + ' ' + d.getFullYear();
}
function fmtDateDMY(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr);
  if (isNaN(d)) return dateStr;
  return String(d.getDate()).padStart(2,'0') + '/' + String(d.getMonth()+1).padStart(2,'0') + '/' + d.getFullYear();
}

function toggleReservationFields() {
  const mode = document.getElementById('f_reservation_mode').value;
  document.getElementById('field_amount').style.display = mode !== '3' ? '' : 'none';
  document.getElementById('field_payment_method').style.display = mode === '1' ? '' : 'none';
  document.getElementById('field_deadline').style.display = mode === '2' ? '' : 'none';
  updateDoc();
}

function set(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}

function updateDoc() {
  // Reference & date
  set('d_option_ref', document.getElementById('f_option_ref').value || '—');
  set('d_doc_date', fmtDateFr(document.getElementById('f_doc_date').value));

  // Parties
  set('d_representative', document.getElementById('f_representative').value || '[Représentant]');
  set('d_rep_position',   document.getElementById('f_rep_position').value);

  // Property
  set('d_property_type',  document.getElementById('f_property_type').value);
  set('d_project_status', document.getElementById('f_project_status').value);
  const area = parseFloat(document.getElementById('f_area').value);
  set('d_area', area > 0 ? area.toLocaleString('fr-FR') + ' m²' : '—');

  // Validity
  set('d_expiry_date', fmtDateDMY(document.getElementById('f_expiry_date').value));

  // Reservation
  const mode = document.getElementById('f_reservation_mode').value;
  const modeLabels = {'1':'Paiement effectué','2':'Paiement différé','3':'Sans paiement'};
  set('d_reservation_mode_label', modeLabels[mode] || '');

  document.getElementById('d_case1').style.display = mode === '1' ? '' : 'none';
  document.getElementById('d_case2').style.display = mode === '2' ? '' : 'none';
  document.getElementById('d_case3').style.display = mode === '3' ? '' : 'none';

  const currency = document.getElementById('f_currency').value || 'TND';
  const amt = parseFloat(document.getElementById('f_reservation_amount').value) || 0;
  const amtFmt = amt > 0 ? amt.toLocaleString('fr-FR', {minimumFractionDigits:3, maximumFractionDigits:3}) : '—';
  const words = document.getElementById('f_reservation_words').value.trim() || '—';
  set('d_reservation_amount',  amtFmt);
  set('d_reservation_amount2', amtFmt);
  set('d_currency1', currency);
  set('d_currency2', currency);
  set('d_reservation_words1', words);
  set('d_reservation_words2', words);
  set('d_payment_method', document.getElementById('f_payment_method').value);
  const ref = document.getElementById('f_payment_ref').value.trim();
  set('d_payment_ref', ref || '—');
  set('d_deadline', fmtDateDMY(document.getElementById('f_deadline').value));

  // Coordonnées bancaires
  set('d_bank_name',   document.getElementById('f_bank_name').value);
  set('d_bank_holder', document.getElementById('f_bank_holder').value);
  set('d_bank_rib',    document.getElementById('f_bank_rib').value);
  set('d_bank_iban',   document.getElementById('f_bank_iban').value);
  set('d_bank_bic',    document.getElementById('f_bank_bic').value);
  set('d_bank_ref',    document.getElementById('f_bank_ref').value.trim() || '—');

  // Signature
  set('d_sig_city', document.getElementById('f_sig_city').value || '—');
  set('d_sig_date', fmtDateFr(document.getElementById('f_doc_date').value));
  set('d_signatory', document.getElementById('f_signatory').value || '[Signataire]');
  set('d_sig_position', document.getElementById('f_sig_position').value);
}

// Auto-update on any input change
document.querySelectorAll('.panel input, .panel select').forEach(el => {
  el.addEventListener('input', updateDoc);
  el.addEventListener('change', updateDoc);
});

// ── Données PHP exposées pour saveOption() ──────────────────────────────
const OPT_PHP = {
  promise_id: <?= (int)($promise['id'] ?? 0) ?>,
  client_id:  <?= (int)($promise['client_id'] ?? 0) ?>,
  project_id: <?= isset($promise['project_id']) && $promise['project_id'] ? (int)$promise['project_id'] : 'null' ?>,
  unit_code:  <?= json_encode($promise['unit_code'] ?? '') ?>,
  client_cin: <?= json_encode($promise['client_cin'] ?? '') ?>,
};

function flash(msg, type) {
  const box = document.getElementById('flashBox');
  box.textContent = msg;
  box.style.display = '';
  if (type === 'ok') {
    box.style.background = '#ecfdf5'; box.style.color = '#166534'; box.style.border = '1px solid #bbf7d0';
  } else {
    box.style.background = '#fef2f2'; box.style.color = '#991b1b'; box.style.border = '1px solid #fecaca';
  }
}

async function saveOption() {
  const btn = document.getElementById('btnSave');
  btn.disabled = true;
  btn.textContent = '⏳ Enregistrement…';

  const reservMode = document.getElementById('f_reservation_mode').value;
  const payStatusMap = {'1': 'paid', '2': 'deferred', '3': 'none'};

  const payload = {
    option_number:      document.getElementById('f_option_ref').value.trim(),
    promise_id:         OPT_PHP.promise_id,
    client_id:          OPT_PHP.client_id,
    project_id:         OPT_PHP.project_id,
    unit_code:          OPT_PHP.unit_code,
    client_cin:         OPT_PHP.client_cin,
    option_date:        document.getElementById('f_doc_date').value,
    valid_until:        document.getElementById('f_expiry_date').value,
    reservation_amount: parseFloat(document.getElementById('f_reservation_amount').value) || 0,
    currency:           document.getElementById('f_currency').value,
    payment_status:     payStatusMap[reservMode] || 'none',
    payment_method:     document.getElementById('f_payment_method').value,
    payment_reference:  document.getElementById('f_payment_ref').value.trim(),
    status:             'active',
    details_json: {
      representative:   document.getElementById('f_representative').value.trim(),
      rep_position:     document.getElementById('f_rep_position').value.trim(),
      property_type:    document.getElementById('f_property_type').value,
      project_status:   document.getElementById('f_project_status').value,
      area:             parseFloat(document.getElementById('f_area').value) || 0,
      reservation_mode: reservMode,
      reservation_words:document.getElementById('f_reservation_words').value.trim(),
      bank_name:        document.getElementById('f_bank_name').value.trim(),
      bank_holder:      document.getElementById('f_bank_holder').value.trim(),
      bank_rib:         document.getElementById('f_bank_rib').value.trim(),
      bank_iban:        document.getElementById('f_bank_iban').value.trim(),
      bank_bic:         document.getElementById('f_bank_bic').value.trim(),
      bank_ref:         document.getElementById('f_bank_ref').value.trim(),
      sig_city:         document.getElementById('f_sig_city').value.trim(),
      signatory:        document.getElementById('f_signatory').value.trim(),
      sig_position:     document.getElementById('f_sig_position').value.trim(),
    },
  };

  try {
    const res  = await fetch('api/option_save.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload),
    });
    const json = await res.json();
    if (!res.ok || json.error) {
      flash('Erreur : ' + (json.error || 'réponse invalide'), 'err');
    } else {
      flash('✓ Option ' + json.option_number + ' enregistrée avec succès.', 'ok');
      document.getElementById('f_option_ref').value = json.option_number;
      document.getElementById('d_option_ref').textContent = json.option_number;
    }
  } catch (e) {
    flash('Erreur réseau : ' + e.message, 'err');
  } finally {
    btn.disabled = false;
    btn.textContent = '💾 Enregistrer l\'option';
  }
}

// Initialize
toggleReservationFields();
updateDoc();
</script>
</body>
</html>
