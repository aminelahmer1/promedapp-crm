<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$projects = $pdo->query("SELECT id,code,nom FROM projects ORDER BY code")->fetchAll();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Dashboard CRM – PromedApp</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php include __DIR__.'/includes/crm_style.php'; ?>
</head>
<body>
<div class="app">

<!-- ══ PANEL GAUCHE ══ -->
<aside class="panel">
  <div class="panel-title">CRM Promed</div>
  <div class="panel-sub">Suivi commercial</div>

  <div class="nav-section">
    <div class="nav-section-title">CRM</div>
    <a class="nav-link active" href="crm_dashboard.php">⊟ Tableau de bord</a>
    <a class="nav-link" href="crm_clients.php">Clients / Prospects</a>
  </div>

  <div class="nav-section">
    <div class="nav-section-title">Vente</div>
    <a class="nav-link" href="promises.php">Promesses de vente</a>
    <a class="nav-link" href="fiche_client.php">Nouvelle fiche client</a>
  </div>

  <div class="nav-section">
    <div class="nav-section-title">Projets</div>
    <?php foreach($projects as $pj): ?>
    <a class="nav-link" href="dashboard_<?=strtolower($pj['code'])?>.php"><?=htmlspecialchars($pj['code'])?></a>
    <?php endforeach; ?>
  </div>

  <div class="nav-section">
    <div class="nav-section-title">Actions rapides</div>
    <a class="nav-link" href="crm_clients.php" onclick="event.preventDefault();window.location='crm_clients.php#new'">+ Nouveau client</a>
    <a class="nav-link" href="fiche_client.php">+ Nouvelle promesse</a>
  </div>
</aside>

<!-- ══ CONTENU ══ -->
<div>

  <!-- KPI -->
  <div class="kpi-grid" id="kpiGrid">
    <div class="kpi"><div class="kval"><span class="spin"></span></div><div class="klbl">Chargement…</div></div>
  </div>

  <!-- À faire + Alertes -->
  <div class="row-2">
    <div class="card">
      <div class="card-head">
        <h3>À faire aujourd'hui <span class="cnt-badge cb-grey" id="todoBadge">0</span></h3>
      </div>
      <div id="todoBody"><div class="empty"><span class="spin"></span></div></div>
    </div>
    <div class="card">
      <div class="card-head">
        <h3>Alertes commerciales <span class="cnt-badge cb-grey" id="alertsBadge">0</span></h3>
      </div>
      <div id="alertsBody"><div class="empty"><span class="spin"></span></div></div>
    </div>
  </div>

  <!-- Pipeline -->
  <div class="pipe-box">
    <h3>Pipeline commercial — cliquez pour filtrer</h3>
    <div class="pipe-row" id="pipeRow"><span class="spin"></span></div>
  </div>

  <!-- Suivi financier -->
  <div class="row-4" id="finRow">
    <div class="kpi"><div class="klbl">Chargement…</div></div>
  </div>

  <!-- Clients chauds + Visites + Top projets -->
  <div class="row-3" id="extraBlocks">
    <div class="card">
      <div class="card-head"><h3>Clients chauds <span id="hotBadge" class="cnt-badge cb-warn">…</span></h3><a href="crm_clients.php?priority=hot">Voir tous →</a></div>
      <div id="hotClientsBody"><div class="empty"><span class="spin"></span></div></div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Visites du jour <span id="visitsBadge" class="cnt-badge cb-grey">…</span></h3><a href="crm_clients.php?status=visit">Voir tous →</a></div>
      <div id="visitsBody"><div class="empty"><span class="spin"></span></div></div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Top projets</h3></div>
      <div id="topProjectsBody"><div class="empty"><span class="spin"></span></div></div>
    </div>
  </div>

  <!-- Dossiers récents -->
  <div class="card">
    <div class="card-head">
      <h3>Dossiers récents</h3>
      <a href="crm_dossiers.php">Voir tous →</a>
    </div>
    <div style="overflow-x:auto">
      <table>
        <thead><tr>
          <th>N° Dossier</th><th>Client</th><th>Téléphone</th>
          <th>Projet · Unité</th><th>Statut</th><th>Priorité</th>
          <th>Prix</th><th>Payé</th><th>Reste</th>
          <th>Commercial</th><th>Date</th>
        </tr></thead>
        <tbody id="recentBody">
          <tr><td colspan="11" class="empty"><span class="spin"></span></td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div>
</div>

<script>
<?php include __DIR__.'/includes/crm_js_helpers.php'; ?>

const PIPE=[
  {key:'interested',    cls:'ps-interested', lbl:'Intéressés'},
  {key:'reserved',      cls:'ps-reserved',   lbl:'Réservés'},
  {key:'promise_draft', cls:'ps-draft',       lbl:'PV Brouillon'},
  {key:'promise_signed',cls:'ps-signed',      lbl:'PV Signés'},
  {key:'sold',          cls:'ps-sold',        lbl:'Vendus'},
  {key:'payment_in_progress',cls:'ps-progress',lbl:'Paiement'},
  {key:'paid',          cls:'ps-paid',        lbl:'Soldés'},
  {key:'closed',        cls:'ps-closed',      lbl:'Clôturés'},
  {key:'cancelled',     cls:'ps-cancelled',   lbl:'Annulés'},
];

