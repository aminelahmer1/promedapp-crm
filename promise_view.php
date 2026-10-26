<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$promise_id = (int)($_GET['id'] ?? 0);
$projects   = $pdo->query("SELECT id,code,nom FROM projects ORDER BY code")->fetchAll();
$promise = null; $schedule = null; $installments = []; $payments = []; $audit = [];

if($promise_id > 0){
    try{
        $st=$pdo->prepare("SELECT p.*,c.nom AS client_nom,c.tel AS client_tel,pj.code AS project_code FROM promises p LEFT JOIN clients c ON c.id=p.client_id LEFT JOIN projects pj ON pj.id=p.project_id WHERE p.id=?");
        $st->execute([$promise_id]); $promise=$st->fetch();
        if($promise){
            $s=$pdo->prepare("SELECT * FROM payment_schedules WHERE promise_id=?");
            $s->execute([$promise_id]); $schedule=$s->fetch()?:null;
            if($schedule){
                $s2=$pdo->prepare("SELECT * FROM payment_installments WHERE schedule_id=? ORDER BY sort_order,due_date");
                $s2->execute([$schedule['id']]); $installments=$s2->fetchAll();
            }
            $s3=$pdo->prepare("SELECT py.*,u.username AS by_name FROM payments py LEFT JOIN users u ON u.id=py.created_by WHERE py.promise_id=? ORDER BY py.payment_date DESC");
            $s3->execute([$promise_id]); $payments=$s3->fetchAll();
            $s4=$pdo->prepare("SELECT al.*,u.username FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id WHERE al.entity_type='promise' AND al.entity_id=? ORDER BY al.created_at DESC LIMIT 20");
            $s4->execute([$promise_id]); $audit=$s4->fetchAll();
        }
    }catch(Throwable $e){}
}

$locked    = $promise && $promise['is_locked'];
$detailsJson = [];
if ($promise && !empty($promise['details_json'])) {
    $detailsJson = json_decode($promise['details_json'], true) ?: [];
}
$coClients = $detailsJson['co_clients'] ?? [];
$ht        = (float)($promise['sale_price']   ?? 0);
$vat       = (float)($promise['vat_rate']     ?? 19);
$tva       = $ht * $vat / 100;
$ttc       = $ht + $tva;
$extra     = (float)($promise['extra_works']  ?? 0);
$total_due = $ttc + $extra;
$paid      = (float)($schedule['paid_amount'] ?? 0);
$remain    = max(0, $total_due - $paid);
$prog      = $total_due > 0 ? min(100, $paid / $total_due * 100) : 0;

