<?php
// DEPRECATED — remplacé par crm_client.php (profil client) + promise_view.php (promesse)
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$id = (int)($_GET['id'] ?? 0);
// Try to find client_id from crm_dossiers for backward compat
$clientId = 0;
if ($id) {
    try {
        $r = $pdo->prepare("SELECT client_id FROM crm_dossiers WHERE id=? LIMIT 1");
        $r->execute([$id]);
        $clientId = (int)($r->fetchColumn() ?: 0);
    } catch(Throwable $e){}
}
header('Location: ' . ($clientId ? "crm_client.php?id=$clientId" : 'crm_clients.php'));
exit;
if (!$id) { header('Location: crm_dossiers.php'); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT d.*, c.nom AS client_nom, c.tel AS client_tel, c.email AS client_email,
               c.cin AS client_cin, c.address AS client_address,
               pj.code AS project_code, pj.nom AS project_nom,
               u.full_name AS assigned_nom
        FROM crm_dossiers d
        LEFT JOIN clients c ON c.id = d.client_id
        LEFT JOIN projects pj ON pj.id = d.project_id
        LEFT JOIN users u ON u.id = d.assigned_to
        WHERE d.id = ?
    ");
    $stmt->execute([$id]);
    $d = $stmt->fetch();
    if (!$d) { header('Location: crm_dossiers.php'); exit; }

    $pStmt = $pdo->prepare("
        SELECT p.*, ps.paid_amount, ps.remaining_amount, ps.progress_percent,
               ps.status AS sched_status, ps.id AS schedule_id
        FROM promises p
        LEFT JOIN payment_schedules ps ON ps.promise_id = p.id
        WHERE p.dossier_id = ?
        ORDER BY p.created_at DESC LIMIT 1
    ");
    $pStmt->execute([$id]);
    $promise = $pStmt->fetch();

    $projects = $pdo->query("SELECT id,code,nom FROM projects ORDER BY code")->fetchAll();
    try { $users = $pdo->query("SELECT id,full_name AS nom FROM users ORDER BY full_name")->fetchAll(); }
    catch (Throwable $e) { $users = []; }

} catch (Throwable $e) {
    die('Erreur: ' . htmlspecialchars($e->getMessage()));
}

$SL = [
  'interested'=>'Intéressé','reserved'=>'Réservé','promise_draft'=>'Promesse brouillon',
  'promise_signed'=>'Promesse signée','sold'=>'Vendu','payment_in_progress'=>'Paiement en cours',
  'paid'=>'Soldé','closed'=>'Clôturé','cancelled'=>'Annulé'
];
$STATUS_NEXT = [
  'interested'=>['reserved'=>'Marquer Réservé','cancelled'=>'Annuler'],
  'reserved'=>['promise_draft'=>'Créer Promesse brouillon','cancelled'=>'Annuler'],
  'promise_draft'=>['promise_signed'=>'Marquer Signé','cancelled'=>'Annuler'],
  'promise_signed'=>['sold'=>'Marquer Vendu','cancelled'=>'Annuler'],
  'sold'=>['payment_in_progress'=>'Paiement en cours','cancelled'=>'Annuler'],
  'payment_in_progress'=>['paid'=>'Marquer Soldé','cancelled'=>'Annuler'],
  'paid'=>['closed'=>'Clôturer'],
  'closed'=>[],'cancelled'=>[],
];
$IL = ['low'=>'Faible','medium'=>'Moyen','high'=>'Élevé','hot'=>'Chaud'];
$ACTIVITY_LABELS = ['call'=>'Appel','whatsapp'=>'WhatsApp','meeting'=>'Rendez-vous','email'=>'Email','note'=>'Note','visit'=>'Visite','task'=>'Tâche'];

$curStatus = $d['status'];
$curSL     = $SL[$curStatus] ?? $curStatus;
$hasPromise = !empty($promise);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title><?=htmlspecialchars($d['dossier_number'])?> – PromedApp</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--bg:#f4f4f4;--paper:#fff;--line:#bdbdbd;--text:#151515;--muted:#5e5e5e;--soft:#efefef;--brand:#145f7d;--brand2:#a56d2d}
  *{box-sizing:border-box} html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif} body{padding:24px}
  .app{display:grid;grid-template-columns:280px 1fr;gap:24px;align-items:start;max-width:1600px;margin:0 auto}
  .panel{background:#fff;border:1px solid #ddd;border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.05);position:sticky;top:18px;max-height:calc(100vh - 36px);overflow:auto}
  .panel h1{font-size:17px;margin:0 0 2px;font-family:monospace;color:var(--brand)}
  .panel .sub{color:var(--muted);font-size:13px;margin-bottom:12px}
  .form-section{border-top:1px solid #e5e5e5;padding-top:14px;margin-top:14px}
  .form-section h2{font-size:12px;margin:0 0 8px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700}
  label{display:block;font-size:12px;font-weight:700;color:#202020;margin-bottom:3px}
  input,select,textarea,button{font:inherit}
  input,select,textarea{width:100%;border:1px solid #d7d7d7;border-radius:10px;padding:9px 12px;background:#fff;font-size:13px;margin-bottom:8px}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--brand)}
  textarea{resize:vertical;min-height:70px}
  .btn{border:0;border-radius:12px;padding:10px 14px;font-weight:700;cursor:pointer;font-size:13px;width:100%;display:block;text-align:center;text-decoration:none;margin-top:4px}
  .btn-brand{background:var(--brand);color:#fff}
  .btn-secondary{background:var(--soft);color:var(--text)}
  .btn-danger{background:#fee2e2;color:#991b1b}
  .btn-sm{width:auto;display:inline-block;padding:6px 12px;font-size:12px;border-radius:8px;margin-top:0}
  .btn-outline{background:#fff;border:1.5px solid var(--brand);color:var(--brand)}
  .btn-outline:hover{background:var(--brand);color:#fff}
  .nav-link{display:block;color:var(--brand);font-size:13px;text-decoration:none;padding:5px 0;border-bottom:1px solid #f0f0f0}
  .nav-link:hover{text-decoration:underline}
  .nav-link:last-child{border:none}
  .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
  .b-interested{background:#fef3c7;color:#92400e}
  .b-reserved{background:#ede9fe;color:#5b21b6}
  .b-promise_draft{background:#f0f0f0;color:#555}
  .b-promise_signed{background:#d1fae5;color:#065f46}
  .b-sold{background:#dbeafe;color:#1e40af}
  .b-payment_in_progress{background:#cffafe;color:#155e75}
  .b-paid{background:#d1fae5;color:#065f46}
  .b-closed{background:#e5e7eb;color:#374151}
  .b-cancelled{background:#fee2e2;color:#991b1b}
  .b-draft{background:#f0f0f0;color:#555}
  .b-signed{background:#d1fae5;color:#065f46}
  .b-converted_to_sale{background:#dbeafe;color:#1e40af}
  .info-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:13px;border-bottom:1px solid #f5f5f5}
  .info-row .lbl{color:var(--muted);font-size:12px}
  .info-row .val{font-weight:700;text-align:right}

  /* Tabs */
  .tabs-wrap{background:#fff;border:1px solid #ddd;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.05);overflow:hidden}
  .tabs-nav{display:flex;border-bottom:1px solid #e5e5e5;overflow-x:auto;background:#f8f8f8}
  .tab-btn{padding:13px 18px;border:none;background:none;cursor:pointer;font:inherit;font-size:13px;font-weight:600;color:var(--muted);white-space:nowrap;border-bottom:2px solid transparent;transition:color .1s,border-color .1s}
  .tab-btn.active{color:var(--brand);border-bottom-color:var(--brand);background:#fff}
  .tab-btn:hover:not(.active){color:var(--text);background:#f0f0f0}
  .tab-content{padding:22px}
  .tab-pane{display:none}
  .tab-pane.active{display:block}

  /* Cards inside tabs */
  .card{background:#f8f8f8;border:1px solid #e8e8e8;border-radius:12px;padding:16px;margin-bottom:14px}
  .card h4{margin:0 0 12px;font-size:14px;font-weight:700}
  .kpi-mini{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-bottom:16px}
  .kmi{background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:12px 14px}
  .kmi .val{font-size:20px;font-weight:800;color:var(--brand)}
  .kmi .val.ok{color:#19733e}
  .kmi .val.warn{color:#c0392b}
  .kmi .lbl{font-size:11px;color:var(--muted)}

  /* Table */
  table{width:100%;border-collapse:collapse}
  thead th{background:#f0f0f0;padding:9px 12px;text-align:left;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;border-bottom:1px solid #e0e0e0}
  tbody tr{border-bottom:1px solid #f0f0f0}
  tbody tr:last-child{border:none}
  tbody td{padding:9px 12px;font-size:13px;vertical-align:middle}
  .amount{font-family:monospace;font-size:12px}

  /* Status bar */
  .status-bar{display:flex;gap:0;margin-bottom:20px;border:1px solid #e0e0e0;border-radius:12px;overflow:hidden}
  .sb-step{flex:1;text-align:center;padding:10px 6px;font-size:10px;font-weight:700;text-transform:uppercase;color:#aaa;background:#fafafa;border-right:1px solid #e0e0e0}
  .sb-step:last-child{border:none}
  .sb-step.done{background:#d1fae5;color:#065f46}
  .sb-step.current{background:var(--brand);color:#fff}
  .sb-step.cancelled-step{background:#fee2e2;color:#991b1b}

  /* Activities */
  .act-item{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid #f0f0f0}
  .act-item:last-child{border:none}
  .act-icon{width:32px;height:32px;border-radius:50%;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
  .act-body .act-type{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted)}
  .act-body .act-desc{font-size:13px;margin:2px 0}
  .act-body .act-meta{font-size:11px;color:var(--muted)}

  .flash{padding:10px 14px;border-radius:10px;font-size:13px;font-weight:700;margin-bottom:14px;display:none}
  .flash.ok{background:#d1fae5;color:#065f46}
  .flash.err{background:#fee2e2;color:#991b1b}

  /* Payment modal */
  .overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center}
  .overlay.open{display:flex}
  .modal{background:#fff;border-radius:18px;padding:28px;width:480px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.2)}
  .modal h2{margin:0 0 18px;font-size:16px}
  .modal-footer{display:flex;gap:10px;margin-top:14px}
  .modal-footer button{flex:1}

  @media(max-width:1100px){.app{grid-template-columns:1fr}.panel{position:relative;top:auto;max-height:none}}
</style>
</head>
<body>
<div class="app">

  <!-- ══ PANEL GAUCHE ══ -->
  <aside class="panel">
    <h1><?=htmlspecialchars($d['dossier_number'])?></h1>
    <p class="sub">Dossier CRM</p>
    <div style="margin-bottom:12px">
      <span class="badge b-<?=$curStatus?>"><?=htmlspecialchars($curSL)?></span>
    </div>

    <div class="info-row"><span class="lbl">Client</span><span class="val"><?=htmlspecialchars($d['client_nom']??'—')?></span></div>
    <?php if($d['client_tel']): ?><div class="info-row"><span class="lbl">Tél</span><span class="val"><?=htmlspecialchars($d['client_tel'])?></span></div><?php endif; ?>
    <?php if($d['project_code']): ?><div class="info-row"><span class="lbl">Projet</span><span class="val"><?=htmlspecialchars($d['project_code'])?></span></div><?php endif; ?>
    <?php if($d['unit_code']): ?><div class="info-row"><span class="lbl">Unité</span><span class="val"><?=htmlspecialchars($d['unit_code'])?></span></div><?php endif; ?>
    <div class="info-row"><span class="lbl">Intérêt</span><span class="val"><?=htmlspecialchars($IL[$d['interest_level']]??$d['interest_level']??'—')?></span></div>
    <?php if($d['assigned_nom']): ?><div class="info-row"><span class="lbl">Assigné à</span><span class="val"><?=htmlspecialchars($d['assigned_nom'])?></span></div><?php endif; ?>
    <?php if($d['budget']): ?><div class="info-row"><span class="lbl">Budget</span><span class="val"><?=number_format((float)$d['budget'],0,',',' ')?> TND</span></div><?php endif; ?>
    <div class="info-row"><span class="lbl">Créé le</span><span class="val"><?=substr($d['created_at'],0,10)?></span></div>
    <?php if($promise): ?>
    <div class="info-row"><span class="lbl">N° Promesse</span><span class="val" style="font-family:monospace;font-size:11px"><?=htmlspecialchars($promise['promise_number'])?></span></div>
    <?php if($promise['progress_percent']): ?>
    <div style="margin-top:8px">
      <div style="font-size:11px;color:var(--muted);margin-bottom:4px">Progression paiements</div>
      <div style="background:#e5e5e5;border-radius:4px;height:8px;overflow:hidden">
        <div style="height:100%;background:var(--brand);border-radius:4px;width:<?=min(100,(float)$promise['progress_percent'])?>%"></div>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-top:3px;text-align:right"><?=number_format((float)$promise['progress_percent'],0)?>%</div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="form-section">
      <h2>Actions</h2>
      <?php
      $nextActions = $STATUS_NEXT[$curStatus] ?? [];
      foreach($nextActions as $ns => $nl): ?>
      <button class="btn <?=$ns==='cancelled'?'btn-danger':'btn-brand'?>" style="margin-top:6px"
        onclick="changeStatus('<?=$ns?>')">
        <?=htmlspecialchars($nl)?>
      </button>
      <?php endforeach; ?>
      <?php if(!$hasPromise && in_array($curStatus, ['promise_draft','promise_signed','sold','payment_in_progress','paid'])): ?>
      <a class="btn btn-secondary" href="promise_view.php?new=1&dossier_id=<?=$id?>&client_id=<?=$d['client_id']?>&project_id=<?=$d['project_id']?>&unit_code=<?=urlencode($d['unit_code']??'')?>">
        + Créer une promesse
      </a>
      <?php elseif($hasPromise): ?>
      <a class="btn btn-secondary" href="promise_view.php?id=<?=(int)$promise['id']?>">
        Voir la promesse
      </a>
      <?php endif; ?>
      <a class="btn btn-secondary" href="fiche_client.php?id=<?=$d['client_id']?>">Fiche client</a>
    </div>

    <div class="form-section">
      <h2>Navigation</h2>
      <a class="nav-link" href="crm_dossiers.php">← Tous les dossiers</a>
      <a class="nav-link" href="crm_dashboard.php">Dashboard CRM</a>
      <a class="nav-link" href="crm_clients.php">Clients</a>
      <a class="nav-link" href="crm_pipeline.php">Pipeline</a>
      <a class="nav-link" href="crm_units.php">Biens</a>
      <?php if($d['client_id']): ?>
      <a class="nav-link" href="crm_fiche_client.php?id=<?=(int)$d['client_id']?>" style="color:var(--brand);font-weight:700">→ Fiche prospect</a>
      <?php endif; ?>
    </div>
  </aside>

  <!-- ══ CONTENU DROIT ══ -->
  <div>
    <div id="topFlash" class="flash"></div>

    <div class="tabs-wrap">
      <div class="tabs-nav">
        <button class="tab-btn active" onclick="switchTab('overview',this)">Vue d'ensemble</button>
        <button class="tab-btn" onclick="switchTab('promise',this)">Promesse</button>
        <button class="tab-btn" onclick="switchTab('schedule',this)">Échéancier</button>
        <button class="tab-btn" onclick="switchTab('payments',this)">Paiements</button>
        <button class="tab-btn" onclick="switchTab('activities',this)">Activités</button>
        <button class="tab-btn" onclick="switchTab('audit',this)">Audit</button>
        <button class="tab-btn" onclick="switchTab('edit',this)">Modifier</button>
      </div>
      <div class="tab-content">

        <!-- ─── TAB: VUE D'ENSEMBLE ─── -->
        <div id="tab-overview" class="tab-pane active">
          <!-- Pipeline visuel -->
          <?php
          $steps = ['interested','reserved','promise_draft','promise_signed','sold','payment_in_progress','paid','closed'];
          $stepLabels = ['Intéressé','Réservé','PV Brouillon','PV Signé','Vendu','Paiement','Soldé','Clôturé'];
          $passed = $curStatus !== 'cancelled';
          ?>
          <?php if($curStatus !== 'cancelled'): ?>
          <div class="status-bar">
            <?php foreach($steps as $i=>$s):
              $cls = '';
              $si = array_search($curStatus,$steps);
              if($s === $curStatus) $cls='current';
              elseif($si !== false && $i < $si) $cls='done';
            ?>
            <div class="sb-step <?=$cls?>"><?=htmlspecialchars($stepLabels[$i])?></div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div style="background:#fee2e2;color:#991b1b;border-radius:10px;padding:12px 16px;font-size:13px;font-weight:700;margin-bottom:16px">
            Ce dossier est annulé.
          </div>
          <?php endif; ?>

          <!-- KPI mini -->
          <div class="kpi-mini">
            <?php if($promise): ?>
            <div class="kmi">
              <div class="val"><?=$promise['sale_price'] ? number_format((float)$promise['sale_price']*(1+(float)($promise['vat_rate']??19)/100),0,',',' ') : '—'?></div>
              <div class="lbl">Prix TTC (TND)</div>
            </div>
            <div class="kmi">
              <div class="val ok"><?=$promise['paid_amount'] ? number_format((float)$promise['paid_amount'],0,',',' ') : '—'?></div>
              <div class="lbl">Encaissé (TND)</div>
            </div>
            <div class="kmi">
              <div class="val <?=(float)($promise['remaining_amount']??0)>0?'warn':'ok'?>"><?=$promise['remaining_amount'] ? number_format((float)$promise['remaining_amount'],0,',',' ') : '—'?></div>
              <div class="lbl">Restant (TND)</div>
            </div>
            <div class="kmi">
              <div class="val"><?=number_format((float)($promise['progress_percent']??0),0)?>%</div>
              <div class="lbl">Progression</div>
            </div>
            <?php else: ?>
            <div class="kmi"><div class="val">—</div><div class="lbl">Aucune promesse liée</div></div>
            <?php endif; ?>
          </div>

          <!-- Dates clés -->
          <div class="card">
            <h4>Dates clés</h4>
            <div class="info-row"><span class="lbl">Date de création</span><span class="val"><?=substr($d['created_at'],0,10)?></span></div>
            <?php if($d['reservation_date']): ?><div class="info-row"><span class="lbl">Date réservation</span><span class="val"><?=$d['reservation_date']?></span></div><?php endif; ?>
            <?php if($d['reservation_expires_at']): ?><div class="info-row"><span class="lbl">Expiration réservation</span><span class="val"><?=$d['reservation_expires_at']?></span></div><?php endif; ?>
            <?php if($promise && $promise['promise_date']): ?><div class="info-row"><span class="lbl">Date promesse</span><span class="val"><?=$promise['promise_date']?></span></div><?php endif; ?>
            <?php if($promise && $promise['signed_date']): ?><div class="info-row"><span class="lbl">Date signature</span><span class="val"><?=$promise['signed_date']?></span></div><?php endif; ?>
            <?php if($d['sold_date']): ?><div class="info-row"><span class="lbl">Date vente</span><span class="val"><?=$d['sold_date']?></span></div><?php endif; ?>
            <?php if($d['closed_date']): ?><div class="info-row"><span class="lbl">Date clôture</span><span class="val"><?=$d['closed_date']?></span></div><?php endif; ?>
          </div>

          <?php if($d['notes']): ?>
          <div class="card">
            <h4>Notes</h4>
            <p style="margin:0;font-size:13px;color:var(--muted);white-space:pre-wrap"><?=htmlspecialchars($d['notes'])?></p>
          </div>
          <?php endif; ?>
        </div>

        <!-- ─── TAB: PROMESSE ─── -->
        <div id="tab-promise" class="tab-pane">
          <?php if($hasPromise): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <div>
              <span style="font-family:monospace;font-size:14px;font-weight:700;color:var(--brand)"><?=htmlspecialchars($promise['promise_number'])?></span>
              <span class="badge b-<?=$promise['status']?>" style="margin-left:8px"><?=$promise['status']?></span>
            </div>
            <a class="btn btn-brand btn-sm" href="promise_view.php?id=<?=(int)$promise['id']?>">Ouvrir la promesse</a>
          </div>
          <div class="card">
            <h4>Résumé financier</h4>
            <?php
            $ht  = (float)$promise['sale_price'];
            $vat = (float)($promise['vat_rate']??19);
            $ttc = $ht * (1 + $vat/100);
            ?>
            <div class="info-row"><span class="lbl">Prix HT</span><span class="val amount"><?=number_format($ht,3,',','.')?> TND</span></div>
            <div class="info-row"><span class="lbl">TVA (<?=$vat?>%)</span><span class="val amount"><?=number_format($ttc-$ht,3,',','.')?> TND</span></div>
            <div class="info-row"><span class="lbl">Prix TTC</span><span class="val amount" style="color:var(--brand)"><?=number_format($ttc,3,',','.')?> TND</span></div>
            <div class="info-row"><span class="lbl">Avance</span><span class="val amount"><?=number_format((float)$promise['advance_amount'],3,',','.')?> TND</span></div>
            <div class="info-row"><span class="lbl">Encaissé</span><span class="val amount" style="color:#19733e"><?=number_format((float)($promise['paid_amount']??0),3,',','.')?> TND</span></div>
            <div class="info-row"><span class="lbl">Restant</span><span class="val amount" style="color:#c0392b"><?=number_format((float)($promise['remaining_amount']??0),3,',','.')?> TND</span></div>
          </div>
          <?php else: ?>
          <div style="text-align:center;padding:40px;color:var(--muted)">
            <p style="margin:0 0 16px;font-size:15px">Aucune promesse liée à ce dossier.</p>
            <a class="btn btn-brand" style="display:inline-block;width:auto;padding:10px 24px"
               href="promise_view.php?new=1&dossier_id=<?=$id?>&client_id=<?=$d['client_id']?>&project_id=<?=$d['project_id']?>&unit_code=<?=urlencode($d['unit_code']??'')?>">
              + Créer une promesse
            </a>
          </div>
          <?php endif; ?>
        </div>

        <!-- ─── TAB: ÉCHÉANCIER ─── -->
        <div id="tab-schedule" class="tab-pane">
          <?php if($hasPromise): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <span style="font-size:14px;font-weight:700">Échéancier de paiement</span>
            <a class="btn btn-brand btn-sm" href="promise_view.php?id=<?=(int)$promise['id']?>#schedule">Gérer l'échéancier</a>
          </div>
          <div id="scheduleContent">Chargement…</div>
          <?php else: ?>
          <div style="text-align:center;padding:40px;color:var(--muted)">Aucune promesse — créez d'abord une promesse de vente.</div>
          <?php endif; ?>
        </div>

        <!-- ─── TAB: PAIEMENTS ─── -->
        <div id="tab-payments" class="tab-pane">
          <?php if($hasPromise): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <span style="font-size:14px;font-weight:700">Historique des paiements</span>
            <button class="btn btn-brand btn-sm" onclick="openPayModal()">+ Ajouter un paiement</button>
          </div>
          <div id="paymentsContent">Chargement…</div>
          <?php else: ?>
          <div style="text-align:center;padding:40px;color:var(--muted)">Aucune promesse associée.</div>
          <?php endif; ?>
        </div>

        <!-- ─── TAB: ACTIVITÉS ─── -->
        <div id="tab-activities" class="tab-pane">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
            <span style="font-size:14px;font-weight:700">Journal d'activités</span>
          </div>
          <!-- Add activity -->
          <div class="card" style="margin-bottom:16px;padding:14px 16px">
            <!-- Boutons rapides -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
              <button class="btn btn-outline btn-sm" onclick="quickAct('call')">📞 Appel effectué</button>
              <button class="btn btn-outline btn-sm" onclick="quickAct('whatsapp')">📱 Message WhatsApp</button>
              <button class="btn btn-outline btn-sm" onclick="quickAct('meeting')">🗓 Planifier RDV</button>
              <button class="btn btn-outline btn-sm" onclick="quickAct('email')">✉ Email envoyé</button>
              <button class="btn btn-outline btn-sm" onclick="quickAct('note')">📝 Note interne</button>
            </div>
            <div id="actForm" style="display:none;border-top:1px solid #eee;padding-top:12px">
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px">
                <div>
                  <label style="font-size:12px;font-weight:700;color:#666;display:block;margin-bottom:4px">Type</label>
                  <select id="actType" class="sel" style="width:100%">
                    <?php foreach($ACTIVITY_LABELS as $v=>$l): ?>
                    <option value="<?=$v?>"><?=$l?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label style="font-size:12px;font-weight:700;color:#666;display:block;margin-bottom:4px">Date &amp; heure</label>
                  <input type="datetime-local" id="actDate" class="inp" style="width:100%" value="<?=date('Y-m-d\TH:i')?>">
                </div>
                <div>
                  <label style="font-size:12px;font-weight:700;color:#666;display:block;margin-bottom:4px">Planifier pour</label>
                  <input type="datetime-local" id="actDue" class="inp" style="width:100%" placeholder="(optionnel)">
                </div>
              </div>
              <div style="margin-bottom:10px">
                <label style="font-size:12px;font-weight:700;color:#666;display:block;margin-bottom:4px">Objet</label>
                <input type="text" id="actSubject" class="inp" style="width:100%" placeholder="Ex: Suivi S+2 Flava, Confirmation RDV…">
              </div>
              <div style="margin-bottom:10px">
                <label style="font-size:12px;font-weight:700;color:#666;display:block;margin-bottom:4px">Description / Notes</label>
                <textarea id="actDesc" class="inp" style="width:100%;height:70px;resize:vertical" placeholder="Détails, réponse client, prochaine étape…"></textarea>
              </div>
              <div style="display:flex;gap:8px">
                <button class="btn btn-brand btn-sm" onclick="saveActivity()">✓ Enregistrer</button>
                <button class="btn btn-outline btn-sm" onclick="document.getElementById('actForm').style.display='none'">Annuler</button>
              </div>
            </div>
          </div>
          <div id="activitiesContent">Chargement…</div>
        </div>

        <!-- ─── TAB: AUDIT ─── -->
        <div id="tab-audit" class="tab-pane">
          <div id="auditContent">Chargement…</div>
        </div>

        <!-- ─── TAB: MODIFIER ─── -->
        <div id="tab-edit" class="tab-pane">
          <div id="editFlash" class="flash"></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div>
              <label>Code unité</label>
              <input type="text" id="editUnit" value="<?=htmlspecialchars($d['unit_code']??'')?>">
            </div>
            <div>
              <label>Projet</label>
              <select id="editProject">
                <option value="">— Sélectionner —</option>
                <?php foreach($projects as $pj): ?>
                <option value="<?=(int)$pj['id']?>" <?=(int)$d['project_id']===(int)$pj['id']?'selected':''?>><?=htmlspecialchars($pj['code'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>Niveau d'intérêt</label>
              <select id="editInterest">
                <option value="low" <?=$d['interest_level']==='low'?'selected':''?>>Faible</option>
                <option value="medium" <?=$d['interest_level']==='medium'?'selected':''?>>Moyen</option>
                <option value="high" <?=$d['interest_level']==='high'?'selected':''?>>Élevé</option>
                <option value="hot" <?=$d['interest_level']==='hot'?'selected':''?>>Chaud</option>
              </select>
            </div>
            <div>
              <label>Budget (TND)</label>
              <input type="number" id="editBudget" value="<?=htmlspecialchars($d['budget']??'')?>">
            </div>
            <div>
              <label>Source</label>
              <input type="text" id="editSource" value="<?=htmlspecialchars($d['source']??'')?>">
            </div>
            <div>
              <label>Date réservation</label>
              <input type="date" id="editResDate" value="<?=htmlspecialchars($d['reservation_date']??'')?>">
            </div>
            <div>
              <label>Expiration réservation</label>
              <input type="date" id="editResExp" value="<?=htmlspecialchars($d['reservation_expires_at']??'')?>">
            </div>
            <?php if($users): ?>
            <div>
              <label>Assigné à</label>
              <select id="editAssigned">
                <option value="">— Non assigné —</option>
                <?php foreach($users as $u): ?>
                <option value="<?=(int)$u['id']?>" <?=(int)$d['assigned_to']===(int)$u['id']?'selected':''?>><?=htmlspecialchars($u['nom'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
          </div>
          <div style="margin-top:8px">
            <label>Notes</label>
            <textarea id="editNotes"><?=htmlspecialchars($d['notes']??'')?></textarea>
          </div>
          <button class="btn btn-brand" style="max-width:220px;margin-top:8px" onclick="saveDossier()">Enregistrer les modifications</button>
        </div>

      </div><!-- /tab-content -->
    </div><!-- /tabs-wrap -->
  </div>

</div>

<!-- ══ MODAL PAIEMENT ══ -->
<div class="overlay" id="payOverlay">
  <div class="modal">
    <h2>Enregistrer un paiement</h2>
    <div id="payFlash" class="flash"></div>
    <label>Montant (TND) *</label>
    <input type="number" id="payAmount" step="0.001" placeholder="0.000">
    <label>Date de paiement *</label>
    <input type="date" id="payDate" value="<?=date('Y-m-d')?>">
    <label>Mode de paiement</label>
    <select id="payMethod">
      <option value="virement">Virement</option>
      <option value="cheque">Chèque</option>
      <option value="especes">Espèces</option>
      <option value="traite">Traite</option>
    </select>
    <label>Banque / Référence</label>
    <input type="text" id="payBank" placeholder="Banque ou référence">
    <input type="text" id="payRef" placeholder="N° chèque, référence…">
    <label>Notes</label>
    <input type="text" id="payNotes" placeholder="Commentaire optionnel">
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closePayModal()">Annuler</button>
      <button class="btn btn-brand" onclick="submitPayment()">Enregistrer</button>
    </div>
  </div>
</div>

<script>
const DOSSIER_ID = <?=$id?>;
const PROMISE_ID = <?=$hasPromise?(int)$promise['id']:'null'?>;

function esc(s){ const d=document.createElement('div');d.textContent=s||'';return d.innerHTML; }
function fmt(n){ return n && parseFloat(n) > 0 ? parseFloat(n).toLocaleString('fr-FR',{minimumFractionDigits:3})+' TND' : '—'; }

// ── Tabs ─────────────────────────────────────────────────────────
const loaded = {};
function switchTab(name, btn) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('tab-'+name).classList.add('active');
  if (!loaded[name]) { lazyLoad(name); loaded[name]=true; }
}

function lazyLoad(name) {
  if (name === 'schedule' && PROMISE_ID) loadSchedule();
  if (name === 'payments'  && PROMISE_ID) loadPayments();
  if (name === 'activities') loadActivities();
  if (name === 'audit')      loadAudit();
}

// ── Schedule ─────────────────────────────────────────────────────
async function loadSchedule() {
  if (!PROMISE_ID) return;
  const res  = await fetch('api/promise_get.php?id=' + PROMISE_ID);
  const data = await res.json();
  const inst = data.installments || [];
  if (!inst.length) {
    document.getElementById('scheduleContent').innerHTML = '<div style="color:var(--muted);text-align:center;padding:30px">Aucun échéancier défini. <a href="promise_view.php?id='+PROMISE_ID+'">Créer l\'échéancier →</a></div>';
    return;
  }
  const SC = {pending:'b-pend',partial:'b-prog',paid:'b-paid',overdue:'b-over'};
  const SL2 = {pending:'En attente',partial:'Partiel',paid:'Payé',overdue:'En retard'};
  document.getElementById('scheduleContent').innerHTML = `
    <table>
      <thead><tr><th>#</th><th>Échéance</th><th>Montant</th><th>Payé</th><th>Restant</th><th>Statut</th></tr></thead>
      <tbody>${inst.map((r,i) => `<tr>
        <td>${i+1}</td>
        <td>${r.due_date||'—'}</td>
        <td class="amount">${fmt(r.amount)}</td>
        <td class="amount" style="color:#19733e">${fmt(r.paid_amount)}</td>
        <td class="amount" style="color:#c0392b">${fmt(r.remaining_amount)}</td>
        <td><span class="badge ${SC[r.status]||''}">${SL2[r.status]||r.status}</span></td>
      </tr>`).join('')}</tbody>
    </table>`;
}

// ── Payments ─────────────────────────────────────────────────────
async function loadPayments() {
  if (!PROMISE_ID) return;
  const res  = await fetch('api/payments_list.php?promise_id='+PROMISE_ID);
  const data = await res.json();
  const pays = data.payments || [];
  const el   = document.getElementById('paymentsContent');
  if (!pays.length) { el.innerHTML = '<div style="color:var(--muted);text-align:center;padding:30px">Aucun paiement enregistré.</div>'; return; }
  el.innerHTML = `<p style="font-size:13px;color:var(--muted);margin:0 0 10px">Total encaissé: <strong style="color:#19733e">${fmt(data.total_paid)}</strong></p>
    <table>
      <thead><tr><th>Date</th><th>Montant</th><th>Mode</th><th>Référence</th><th>Enregistré par</th></tr></thead>
      <tbody>${pays.map(p => `<tr>
        <td>${p.payment_date||'—'}</td>
        <td class="amount" style="color:#19733e">${fmt(p.amount)}</td>
        <td>${esc(p.payment_method||'—')}</td>
        <td style="font-size:12px;color:var(--muted)">${esc(p.reference_number||p.bank_name||'—')}</td>
        <td style="font-size:12px;color:var(--muted)">${esc(p.user_nom||'—')}</td>
      </tr>`).join('')}</tbody>
    </table>`;
}

// ── Activities ────────────────────────────────────────────────────
async function loadActivities() {
  const res  = await fetch('api/crm_dossier_get.php?id='+DOSSIER_ID);
  const data = await res.json();
  const acts = data.activities || [];
  const el   = document.getElementById('activitiesContent');
  const ATYPE_LABELS = {note:'Note',call:'Appel',whatsapp:'WhatsApp',meeting:'RDV',email:'Email',visit:'Visite',task:'Tâche'};
  const icons = {note:'📝',call:'📞',whatsapp:'📱',meeting:'🤝',email:'📧',visit:'🏠',task:'✅'};
  if (!acts.length) { el.innerHTML = '<div style="color:var(--muted);text-align:center;padding:30px">Aucune activité enregistrée.</div>'; return; }
  el.innerHTML = acts.map(a => `
    <div class="act-item">
      <div class="act-icon">${icons[a.activity_type]||'📌'}</div>
      <div class="act-body">
        <div class="act-type">${esc(ATYPE_LABELS[a.activity_type]||a.activity_type)}</div>
        ${a.subject ? `<div style="font-size:13px;font-weight:700;margin:2px 0">${esc(a.subject)}</div>` : ''}
        ${a.description ? `<div class="act-desc">${esc(a.description)}</div>` : ''}
        <div class="act-meta">${esc(a.activity_date||'')} — ${esc(a.user_nom||'?')}</div>
      </div>
    </div>`).join('');
}

function quickAct(type) {
  const sel = document.getElementById('actType');
  if (sel) sel.value = type;
  const now = new Date();
  const pad = n => String(n).padStart(2,'0');
  const local = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
  const dateEl = document.getElementById('actDate');
  if (dateEl && !dateEl.value) dateEl.value = local;
  document.getElementById('actForm').style.display = 'block';
  document.getElementById('actSubject').focus();
}

async function saveActivity() {
  const subject = document.getElementById('actSubject').value.trim();
  const desc    = document.getElementById('actDesc').value.trim();
  if (!subject && !desc) { alert('Veuillez saisir un objet ou une description'); return; }
  const body = {
    dossier_id:    DOSSIER_ID,
    activity_type: document.getElementById('actType').value,
    subject:       subject,
    description:   desc,
    activity_date: document.getElementById('actDate').value || new Date().toISOString().slice(0,16),
    due_date:      document.getElementById('actDue').value || '',
  };
  const res  = await fetch('api/crm_activity_save.php', {method:'POST', body:JSON.stringify(body)});
  const data = await res.json();
  if (data.error) { alert(data.error); return; }
  document.getElementById('actSubject').value = '';
  document.getElementById('actDesc').value    = '';
  document.getElementById('actDue').value     = '';
  document.getElementById('actForm').style.display = 'none';
  loaded['activities'] = false;
  loadActivities();
  loaded['activities'] = true;
}

// ── Audit ─────────────────────────────────────────────────────────
async function loadAudit() {
  const res  = await fetch('api/crm_dossier_get.php?id='+DOSSIER_ID);
  const data = await res.json();
  const logs = data.audits || [];
  const el   = document.getElementById('auditContent');
  if (!logs.length) { el.innerHTML = '<div style="color:var(--muted);text-align:center;padding:30px">Aucun événement d\'audit.</div>'; return; }
  el.innerHTML = `<table>
    <thead><tr><th>Date</th><th>Action</th><th>Utilisateur</th><th>Détails</th></tr></thead>
    <tbody>${logs.map(l => `<tr>
      <td style="font-size:12px;color:var(--muted)">${(l.created_at||'').substring(0,16)}</td>
      <td><span class="badge b-promise_draft">${esc(l.action)}</span></td>
      <td style="font-size:12px">${esc(l.user_nom||'—')}</td>
      <td style="font-size:11px;color:var(--muted)">${l.new_value ? esc(l.new_value).substring(0,80) : '—'}</td>
    </tr>`).join('')}</tbody>
  </table>`;
}

// ── Status transition ─────────────────────────────────────────────
async function changeStatus(newStatus) {
  if (!confirm('Confirmer le changement de statut ?')) return;
  const body = {
    id:         DOSSIER_ID,
    client_id:  <?=(int)$d['client_id']?>,
    status:     newStatus,
  };
  const res  = await fetch('api/crm_dossier_save.php', {method:'POST', body:JSON.stringify(body)});
  const data = await res.json();
  if (data.error) { showFlash(data.error,'err'); return; }
  showFlash('Statut mis à jour. Rechargement…','ok');
  setTimeout(()=>location.reload(), 1200);
}

// ── Edit dossier ──────────────────────────────────────────────────
async function saveDossier() {
  const body = {
    id:                     DOSSIER_ID,
    client_id:              <?=(int)$d['client_id']?>,
    project_id:             parseInt(document.getElementById('editProject').value)||null,
    unit_code:              document.getElementById('editUnit').value.trim()||null,
    interest_level:         document.getElementById('editInterest').value,
    budget:                 parseFloat(document.getElementById('editBudget').value)||null,
    source:                 document.getElementById('editSource').value.trim()||null,
    reservation_date:       document.getElementById('editResDate').value||null,
    reservation_expires_at: document.getElementById('editResExp').value||null,
    notes:                  document.getElementById('editNotes').value.trim()||null,
    assigned_to:            parseInt(document.getElementById('editAssigned')?.value)||null,
  };
  const res  = await fetch('api/crm_dossier_save.php', {method:'POST', body:JSON.stringify(body)});
  const data = await res.json();
  if (data.error) { showEditFlash(data.error,'err'); return; }
  showEditFlash('Dossier mis à jour avec succès.','ok');
  setTimeout(()=>location.reload(), 1500);
}

// ── Payment modal ─────────────────────────────────────────────────
function openPayModal()  { document.getElementById('payOverlay').classList.add('open'); }
function closePayModal() { document.getElementById('payOverlay').classList.remove('open'); }
document.getElementById('payOverlay').addEventListener('click', e=>{ if(e.target===document.getElementById('payOverlay')) closePayModal(); });

async function submitPayment() {
  const amount = parseFloat(document.getElementById('payAmount').value)||0;
  if (!amount) { showPayFlash('Le montant est obligatoire.','err'); return; }
  const body = {
    promise_id:     PROMISE_ID,
    amount:         amount,
    payment_date:   document.getElementById('payDate').value,
    payment_method: document.getElementById('payMethod').value,
    bank_name:      document.getElementById('payBank').value.trim()||null,
    reference_number:document.getElementById('payRef').value.trim()||null,
    notes:          document.getElementById('payNotes').value.trim()||null,
  };
  const res  = await fetch('api/payment_add.php', {method:'POST', body:JSON.stringify(body)});
  const data = await res.json();
  if (data.error) { showPayFlash(data.error,'err'); return; }
  closePayModal();
  showFlash('Paiement enregistré avec succès.','ok');
  loaded['payments'] = false;
  loadPayments();
  loaded['payments'] = true;
}

// ── Flash helpers ──────────────────────────────────────────────────
function showFlash(msg, type) {
  const el = document.getElementById('topFlash');
  el.textContent = msg; el.className = 'flash '+type; el.style.display='block';
  setTimeout(()=>{ el.style.display='none'; }, 4000);
}
function showEditFlash(msg, type) {
  const el = document.getElementById('editFlash');
  el.textContent = msg; el.className = 'flash '+type; el.style.display='block';
}
function showPayFlash(msg, type) {
  const el = document.getElementById('payFlash');
  el.textContent = msg; el.className = 'flash '+type; el.style.display='block';
}
</script>
</body>
</html>