async function loadStats(){
  try{
    const d = await fetch('api/crm_dashboard_stats.php').then(r=>r.json());
    if(d.error){ console.error(d.error); return; }

    // KPI
    const fin=d.financial||{};
    document.getElementById('kpiGrid').innerHTML=`
      <div class="kpi"><div class="kval">${(d.total_active||0).toLocaleString('fr-FR')}</div><div class="klbl">Dossiers actifs</div><div class="ksub">${d.by_status?.interested||0} intéressés</div></div>
      <div class="kpi"><div class="kval gold">${(d.by_status?.promise_signed||0).toLocaleString('fr-FR')}</div><div class="klbl">Promesses signées</div><div class="ksub">${d.signed_this_month||0} ce mois</div></div>
      <div class="kpi"><div class="kval ok">${fmt(fin.month_paid)}</div><div class="klbl">Encaissé ce mois</div><div class="ksub">Prévu : ${fmt(fin.month_due)}</div></div>
      <div class="kpi"><div class="kval ${(fin.overdue_count||0)>0?'warn':'ok'}">${fin.overdue_count||0}</div><div class="klbl">Échéances en retard</div><div class="ksub">${fmt(fin.overdue_amount)}</div></div>
      <div class="kpi"><div class="kval gold">${fmtK(d.pipeline_value||0)}</div><div class="klbl">Pipeline total</div></div>`;

    // Todo
    const todo=d.todo||{};
    const tb=document.getElementById('todoBadge');
    tb.textContent=todo.total||0;
    tb.className='cnt-badge '+((todo.total||0)>0?'cb-warn':'cb-grey');
    document.getElementById('todoBody').innerHTML=
      `<div class="td-counts">
        <div class="tdc"><div class="tdc-n">${todo.calls||0}</div><div class="tdc-l">📞 Appels</div></div>
        <div class="tdc"><div class="tdc-n">${todo.whatsapp||0}</div><div class="tdc-l">📱 WhatsApp</div></div>
        <div class="tdc"><div class="tdc-n">${todo.meetings||0}</div><div class="tdc-l">🗓 RDV</div></div>
        <div class="tdc"><div class="tdc-n">${todo.emails||0}</div><div class="tdc-l">✉ Emails</div></div>
      </div>`+
      ((todo.items||[]).length
        ? `<div class="td-list">${(todo.items).map(a=>`
            <div class="td-item">
              <span class="td-tag tt-${a.activity_type||'task'}">${ACT[a.activity_type]||a.activity_type}</span>
              <div><div class="td-name">${esc(a.client_nom||'—')} <span class="t-muted">${esc(a.dossier_number||'')}</span></div>
              <div class="td-sub">${esc(a.subject||'')}${a.due_date?' · '+(a.due_date+'').substring(11,16):''}</div></div>
            </div>`).join('')}</div>`
        : `<div class="empty">✓ Aucune tâche prévue aujourd'hui</div>`);

    // Alertes
    const al=d.alerts||{};
    const ab=document.getElementById('alertsBadge');
    ab.textContent=al.total||0;
    ab.className='cnt-badge '+((al.total||0)>0?'cb-warn':'cb-grey');
    document.getElementById('alertsBody').innerHTML=[
      {l:'Réservations expirent < 3j',   n:al.expiring?.length||0, dot:'red',    url:'crm_dossiers.php?status=reserved'},
      {l:'Promesses brouillon > 7j',      n:al.draft_promises||0,   dot:'orange', url:'crm_dossiers.php?status=promise_draft'},
      {l:'Promesses signées non vérouillées', n:al.signed_unlocked||0, dot:'orange', url:'crm_dossiers.php?status=promise_signed'},
      {l:'Vendus sans échéancier',         n:al.sold_no_schedule||0, dot:'red',    url:'crm_dossiers.php?status=sold'},
      {l:'Clients chauds sans suivi',      n:al.hot_no_followup||0,  dot:'red',    url:'crm_clients.php'},
    ].map(r=>`<a class="al-row" href="${r.url}">
      <div class="al-left"><div class="al-dot al-${r.n>0?r.dot:'green'}"></div><div class="al-text">${r.l}</div></div>
      <div class="al-n ${r.n>0?'red':'ok'}">${r.n}</div>
    </a>`).join('');

    // Pipeline
    const by=d.by_status||{};
    document.getElementById('pipeRow').innerHTML=PIPE.map(s=>`
      <div class="pipe-step ${s.cls}" onclick="location='crm_dossiers.php?status=${s.key}'">
        <div class="ps-n">${by[s.key]||0}</div><div class="ps-l">${s.lbl}</div>
      </div>`).join('');

    // Financial
    const pct=fin.month_due>0?Math.round(fin.month_paid/fin.month_due*100)+'%':'—';
    document.getElementById('finRow').innerHTML=`
      <div class="kpi" style="border-top:3px solid var(--ok)"><div class="kval ok">${fmt(fin.month_paid)}</div><div class="klbl">Encaissé ce mois</div><div class="ksub">${pct} du prévu</div></div>
      <div class="kpi" style="border-top:3px solid var(--brand)"><div class="kval">${fmt(fin.month_due)}</div><div class="klbl">Attendu ce mois</div></div>
      <div class="kpi" style="border-top:3px solid var(--err)"><div class="kval ${(fin.overdue_count||0)>0?'warn':'ok'}">${fin.overdue_count||0} retard${(fin.overdue_count||0)>1?'s':''}</div><div class="klbl">Échéances en retard</div><div class="ksub">${fmt(fin.overdue_amount)}</div></div>
      <div class="kpi" style="border-top:3px solid var(--gold)"><div class="kval gold">${fmtK(fin.total_remaining)}</div><div class="klbl">Reste à encaisser</div></div>`;

    // Récents
    const rows=d.recent||[];
    document.getElementById('recentBody').innerHTML=rows.length
      ? rows.map(r=>`<tr onclick="location='crm_dossier.php?id=${r.id}'">
          <td><span class="tnum">${esc(r.dossier_number||'—')}</span></td>
          <td style="font-weight:600">${esc(r.client_nom||'—')}</td>
          <td class="t-muted">${esc(r.client_tel||'—')}</td>
          <td><div style="font-weight:600;font-size:12px">${esc(r.unit_code||'—')}</div><div class="t-muted" style="font-size:11px">${esc(r.project_code||'')}</div></td>
          <td>${sbadge(r.status)}</td>
          <td>${pbadge(r.interest_level)}</td>
          <td style="font-size:12px;font-weight:600">${fmt(r.total_price)}</td>
          <td style="font-size:12px;color:var(--ok)">${fmt(r.paid_amount)}</td>
          <td style="font-size:12px;color:${parseFloat(r.remaining_amount||0)>0?'var(--err)':'var(--muted)'}">${fmt(r.remaining_amount)}</td>
          <td class="t-muted">${esc(r.assigned_nom||'—')}</td>
          <td class="t-muted" style="font-size:11px">${(r.created_at||'').substring(0,10)}</td>
        </tr>`).join('')
      : '<tr><td colspan="11" class="empty">Aucun dossier</td></tr>';

    // Hot clients
    const hot = d.hot_clients||[];
    const ILbl = {hot:'Très chaud',vip:'VIP',high:'Chaud'};
    document.getElementById('hotBadge').textContent = hot.length;
    document.getElementById('hotClientsBody').innerHTML = hot.length
      ? hot.map(c=>`<a href="crm_fiche_client.php?id=${c.id}" class="al-row">
          <div class="al-left"><div class="al-dot al-red"></div>
          <div><div class="al-text" style="font-weight:700">${esc(c.nom)}</div>
          <div style="font-size:11px;color:var(--muted)">${esc(c.tel||'')} · ${esc(ILbl[c.interest_level]||c.interest_level)}</div></div></div>
          ${pbadge(c.interest_level)}
        </a>`).join('')
      : '<div class="empty">Aucun client chaud</div>';

    // Visits today
    const vis = d.visits_today||[];
    document.getElementById('visitsBadge').textContent = vis.length;
    document.getElementById('visitsBadge').className = 'cnt-badge ' + (vis.length>0?'cb-warn':'cb-grey');
    document.getElementById('visitsBody').innerHTML = vis.length
      ? vis.map(v=>`<a href="crm_fiche_client.php?id=${v.client_id}" class="al-row">
          <div class="al-left"><div class="al-dot al-orange"></div>
          <div><div class="al-text" style="font-weight:700">${esc(v.nom||'—')}</div>
          <div style="font-size:11px;color:var(--muted)">${v.visit_time?esc(v.visit_time.substring(0,5)):''} · ${esc(v.project_code||'')} ${esc(v.unit_code||'')} · ${esc(v.commercial_nom||'')}</div></div></div>
        </a>`).join('')
      : '<div class="empty">Aucune visite planifiée aujourd\'hui</div>';

    // Top projects
    const tp = d.top_projects||[];
    document.getElementById('topProjectsBody').innerHTML = tp.length
      ? tp.map((p,i)=>`<div class="al-row" style="cursor:default">
          <div class="al-left"><div class="al-dot" style="background:${['var(--brand)','var(--gold)','var(--ok)','var(--warn)','#7c3aed'][i%5]}"></div>
          <div class="al-text"><strong>${esc(p.project_code)}</strong> — ${esc(p.project_nom||'')}</div></div>
          <div class="al-n">${p.cnt}</div>
        </div>`).join('')
      : '<div class="empty">Aucune donnée</div>';

  }catch(e){console.error(e);}
}
loadStats();
</script>
</body></html>