$SL  = ['draft'=>'Brouillon','signed'=>'Signé','converted_to_sale'=>'Converti en vente','cancelled'=>'Annulé'];
$SC  = ['draft'=>'b-draft','signed'=>'b-signed','converted_to_sale'=>'b-conv','cancelled'=>'b-cancel'];
$DSL = ['pending'=>'En attente','in_progress'=>'En cours','paid'=>'Soldé','overdue'=>'Retard'];
$DSC = ['pending'=>'b-pend','in_progress'=>'b-prog','paid'=>'b-paid','overdue'=>'b-over'];
$IL  = ['pending'=>'En attente','partially_paid'=>'Part. payé','paid'=>'Payé','overdue'=>'Retard'];
$IC  = ['pending'=>'b-pend','partially_paid'=>'b-prog','paid'=>'b-paid','overdue'=>'b-over'];
$PAY_ICON  = ['cash'=>'💵','cheque'=>'📑','bank_transfer'=>'🏦','traite'=>'📄'];
$PAY_LABEL = ['cash'=>'Espèces','cheque'=>'Chèque','bank_transfer'=>'Virement','traite'=>'Traite'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title><?=$promise?htmlspecialchars($promise['promise_number']).' – PromedApp':'Nouvelle Promesse'?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--bg:#f4f4f4;--paper:#fff;--line:#bdbdbd;--text:#151515;--muted:#5e5e5e;--soft:#efefef;--brand:#145f7d;--brand2:#a56d2d}
  *{box-sizing:border-box} html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif} body{padding:24px}
  .app{display:grid;grid-template-columns:500px minmax(600px,1fr);gap:24px;align-items:start;max-width:1700px;margin:0 auto}
  .panel{background:#fff;border:1px solid #ddd;border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.05);position:sticky;top:18px;max-height:calc(100vh - 36px);overflow:auto}
  .panel h1{font-size:20px;margin:0 0 4px}
  .panel .sub{color:var(--muted);font-size:13px;margin-bottom:16px}
  .form-section{border-top:1px solid #e5e5e5;padding-top:14px;margin-top:14px}
  .form-section h2{font-size:14px;margin:0 0 10px;text-transform:uppercase;letter-spacing:.4px}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
  .mt-10{margin-top:10px}
  label{display:block;font-size:12px;font-weight:700;color:#202020;margin-bottom:4px}
  input,select,textarea,button{font:inherit}
  input[type=text],input[type=number],input[type=date],select,textarea{width:100%;border:1px solid #d7d7d7;border-radius:10px;padding:10px 12px;background:#fff;font-size:13px}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--brand)}
  input:disabled,select:disabled,textarea:disabled{background:#f5f5f5;color:var(--muted);cursor:default}
  textarea{min-height:64px;resize:vertical}
  .inline-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
  .btn{border:0;border-radius:12px;padding:12px 14px;font-weight:700;cursor:pointer;font-size:13px;text-decoration:none;display:inline-block;text-align:center}
  .btn-primary{background:#111;color:#fff}
  .btn-secondary{background:var(--soft);color:var(--text)}
  .btn-brand{background:var(--brand);color:#fff}
  .btn-save{background:#19733e;color:#fff}
  .btn-danger{background:#c0392b;color:#fff}
  .btn-full{width:100%;display:block;margin-top:8px}
  .btn-sm{padding:7px 12px;font-size:12px;border-radius:9px}
  .nav-link{display:block;color:var(--brand);font-size:13px;text-decoration:none;padding:6px 0;border-bottom:1px solid #f0f0f0}
  .nav-link:hover{text-decoration:underline}
  .nav-link:last-child{border:none}
  .flash{padding:10px 12px;border-radius:10px;font-size:13px;margin-bottom:12px;display:none}
  .flash.ok{display:block;background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
  .flash.err{display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
  .lock-notice{background:#fef9ec;border:1px solid #e8c84a;border-radius:10px;padding:10px 14px;font-size:13px;color:#7d5a00;margin-bottom:14px}

  /* Right cards */
  .right-col{display:flex;flex-direction:column;gap:18px}
  .card{background:#fff;border:1px solid #ddd;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.05);overflow:hidden}
  .card-head{padding:14px 18px 12px;border-bottom:1px solid #ebebeb;display:flex;align-items:center;justify-content:space-between;gap:10px}
  .card-head h2{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin:0;color:var(--muted)}
  .card-body{padding:16px 18px}

  /* Badges */
  .badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700}
  .b-draft{background:#f0f0f0;color:#555}
  .b-signed{background:#d1fae5;color:#065f46}
  .b-conv{background:#dbeafe;color:#1e40af}
  .b-cancel{background:#fee2e2;color:#991b1b}
  .b-pend{background:#fef3c7;color:#92400e}
  .b-prog{background:#dbeafe;color:#1e40af}
  .b-paid{background:#d1fae5;color:#065f46}
  .b-over{background:#fee2e2;color:#991b1b}

  /* KV rows */
  .kv-row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px dashed #ebebeb;font-size:13px}
  .kv-row:last-child{border:none}
  .kv-row .k{color:var(--muted)}
  .kv-row .v{font-weight:700}
  .kv-total{border-top:2px solid var(--brand)!important;border-bottom:none!important;padding-top:10px!important;margin-top:4px}
  .kv-total .k,.kv-total .v{font-size:15px;font-weight:800;color:var(--brand)}

  /* Progress */
  .prog-wrap{margin:12px 0 6px}
  .prog-bar{height:10px;background:#e5e5e5;border-radius:5px;overflow:hidden}
  .prog-fill{height:100%;background:var(--brand);border-radius:5px}
  .prog-labels{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:4px}

  /* Installments */
  .inst-table{width:100%;border-collapse:collapse;font-size:13px}
  .inst-table th{background:#f8f8f8;padding:9px 12px;text-align:left;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;border-bottom:1px solid #ebebeb}
  .inst-table td{padding:8px 12px;border-bottom:1px solid #f4f4f4;vertical-align:middle}
  .inst-table tr:last-child td{border:none}
  .inst-table input{width:100%;padding:6px 9px;border:1px solid #d7d7d7;border-radius:8px;font-size:12px;font-family:inherit}
  .inst-table input:focus{outline:none;border-color:var(--brand)}
  .add-row-btn{display:block;width:100%;padding:9px;background:var(--soft);border:1px dashed #ccc;border-radius:10px;color:var(--brand);font-size:13px;font-weight:700;cursor:pointer;text-align:center;margin-top:8px;font-family:inherit}
  .add-row-btn:hover{background:#e8f0f4;border-color:var(--brand)}
  .del-btn{background:none;border:none;cursor:pointer;color:#c0392b;font-size:18px;padding:0 4px;font-family:inherit;line-height:1}

  /* Payments */
  .pay-list{list-style:none;margin:0;padding:0}
  .pay-item{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f4f4f4}
  .pay-item:last-child{border:none}
  .pay-icon{width:34px;height:34px;border-radius:50%;background:var(--soft);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
  .pay-amt{font-weight:800;color:#19733e;font-size:14px}
  .pay-sub{font-size:11px;color:var(--muted);margin-top:2px}
  .pay-date{margin-left:auto;font-size:11px;color:var(--muted);flex-shrink:0}

  /* Audit */
  .audit-list{list-style:none;margin:0;padding:0}
  .audit-list li{display:flex;gap:10px;padding:6px 0;border-bottom:1px solid #f4f4f4;font-size:12px;color:var(--muted)}
  .audit-list li:last-child{border:none}
  .audit-ts{flex-shrink:0;white-space:nowrap}
  .audit-act{font-weight:700;color:var(--text)}

  /* Autocomplete */
  .ac-wrap{position:relative}
  .ac-dd{position:absolute;top:100%;left:0;right:0;z-index:500;background:#fff;border:1px solid #d7d7d7;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:200px;overflow-y:auto;margin-top:2px;display:none}
  .ac-item{padding:10px 14px;cursor:pointer;border-bottom:1px solid #f4f4f4;font-size:13px}
  .ac-item:hover{background:#f5fbfd}
  .ac-item:last-child{border:none}
  .ac-name{font-weight:700}
  .ac-sub{font-size:11px;color:var(--muted)}

  /* Modal */
  .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:1000;display:flex;align-items:center;justify-content:center;padding:16px}
  .modal-box{background:#fff;border-radius:18px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
  .modal-head{padding:16px 20px 14px;border-bottom:1px solid #ebebeb;display:flex;align-items:center;justify-content:space-between}
  .modal-head h3{font-size:16px;font-weight:700;margin:0}
  .modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1}
  .modal-body{padding:20px}
  .modal-foot{padding:12px 20px 18px;display:flex;gap:8px;justify-content:flex-end;border-top:1px solid #ebebeb}
  .hidden{display:none!important}
  @media(max-width:1200px){.app{grid-template-columns:1fr}.panel{position:relative;top:auto;max-height:none}}
</style>
</head>
<body>
<div class="app">

<!-- ══ PANEL GAUCHE ══ -->
<aside class="panel">
  <h1><?=$promise?htmlspecialchars($promise['promise_number']):'Nouvelle promesse'?></h1>
  <p class="sub">
    <?php if($promise):
      $sc=$SC[$promise['status']]??'b-draft'; $sl=$SL[$promise['status']]??$promise['status'];
    ?>
    <span class="badge <?=$sc?>"><?=htmlspecialchars($sl)?></span>
    <?php if($locked): ?>&nbsp;<span class="badge b-over">🔒 Verrouillée</span><?php endif; ?>
    <?php else: ?>Saisir les informations de la promesse<?php endif; ?>
  </p>

  <div id="flashBox" class="flash"></div>

  <?php if($locked): ?>
  <div class="lock-notice">🔒 <strong>Promesse verrouillée.</strong> Convertie en vente — modification impossible.</div>
  <?php endif; ?>

  <!-- Navigation -->
  <div class="form-section">
    <h2>Navigation</h2>
    <a class="nav-link" href="promises.php">← Promesses de vente</a>
    <a class="nav-link" href="clients.php">Dossiers clients</a>
  </div>

  <input type="hidden" id="prom-id" value="<?=(int)($promise['id']??0)?>">

  <!-- Client -->
  <div class="form-section">
    <h2>1. Client(s)</h2>
    <div class="ac-wrap">
      <label>Acquéreur principal</label>
      <input type="text" id="client_search"
        value="<?=htmlspecialchars($promise['client_nom']??'')?>"
        placeholder="Rechercher un client…" autocomplete="off"
        <?=$locked?'disabled':''?>>
      <input type="hidden" id="client_id" value="<?=(int)($promise['client_id']??0)?>">
      <div class="ac-dd" id="ac_dd"></div>
    </div>
    <!-- Co-acquéreurs -->
    <div id="co-clients-list" style="margin-top:10px"></div>
    <?php if(!$locked): ?>
    <button type="button" onclick="addCoClient()" style="margin-top:10px;background:#f0f7ff;color:#145f7d;border:1px solid #b8d4f0;border-radius:6px;padding:7px 14px;cursor:pointer;font-size:13px;width:100%">
      ➕ Ajouter un co-acquéreur
    </button>
    <?php endif; ?>
  </div>
  <script>
  const CO_CLIENTS_INIT = <?=json_encode($coClients, JSON_UNESCAPED_UNICODE)?>;
  const LOCKED = <?=$locked?'true':'false'?>;
  </script>

  <!-- Bien -->
  <div class="form-section">
    <h2>2. Bien réservé</h2>
    <div class="grid-2">
      <div>
        <label>Projet</label>
        <select id="project_id" <?=$locked?'disabled':''?>>
          <option value="">— Sélectionner —</option>
          <?php foreach($projects as $pj): ?>
          <option value="<?=(int)$pj['id']?>" <?=(int)($promise['project_id']??0)===(int)$pj['id']?'selected':''?>><?=htmlspecialchars($pj['code'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Code unité</label>
        <input type="text" id="unit_code" value="<?=htmlspecialchars($promise['unit_code']??'')?>" placeholder="Ex: A-101" <?=$locked?'disabled':''?>>
      </div>
    </div>
    <div class="grid-2 mt-10">
      <div>
        <label>Parking</label>
        <input type="text" id="parking_codes" value="<?=htmlspecialchars($promise['parking_codes']??'')?>" placeholder="P01, P02…" <?=$locked?'disabled':''?>>
      </div>
      <div>
        <label>Cellier</label>
        <input type="text" id="cellier_codes" value="<?=htmlspecialchars($promise['cellier_codes']??'')?>" placeholder="C01…" <?=$locked?'disabled':''?>>
      </div>
    </div>
  </div>

  <!-- Prix -->
  <div class="form-section">
    <h2>3. Prix &amp; conditions</h2>
    <div class="grid-3">
      <div>
        <label>Prix HT</label>
        <input type="number" id="sale_price" value="<?=htmlspecialchars($promise['sale_price']??'')?>" min="0" step="0.001" <?=$locked?'disabled':''?>>
      </div>
      <div>
        <label>TVA (%)</label>
        <input type="number" id="vat_rate" value="<?=htmlspecialchars($promise['vat_rate']??19)?>" min="0" max="100" <?=$locked?'disabled':''?>>
      </div>
      <div>
        <label>Avance</label>
        <input type="number" id="advance_amount" value="<?=htmlspecialchars($promise['advance_amount']??0)?>" min="0" step="0.001" <?=$locked?'disabled':''?>>
      </div>
    </div>
    <div class="grid-2 mt-10">
      <div>
        <label>Travaux supplémentaires</label>
        <input type="number" id="extra_works" value="<?=htmlspecialchars($promise['extra_works']??0)?>" min="0" step="0.001" <?=$locked?'disabled':''?>>
      </div>
      <div>
        <label>Statut</label>
        <select id="status" <?=$locked?'disabled':''?>>
          <option value="draft"     <?=($promise['status']??'draft')==='draft'    ?'selected':''?>>Brouillon</option>
          <option value="signed"    <?=($promise['status']??'')==='signed'         ?'selected':''?>>Signé</option>
          <option value="cancelled" <?=($promise['status']??'')==='cancelled'      ?'selected':''?>>Annulé</option>
        </select>
      </div>
    </div>
    <div class="grid-2 mt-10">
      <div>
        <label>Date promesse</label>
        <input type="date" id="promise_date" value="<?=htmlspecialchars($promise['promise_date']??'')?>" <?=$locked?'disabled':''?>>
      </div>
      <div>
        <label>Date signature</label>
        <input type="date" id="signed_date" value="<?=htmlspecialchars($promise['signed_date']??'')?>" <?=$locked?'disabled':''?>>
      </div>
    </div>
  </div>

  <!-- Actions -->
  <?php if(!$locked): ?>
  <div class="form-section">
    <h2>Actions</h2>
    <div class="inline-actions">
      <button class="btn btn-brand" onclick="saveProm()">💾 Enregistrer</button>
      <?php if(($promise['status']??'')==='signed'): ?>
      <button class="btn btn-danger btn-sm" onclick="convertToSale()">🔒 Convertir en vente</button>
      <?php endif; ?>
    </div>
    <button class="btn btn-brand btn-full" onclick="saveProm()">💾 Enregistrer la promesse</button>
  </div>
  <?php endif; ?>

  <?php if($promise_id > 0): ?>
  <div class="form-section">
    <h2>Documents</h2>
    <a class="btn btn-secondary btn-full" href="option_vente.php?promise_id=<?=$promise_id?>" style="background:#f0f7ff;color:#145f7d;border:1px solid #b8d4f0;text-align:center;display:block;border-radius:12px;padding:11px 14px;font-weight:700;font-size:13px;text-decoration:none">
      📄 Générer Option de Vente
    </a>
  </div>
  <?php endif; ?>
</aside>

<!-- ══ DROITE ══ -->
<div class="right-col">

  <?php if($promise): ?>

  <!-- Récapitulatif financier -->
  <div class="card">
    <div class="card-head">
      <h2>Récapitulatif financier</h2>
      <?php if($schedule): $dc=$DSC[$schedule['status']]??'b-pend';$dl=$DSL[$schedule['status']]??$schedule['status']; ?>
        <span class="badge <?=$dc?>"><?=htmlspecialchars($dl)?></span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <div class="kv-row"><span class="k">Prix HT</span><span class="v"><?=number_format($ht,3,',',' ')?> TND</span></div>
      <div class="kv-row"><span class="k">TVA (<?=htmlspecialchars($vat)?>%)</span><span class="v"><?=number_format($tva,3,',',' ')?> TND</span></div>
      <div class="kv-row"><span class="k">Prix TTC</span><span class="v"><?=number_format($ttc,3,',',' ')?> TND</span></div>
      <?php if($extra>0): ?>
      <div class="kv-row"><span class="k">Travaux supplémentaires</span><span class="v"><?=number_format($extra,3,',',' ')?> TND</span></div>
      <?php endif; ?>
      <div class="kv-row kv-total"><span class="k">TOTAL DÛ</span><span class="v"><?=number_format($total_due,3,',',' ')?> TND</span></div>
      <div class="prog-wrap">
        <div class="prog-bar"><div class="prog-fill" style="width:<?=number_format($prog,2)?>%"></div></div>
        <div class="prog-labels"><span>Encaissé : <?=number_format($paid,3,',',' ')?> TND</span><span><?=number_format($prog,1)?>%</span></div>
      </div>
      <div class="kv-row">
        <span class="k">Restant à payer</span>
        <span class="v" style="color:<?=$remain>0?'#c0392b':'#19733e'?>"><?=number_format($remain,3,',',' ')?> TND</span>
      </div>
    </div>
  </div>

  <!-- Échéancier -->
  <div class="card">
    <div class="card-head">
      <h2>Échéancier de paiement</h2>
      <?php if(!$locked): ?>
      <button class="btn btn-brand btn-sm" onclick="saveSchedule()">💾 Sauvegarder</button>
      <?php endif; ?>
    </div>
    <div style="overflow-x:auto">
      <table class="inst-table">
        <thead>
          <tr>
            <th style="min-width:150px">Libellé</th>
            <th style="min-width:120px">Échéance</th>
            <th style="min-width:130px">Montant prévu</th>
            <th style="min-width:100px;text-align:right">Payé</th>
            <th style="min-width:90px">Statut</th>
            <?php if(!$locked): ?><th style="width:36px"></th><?php endif; ?>
          </tr>
        </thead>
        <tbody id="instBody">
          <?php foreach($installments as $inst):
            $ic=$IC[$inst['status']]??'b-pend'; $il=$IL[$inst['status']]??$inst['status'];
          ?>
          <tr data-id="<?=(int)$inst['id']?>">
            <td><input type="text" value="<?=htmlspecialchars($inst['label']??'')?>" placeholder="Libellé" <?=$locked?'disabled':''?>></td>
            <td><input type="date" value="<?=htmlspecialchars($inst['due_date']??'')?>" <?=$locked?'disabled':''?>></td>
            <td><input type="number" value="<?=htmlspecialchars($inst['expected_amount']??0)?>" min="0" step="0.001" <?=$locked?'disabled':''?>></td>
            <td style="text-align:right;font-weight:800;color:#19733e"><?=number_format((float)$inst['paid_amount'],3,',',' ')?></td>
            <td><span class="badge <?=$ic?>"><?=htmlspecialchars($il)?></span></td>
            <?php if(!$locked): ?><td><button class="del-btn" onclick="delInst(this)">×</button></td><?php endif; ?>
          </tr>
          <?php endforeach; ?>
          <?php if(!$installments): ?>
          <tr id="emptyInstRow"><td colspan="<?=$locked?5:6?>" style="text-align:center;color:var(--muted);padding:24px;font-size:13px">Aucune échéance définie</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if(!$locked): ?>
    <div style="padding:0 14px 14px">
      <button class="add-row-btn" onclick="addInstRow()">+ Ajouter une échéance</button>
    </div>
    <?php endif; ?>
  </div>

  <!-- Paiements -->
  <div class="card">
    <div class="card-head">
      <h2>Paiements reçus</h2>
      <?php if(!$locked): ?>
      <button class="btn btn-save btn-sm" onclick="openPayModal()">+ Enregistrer un paiement</button>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if(!$payments): ?>
      <div style="text-align:center;color:var(--muted);padding:16px;font-size:13px">Aucun paiement enregistré</div>
      <?php else: ?>
      <ul class="pay-list">
        <?php foreach($payments as $py): ?>
        <li class="pay-item">
          <div class="pay-icon"><?=$PAY_ICON[$py['payment_method']]??'💳'?></div>
          <div>
            <div class="pay-amt"><?=number_format((float)$py['amount'],3,',',' ')?> TND</div>
            <div class="pay-sub">
              <?=$PAY_LABEL[$py['payment_method']]??$py['payment_method']?>
              <?php if($py['bank_name']): ?> · <?=htmlspecialchars($py['bank_name'])?><?php endif; ?>
              <?php if($py['reference_number']): ?> · Réf&nbsp;: <?=htmlspecialchars($py['reference_number'])?><?php endif; ?>
              <?php if($py['by_name']): ?> · <?=htmlspecialchars($py['by_name'])?><?php endif; ?>
            </div>
          </div>
          <div class="pay-date"><?=htmlspecialchars(substr($py['payment_date'],0,10))?></div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>

  <!-- Journal audit -->
  <div class="card">
    <div class="card-head"><h2>Journal des modifications</h2></div>
    <div class="card-body">
      <?php if(!$audit): ?>
      <div style="text-align:center;color:var(--muted);padding:10px;font-size:13px">Aucune activité enregistrée</div>
      <?php else: ?>
      <ul class="audit-list">
        <?php foreach($audit as $log): ?>
        <li>
          <span class="audit-ts"><?=htmlspecialchars(substr($log['created_at'],0,16))?></span>
          <span class="audit-act"><?=htmlspecialchars($log['action'])?></span>
          <span><?=htmlspecialchars($log['username']??'système')?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>

  <?php else: ?>
  <!-- Nouvelle promesse -->
  <div class="card"><div class="card-body" style="text-align:center;padding:60px;color:var(--muted)">
    <div style="font-size:40px;margin-bottom:12px">📋</div>
    <div style="font-size:14px;font-weight:700">Remplissez le formulaire et enregistrez<br>pour créer la promesse.</div>
  </div></div>
  <?php endif; ?>

</div>
</div>

<!-- ══ MODAL PAIEMENT ══ -->
<div class="modal-overlay hidden" id="payModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Enregistrer un paiement</h3>
      <button class="modal-close" onclick="closePayModal()">×</button>
    </div>
    <div class="modal-body">
      <div class="grid-2">
        <div><label>Montant (TND)</label><input type="number" id="pay_amount" min="0.001" step="0.001" placeholder="0.000"></div>
        <div><label>Date de paiement</label><input type="date" id="pay_date" value="<?=date('Y-m-d')?>"></div>
      </div>
      <div class="grid-2 mt-10">
        <div>
          <label>Méthode</label>
          <select id="pay_method">
            <option value="bank_transfer">Virement bancaire</option>
            <option value="cheque">Chèque</option>
            <option value="cash">Espèces</option>
            <option value="traite">Traite</option>
          </select>
        </div>
        <div><label>Banque</label><input type="text" id="pay_bank" placeholder="Nom de la banque…"></div>
      </div>
      <div class="grid-2 mt-10">
        <div><label>N° référence</label><input type="text" id="pay_ref" placeholder="N° chèque / virement…"></div>
        <div>
          <label>Lier à une échéance</label>
          <select id="pay_inst"><option value="">— Aucune —</option></select>
        </div>
      </div>
      <div class="mt-10"><label>Notes</label><textarea id="pay_notes" placeholder="Observations…"></textarea></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" onclick="closePayModal()">Annuler</button>
      <button class="btn btn-save" onclick="submitPayment()">✓ Valider le paiement</button>
    </div>
  </div>
</div>

<script>
const PROM_ID = <?=(int)($promise['id']??0)?>;

function flash(msg, type){ const b=document.getElementById('flashBox'); b.className='flash '+(type||'ok'); b.textContent=msg; setTimeout(()=>b.className='flash',4000); }
function esc(s){ const d=document.createElement('div');d.textContent=s||'';return d.innerHTML; }

/* Co-acquéreurs */
let coClientIdx = 0;

function bindAc(inp, hid, dd) {
  let t;
  inp.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(async () => {
      const q = inp.value.trim();
      if (q.length < 2) { dd.style.display = 'none'; return; }
      const r = await fetch('api/clients_list.php?q=' + encodeURIComponent(q) + '&limit=8');
      const d = await r.json();
      const cl = d.clients || [];
      dd.innerHTML = '';
      if (!cl.length) { dd.style.display = 'none'; return; }
      cl.forEach(c => {
        const item = document.createElement('div');
        item.className = 'ac-item';
        item.innerHTML = '<div class="ac-name">' + esc(c.nom) + '</div><div class="ac-sub">' + esc(c.tel || c.email || c.cin || '') + '</div>';
        item.addEventListener('mousedown', e => { e.preventDefault(); inp.value = c.nom; hid.value = c.id; dd.style.display = 'none'; });
        dd.appendChild(item);
      });
      dd.style.display = 'block';
    }, 250);
  });
  document.addEventListener('click', e => { if (!inp.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none'; });
}

function addCoClient(data) {
  const idx = ++coClientIdx;
  const list = document.getElementById('co-clients-list');
  const wrap = document.createElement('div');
  wrap.className = 'ac-wrap';
  wrap.dataset.idx = idx;
  wrap.style.cssText = 'position:relative;margin-top:8px;display:flex;gap:6px;align-items:flex-start';
  wrap.innerHTML = `
    <div style="flex:1;position:relative">
      <label style="font-size:12px;color:#5e5e5e">Co-acquéreur</label>
      <input type="text" class="co-inp" data-idx="${idx}" placeholder="Rechercher un client…" autocomplete="off"
             value="${data ? esc(data.nom || '') : ''}"
             ${LOCKED ? 'disabled' : ''}>
      <input type="hidden" class="co-hid" data-idx="${idx}" value="${data ? (data.id || '') : ''}">
      <div class="ac-dd co-dd" data-idx="${idx}"></div>
    </div>
    ${!LOCKED ? `<button type="button" onclick="removeCoClient(${idx})" title="Supprimer" style="margin-top:18px;background:none;border:none;color:#c0392b;font-size:18px;cursor:pointer;flex-shrink:0">✕</button>` : ''}
  `;
  list.appendChild(wrap);
  const inp = wrap.querySelector('.co-inp');
  const hid = wrap.querySelector('.co-hid');
  const dd  = wrap.querySelector('.co-dd');
  bindAc(inp, hid, dd);
}

function removeCoClient(idx) {
  const el = document.querySelector('[data-idx="' + idx + '"]');
  if (el) el.remove();
}

function getCoClientIds() {
  return Array.from(document.querySelectorAll('.co-hid'))
    .map(h => ({ id: parseInt(h.value) || 0, nom: h.closest('[data-idx]').querySelector('.co-inp').value.trim() }))
    .filter(c => c.id > 0);
}

// Init co-clients on page load
document.addEventListener('DOMContentLoaded', () => {
  (CO_CLIENTS_INIT || []).forEach(c => addCoClient(c));
});

/* Autocomplete client principal */
(function(){
  const inp=document.getElementById('client_search');
  const hid=document.getElementById('client_id');
  const dd=document.getElementById('ac_dd');
  if(!inp) return;
  bindAc(inp, hid, dd);
})();

/* Save promise */
async function saveProm(){
  const client_id=parseInt(document.getElementById('client_id').value);
  const unit_code=document.getElementById('unit_code').value.trim();
  const sale_price=parseFloat(document.getElementById('sale_price').value);
  if(!client_id){ flash('Veuillez sélectionner un client.','err'); return; }
  if(!unit_code){ flash('Le code unité est obligatoire.','err'); return; }
  if(!(sale_price>0)){ flash('Le prix de vente est obligatoire.','err'); return; }
  const payload={
    id: PROM_ID||undefined,
    client_id, unit_code,
    project_id: parseInt(document.getElementById('project_id').value)||null,
    parking_codes: document.getElementById('parking_codes').value.trim()||null,
    cellier_codes: document.getElementById('cellier_codes').value.trim()||null,
    sale_price,
    vat_rate: parseFloat(document.getElementById('vat_rate').value)||19,
    advance_amount: parseFloat(document.getElementById('advance_amount').value)||0,
    extra_works: parseFloat(document.getElementById('extra_works').value)||0,
    status: document.getElementById('status').value,
    promise_date: document.getElementById('promise_date').value||null,
    signed_date: document.getElementById('signed_date').value||null,
    details_json: { co_clients: getCoClientIds() },
  };
  const r=await fetch('api/promise_save.php',{method:'POST',body:JSON.stringify(payload),headers:{'Content-Type':'application/json'}});
  const d=await r.json();
  if(d.error){ flash('Erreur : '+d.error,'err'); return; }
  if(!PROM_ID&&d.id){ window.location='promise_view.php?id='+d.id; return; }
  flash('Promesse enregistrée avec succès.'); setTimeout(()=>location.reload(),1200);
}

async function convertToSale(){
  if(!confirm('Convertir en vente et verrouiller définitivement cette promesse ?')) return;
  const r=await fetch('api/promise_save.php',{method:'POST',body:JSON.stringify({
    id:PROM_ID,
    client_id:parseInt(document.getElementById('client_id').value),
    unit_code:document.getElementById('unit_code').value.trim(),
    sale_price:parseFloat(document.getElementById('sale_price').value),
    status:'converted_to_sale'
  }),headers:{'Content-Type':'application/json'}});
  const d=await r.json();
  if(d.error){ flash(d.error,'err'); return; }
  location.reload();
}

/* Installments */
function addInstRow(){
  const empty=document.getElementById('emptyInstRow');
  if(empty) empty.remove();
  const tr=document.createElement('tr');
  tr.innerHTML='<td><input type="text" placeholder="Libellé"></td><td><input type="date"></td><td><input type="number" min="0" step="0.001" placeholder="0.000"></td><td style="text-align:right;color:var(--muted)">—</td><td><span class="badge b-pend">En attente</span></td><td><button class="del-btn" onclick="delInst(this)">×</button></td>';
  document.getElementById('instBody').appendChild(tr);
}
function delInst(btn){
  const tbody=document.getElementById('instBody');
  btn.closest('tr').remove();
  if(!tbody.querySelector('tr[data-id],tr:not(#emptyInstRow)'))
    tbody.innerHTML='<tr id="emptyInstRow"><td colspan="6" style="text-align:center;color:var(--muted);padding:24px;font-size:13px">Aucune échéance définie</td></tr>';
}
async function saveSchedule(){
  const rows=[...document.getElementById('instBody').querySelectorAll('tr[data-id],tr:not(#emptyInstRow)')];
  const installments=rows.map(tr=>{
    const inp=tr.querySelectorAll('input');
    return{label:inp[0]?.value.trim()||null,due_date:inp[1]?.value||null,expected_amount:parseFloat(inp[2]?.value)||0};
  }).filter(i=>i.expected_amount>0);
  const r=await fetch('api/schedule_save.php',{method:'POST',body:JSON.stringify({promise_id:PROM_ID,installments}),headers:{'Content-Type':'application/json'}});
  const d=await r.json();
  if(d.error){ flash(d.error,'err'); return; }
  flash('Échéancier enregistré.'); setTimeout(()=>location.reload(),1000);
}

/* Payment modal */
function openPayModal(){
  const sel=document.getElementById('pay_inst');
  sel.innerHTML='<option value="">— Aucune échéance —</option>';
  <?php foreach($installments as $inst): ?>
  (function(){ const o=document.createElement('option'); o.value=<?=(int)$inst['id']?>; o.textContent=<?=json_encode(($inst['label']??'Échéance #'.$inst['id']).' – '.number_format((float)$inst['expected_amount'],0,',',' ').' TND')?>; document.getElementById('pay_inst').appendChild(o); })();
  <?php endforeach; ?>
  document.getElementById('payModal').classList.remove('hidden');
}
function closePayModal(){ document.getElementById('payModal').classList.add('hidden'); }
async function submitPayment(){
  const amount=parseFloat(document.getElementById('pay_amount').value);
  const date=document.getElementById('pay_date').value;
  if(!(amount>0)){ flash('Montant invalide','err'); return; }
  if(!date){ flash('Date requise','err'); return; }
  const r=await fetch('api/payment_add.php',{method:'POST',body:JSON.stringify({
    promise_id:PROM_ID, amount, payment_date:date,
    payment_method:document.getElementById('pay_method').value,
    bank_name:document.getElementById('pay_bank').value.trim()||null,
    reference_number:document.getElementById('pay_ref').value.trim()||null,
    installment_id:parseInt(document.getElementById('pay_inst').value)||null,
    notes:document.getElementById('pay_notes').value.trim()||null,
  }),headers:{'Content-Type':'application/json'}});
  const d=await r.json();
  if(d.error){ flash(d.error,'err'); return; }
  closePayModal(); flash('Paiement enregistré.'); setTimeout(()=>location.reload(),1000);
}
</script>
</body>
</html>
