<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Clients – PromedApp</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--bg:#f4f4f4;--paper:#fff;--line:#bdbdbd;--text:#151515;--muted:#5e5e5e;--soft:#efefef;--brand:#145f7d;--brand2:#a56d2d}
  *{box-sizing:border-box} html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif} body{padding:24px}
  .app{display:grid;grid-template-columns:300px 1fr;gap:24px;align-items:start;max-width:1600px;margin:0 auto}
  .panel{background:#fff;border:1px solid #ddd;border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.05);position:sticky;top:18px;max-height:calc(100vh - 36px);overflow:auto}
  .panel h1{font-size:20px;margin:0 0 4px}
  .panel .sub{color:var(--muted);font-size:13px;margin-bottom:16px}
  .form-section{border-top:1px solid #e5e5e5;padding-top:14px;margin-top:14px}
  .form-section h2{font-size:14px;margin:0 0 10px;text-transform:uppercase;letter-spacing:.4px}
  label{display:block;font-size:12px;font-weight:700;color:#202020;margin-bottom:4px}
  input,select,button{font:inherit}
  input,select{width:100%;border:1px solid #d7d7d7;border-radius:10px;padding:10px 12px;background:#fff;font-size:13px}
  input:focus,select:focus{outline:none;border-color:var(--brand)}
  .btn{border:0;border-radius:12px;padding:12px 14px;font-weight:700;cursor:pointer;font-size:13px;width:100%;margin-top:8px;display:block;text-align:center;text-decoration:none}
  .btn-brand{background:var(--brand);color:#fff}
  .btn-secondary{background:var(--soft);color:var(--text)}
  .btn-sm{padding:6px 12px;font-size:12px;border-radius:8px;width:auto;display:inline-block}
  .nav-link{display:block;color:var(--brand);font-size:13px;text-decoration:none;padding:6px 0;border-bottom:1px solid #f0f0f0}
  .nav-link:hover{text-decoration:underline}
  .nav-link:last-child{border:none}
  .stat-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}
  .stat{background:var(--soft);border-radius:10px;padding:10px 12px}
  .stat .val{font-size:18px;font-weight:800;color:var(--brand)}
  .stat .lbl{font-size:11px;color:var(--muted);margin-top:1px}

  /* Right */
  .right{display:flex;flex-direction:column;gap:0}
  .table-card{background:#fff;border:1px solid #ddd;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.05);overflow:hidden}
  table{width:100%;border-collapse:collapse}
  thead th{background:#f8f8f8;padding:11px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #ebebeb}
  tbody tr{border-bottom:1px solid #f0f0f0;cursor:pointer;transition:background .1s}
  tbody tr:hover{background:#f5fbfd}
  tbody td{padding:11px 14px;font-size:13px;vertical-align:middle}
  .avatar{width:34px;height:34px;border-radius:50%;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0}
  .ccell{display:flex;align-items:center;gap:10px}
  .cname{font-weight:700}
  .csub{font-size:11px;color:var(--muted);margin-top:1px}
  .badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700}
  .b-draft{background:#f0f0f0;color:#555}
  .b-signed{background:#d1fae5;color:#065f46}
  .b-conv{background:#dbeafe;color:#1e40af}
  .b-cancel{background:#fee2e2;color:#991b1b}
  .cnt-badge{display:inline-block;background:#eef4f8;color:var(--brand);border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700}
  .amount{font-family:monospace;font-size:12px}
  .pager{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid #f0f0f0;font-size:12px;color:var(--muted)}
  .pager-btns{display:flex;gap:6px}
  .pager-btns button{padding:5px 12px;border:1px solid #d7d7d7;border-radius:8px;background:#fff;cursor:pointer;font-size:12px;font-family:inherit;font-weight:700}
  .pager-btns button:hover:not(:disabled){background:var(--soft)}
  .pager-btns button:disabled{opacity:.4;cursor:default}
  @media(max-width:1100px){.app{grid-template-columns:1fr}.panel{position:relative;top:auto;max-height:none}}
</style>
</head>
<body>
<div class="app">

  <!-- ══ PANEL GAUCHE ══ -->
  <aside class="panel">
    <h1>Dossiers Clients</h1>
    <p class="sub">Recherche et gestion des clients</p>

    <div class="form-section">
      <h2>Navigation</h2>
      <a class="nav-link" href="select_project.php">← Retour aux projets</a>
      <a class="nav-link" href="promises.php">Promesses de vente</a>
      <a class="nav-link" href="promise_view.php?new=1">+ Nouvelle promesse</a>
    </div>

    <div class="form-section">
      <h2>Recherche</h2>
      <div>
        <label>Nom, téléphone, email, CIN</label>
        <input type="text" id="searchInput" placeholder="Rechercher…" autocomplete="off">
      </div>
      <button class="btn btn-brand" onclick="doSearch()">Rechercher</button>
      <a href="fiche_client.php" class="btn btn-secondary">+ Nouveau client</a>
    </div>

    <div class="form-section">
      <h2>Statistiques</h2>
      <div class="stat-row">
        <div class="stat"><div class="val" id="stat-total">—</div><div class="lbl">Total clients</div></div>
        <div class="stat"><div class="val" id="stat-active">—</div><div class="lbl">Avec promesse</div></div>
        <div class="stat"><div class="val" id="stat-paid">—</div><div class="lbl">Encaissé</div></div>
        <div class="stat"><div class="val" id="stat-remain">—</div><div class="lbl">Restant</div></div>
      </div>
    </div>
  </aside>

  <!-- ══ CONTENU DROIT ══ -->
  <div class="right">
    <div class="table-card">
      <table>
        <thead>
          <tr>
            <th>Client</th>
            <th>CIN / Passeport</th>
            <th>Téléphone</th>
            <th style="text-align:center">Promesses</th>
            <th>Encaissé</th>
            <th>Restant</th>
            <th>Statut</th>
          </tr>
        </thead>
        <tbody id="clientsBody">
          <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted)">Chargement…</td></tr>
        </tbody>
      </table>
      <div class="pager">
        <span id="pageInfo"></span>
        <div class="pager-btns">
          <button id="btnPrev" onclick="goPage(-1)" disabled>← Précédent</button>
          <button id="btnNext" onclick="goPage(1)">Suivant →</button>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
const LIMIT = 30;
let offset = 0, total = 0;
const SL = {draft:'Brouillon',signed:'Signé',converted_to_sale:'Converti',cancelled:'Annulé'};
const SC = {draft:'b-draft',signed:'b-signed',converted_to_sale:'b-conv',cancelled:'b-cancel'};

function fmt(n){ return n && n > 0 ? Math.round(n).toLocaleString('fr-FR')+' TND' : '—'; }
function initials(s){ return (s||'?').trim().substring(0,2).toUpperCase(); }
function esc(s){ const d=document.createElement('div');d.textContent=s||'';return d.innerHTML; }

async function loadClients(){
  const q = document.getElementById('searchInput').value.trim();
  const res = await fetch('api/clients_list.php?q='+encodeURIComponent(q)+'&limit='+LIMIT+'&offset='+offset);
  const data = await res.json();
  if(data.error){ alert(data.error); return; }
  total = data.total;
  const clients = data.clients||[];

  document.getElementById('stat-total').textContent  = total.toLocaleString('fr-FR');
  document.getElementById('stat-active').textContent = clients.filter(c=>c.promise_count>0).length;
  const tp = clients.reduce((s,c)=>s+(parseFloat(c.total_paid)||0),0);
  const tr = clients.reduce((s,c)=>s+(parseFloat(c.total_remaining)||0),0);
  document.getElementById('stat-paid').textContent   = tp ? Math.round(tp).toLocaleString('fr-FR')+' TND' : '—';
  document.getElementById('stat-remain').textContent = tr ? Math.round(tr).toLocaleString('fr-FR')+' TND' : '—';

  const tbody = document.getElementById('clientsBody');
  if(!clients.length){
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted)">Aucun client trouvé</td></tr>';
    return;
  }
  tbody.innerHTML = clients.map(c => {
    const cnt = parseInt(c.promise_count)||0;
    const st  = c.last_promise_status;
    return `<tr onclick="window.location='fiche_client.php?id=${c.id}'">
      <td><div class="ccell"><div class="avatar">${initials(c.nom)}</div><div>
        <div class="cname">${esc(c.nom)}</div>
        <div class="csub">${esc(c.email||c.tel||'')}</div>
      </div></div></td>
      <td style="color:var(--muted)">${esc(c.cin||'—')}</td>
      <td>${esc(c.tel||'—')}</td>
      <td style="text-align:center">${cnt>0?`<span class="cnt-badge">${cnt}</span>`:'—'}</td>
      <td class="amount" style="color:#19733e">${fmt(c.total_paid)}</td>
      <td class="amount" style="color:${parseFloat(c.total_remaining)>0?'#c0392b':'var(--muted)'}">${fmt(c.total_remaining)}</td>
      <td>${st?`<span class="badge ${SC[st]||'b-draft'}">${SL[st]||st}</span>`:'—'}</td>
    </tr>`;
  }).join('');

  const pages = Math.max(1,Math.ceil(total/LIMIT));
  const cur   = Math.floor(offset/LIMIT)+1;
  document.getElementById('pageInfo').textContent = `Page ${cur}/${pages} — ${total} client${total>1?'s':''}`;
  document.getElementById('btnPrev').disabled = offset===0;
  document.getElementById('btnNext').disabled = offset+LIMIT>=total;
}

function doSearch(){ offset=0; loadClients(); }
function goPage(dir){ offset=Math.max(0,offset+dir*LIMIT); loadClients(); }
document.getElementById('searchInput').addEventListener('keydown', e=>{ if(e.key==='Enter') doSearch(); });
loadClients();
</script>
</body>
</html>
