<?php
// DEPRECATED — remplacé par promises.php (liste des promesses de vente)
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
header('Location: promises.php');
exit;

try {
    $users = $pdo->query("SELECT id,full_name AS nom FROM users ORDER BY full_name")->fetchAll();
} catch (Throwable $e) { $users = []; }

$initStatus  = htmlspecialchars($_GET['status']  ?? '');
$initProject = htmlspecialchars($_GET['project'] ?? '');
$openNew     = !empty($_GET['new']) ? 'true' : 'false';

$SL = [
  'interested'=>'Intéressé','reserved'=>'Réservé','promise_draft'=>'Promesse brouillon',
  'promise_signed'=>'Promesse signée','sold'=>'Vendu','payment_in_progress'=>'Paiement en cours',
  'paid'=>'Soldé','closed'=>'Clôturé','cancelled'=>'Annulé'
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Dossiers CRM – PromedApp</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--bg:#f4f4f4;--paper:#fff;--line:#bdbdbd;--text:#151515;--muted:#5e5e5e;--soft:#efefef;--brand:#145f7d;--brand2:#a56d2d}
  *{box-sizing:border-box} html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif} body{padding:24px}
  .app{display:grid;grid-template-columns:280px 1fr;gap:24px;align-items:start;max-width:1600px;margin:0 auto}
  .panel{background:#fff;border:1px solid #ddd;border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.05);position:sticky;top:18px;max-height:calc(100vh - 36px);overflow:auto}
  .panel h1{font-size:20px;margin:0 0 4px}
  .panel .sub{color:var(--muted);font-size:13px;margin-bottom:16px}
  .form-section{border-top:1px solid #e5e5e5;padding-top:14px;margin-top:14px}
  .form-section h2{font-size:14px;margin:0 0 10px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted)}
  label{display:block;font-size:12px;font-weight:700;color:#202020;margin-bottom:4px}
  input,select,button{font:inherit}
  input,select{width:100%;border:1px solid #d7d7d7;border-radius:10px;padding:10px 12px;background:#fff;font-size:13px;margin-bottom:8px}
  input:focus,select:focus{outline:none;border-color:var(--brand)}
  .btn{border:0;border-radius:12px;padding:11px 14px;font-weight:700;cursor:pointer;font-size:13px;width:100%;display:block;text-align:center;text-decoration:none;margin-top:4px}
  .btn-brand{background:var(--brand);color:#fff}
  .btn-secondary{background:var(--soft);color:var(--text)}
  .nav-link{display:block;color:var(--brand);font-size:13px;text-decoration:none;padding:6px 0;border-bottom:1px solid #f0f0f0}
  .nav-link:hover{text-decoration:underline}
  .nav-link:last-child{border:none}

  /* Table */
  .table-card{background:#fff;border:1px solid #ddd;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.05);overflow:hidden}
  table{width:100%;border-collapse:collapse}
  thead th{background:#f8f8f8;padding:11px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #ebebeb}
  tbody tr{border-bottom:1px solid #f0f0f0;cursor:pointer;transition:background .1s}
  tbody tr:hover{background:#f5fbfd}
  tbody td{padding:11px 14px;font-size:13px;vertical-align:middle}
  .badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700}
  .b-interested{background:#fef3c7;color:#92400e}
  .b-reserved{background:#ede9fe;color:#5b21b6}
  .b-promise_draft{background:#f0f0f0;color:#555}
  .b-promise_signed{background:#d1fae5;color:#065f46}
  .b-sold{background:#dbeafe;color:#1e40af}
  .b-payment_in_progress{background:#cffafe;color:#155e75}
  .b-paid{background:#d1fae5;color:#065f46}
  .b-closed{background:#e5e7eb;color:#374151}
  .b-cancelled{background:#fee2e2;color:#991b1b}
  .b-low{background:#f0f0f0;color:#555}
  .b-medium{background:#fef3c7;color:#92400e}
  .b-high{background:#dbeafe;color:#1e40af}
  .b-hot{background:#fee2e2;color:#991b1b}
  .dos-num{font-family:monospace;font-size:12px;font-weight:700;color:var(--brand)}
  .amount{font-family:monospace;font-size:12px}
  .prog-wrap{display:flex;align-items:center;gap:6px}
  .prog-bar{width:56px;height:6px;background:#e5e5e5;border-radius:3px;overflow:hidden;flex-shrink:0}
  .prog-fill{height:100%;background:var(--brand);border-radius:3px}
  .pager{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid #f0f0f0;font-size:12px;color:var(--muted)}
  .pager-btns{display:flex;gap:6px}
  .pager-btns button{padding:5px 12px;border:1px solid #d7d7d7;border-radius:8px;background:#fff;cursor:pointer;font-size:12px;font-family:inherit;font-weight:700}
  .pager-btns button:hover:not(:disabled){background:var(--soft)}
  .pager-btns button:disabled{opacity:.4;cursor:default}

  /* Modal nouveau dossier */
  .overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center}
  .overlay.open{display:flex}
  .modal{background:#fff;border-radius:18px;padding:28px;width:560px;max-width:95vw;max-height:90vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
  .modal h2{margin:0 0 20px;font-size:17px}
  .modal-footer{display:flex;gap:10px;margin-top:18px}
  .modal-footer button{flex:1}
  .flash{padding:10px 14px;border-radius:10px;font-size:13px;font-weight:700;margin-bottom:14px}
  .flash.ok{background:#d1fae5;color:#065f46}
  .flash.err{background:#fee2e2;color:#991b1b}

  @media(max-width:1100px){.app{grid-template-columns:1fr}.panel{position:relative;top:auto;max-height:none}}
</style>
</head>
<body>
<div class="app">

  <!-- ══ PANEL GAUCHE ══ -->
  <aside class="panel">
    <h1>Dossiers CRM</h1>
    <p class="sub">Suivi des dossiers commerciaux</p>

    <div class="form-section">
      <h2>Navigation</h2>
      <a class="nav-link" href="crm_dashboard.php">← Tableau de bord</a>
      <a class="nav-link" href="clients.php">Fiches clients</a>
      <a class="nav-link" href="promises.php">Promesses de vente</a>
    </div>

    <div class="form-section">
      <h2>Filtres</h2>
      <div>
        <label>Recherche</label>
        <input type="text" id="searchInput" placeholder="N° dossier, client, unité…" autocomplete="off">
      </div>
      <div>
        <label>Statut</label>
        <select id="statusFilter">
          <option value="">Tous les statuts</option>
          <?php foreach($SL as $v=>$l): ?>
          <option value="<?=$v?>" <?=$initStatus===$v?'selected':''?>><?=$l?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Projet</label>
        <select id="projectFilter">
          <option value="">Tous projets</option>
          <?php foreach($projects as $pj): ?>
          <option value="<?=htmlspecialchars($pj['code'])?>" <?=strtoupper($initProject)===strtoupper($pj['code'])?'selected':''?>><?=htmlspecialchars($pj['code'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-brand" onclick="doSearch()">Filtrer</button>
      <button class="btn btn-secondary" onclick="clearFilters()">Effacer</button>
    </div>

    <div class="form-section">
      <button class="btn btn-brand" onclick="openModal()">+ Nouveau dossier</button>
    </div>

    <div class="form-section">
      <div id="resultInfo" style="font-size:13px;color:var(--muted)">Chargement…</div>
    </div>
  </aside>

  <!-- ══ CONTENU DROIT ══ -->
  <div>
    <div class="table-card">
      <table>
        <thead>
          <tr>
            <th>N° Dossier</th>
            <th>Client</th>
            <th>Projet / Unité</th>
            <th>Statut</th>
            <th>Intérêt</th>
            <th>Prix / Budget</th>
            <th>Progression</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody id="dossiersBody">
          <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">Chargement…</td></tr>
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

<!-- ══ MODAL NOUVEAU DOSSIER ══ -->
<div class="overlay" id="newOverlay">
  <div class="modal">
    <h2>Nouveau dossier CRM</h2>
    <div id="modalFlash"></div>

    <div>
      <label>Client *</label>
      <input type="text" id="ndClientSearch" placeholder="Rechercher un client…" autocomplete="off">
      <input type="hidden" id="ndClientId">
      <div id="ndClientDd" style="position:relative"></div>
    </div>
    <div>
      <label>Projet</label>
      <select id="ndProject">
        <option value="">— Sélectionner —</option>
        <?php foreach($projects as $pj): ?>
        <option value="<?=(int)$pj['id']?>"><?=htmlspecialchars($pj['code'])?> – <?=htmlspecialchars($pj['nom'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Code unité</label>
      <input type="text" id="ndUnit" placeholder="Ex: A101">
    </div>
    <div>
      <label>Statut initial</label>
      <select id="ndStatus">
        <option value="interested">Intéressé</option>
        <option value="reserved">Réservé</option>
      </select>
    </div>
    <div>
      <label>Niveau d'intérêt</label>
      <select id="ndInterest">
        <option value="medium">Moyen</option>
        <option value="low">Faible</option>
        <option value="high">Élevé</option>
        <option value="hot">Chaud</option>
      </select>
    </div>
    <div>
      <label>Budget (TND)</label>
      <input type="number" id="ndBudget" placeholder="0">
    </div>
    <div>
      <label>Source</label>
      <input type="text" id="ndSource" placeholder="Ex: Site web, Recommandation…">
    </div>
    <div>
      <label>Notes</label>
      <input type="text" id="ndNotes" placeholder="Observations…">
    </div>
    <?php if($users): ?>
    <div>
      <label>Assigné à</label>
      <select id="ndAssigned">
        <option value="">— Non assigné —</option>
        <?php foreach($users as $u): ?>
        <option value="<?=(int)$u['id']?>"><?=htmlspecialchars($u['nom'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal()">Annuler</button>
      <button class="btn btn-brand" onclick="saveNewDossier()">Créer le dossier</button>
    </div>
  </div>
</div>

<script>
const LIMIT = 40;
let offset = 0, total = 0;
const SL = {
  interested:'Intéressé', reserved:'Réservé', promise_draft:'Promesse brouillon',
  promise_signed:'Promesse signée', sold:'Vendu', payment_in_progress:'Paiement en cours',
  paid:'Soldé', closed:'Clôturé', cancelled:'Annulé'
};
const IL = {low:'Faible', medium:'Moyen', high:'Élevé', hot:'Chaud'};

function fmt(n){ return n && n > 0 ? Math.round(n).toLocaleString('fr-FR')+' TND' : '—'; }
function esc(s){ const d=document.createElement('div');d.textContent=s||'';return d.innerHTML; }

async function loadDossiers() {
  const q  = document.getElementById('searchInput').value.trim();
  const st = document.getElementById('statusFilter').value;
  const pj = document.getElementById('projectFilter').value;
  const url = `api/crm_dossiers_list.php?q=${encodeURIComponent(q)}&status=${encodeURIComponent(st)}&project=${encodeURIComponent(pj)}&limit=${LIMIT}&offset=${offset}`;
  const res  = await fetch(url);
  const data = await res.json();
  if (data.error) { alert(data.error); return; }
  total = data.total;
  const rows = data.dossiers || [];

  document.getElementById('resultInfo').textContent = `${total} dossier${total>1?'s':''} trouvé${total>1?'s':''}`;

  if (!rows.length) {
    document.getElementById('dossiersBody').innerHTML = '<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">Aucun dossier trouvé</td></tr>';
    document.getElementById('pageInfo').textContent = '';
    document.getElementById('btnPrev').disabled = true;
    document.getElementById('btnNext').disabled = true;
    return;
  }

  document.getElementById('dossiersBody').innerHTML = rows.map(d => {
    const prog = parseFloat(d.progress_percent)||0;
    const price = parseFloat(d.sale_price)||parseFloat(d.budget)||0;
    return `<tr onclick="window.location='crm_dossier.php?id=${d.id}'">
      <td><span class="dos-num">${esc(d.dossier_number)}</span></td>
      <td>
        <div style="font-weight:700">${esc(d.client_nom||'—')}</div>
        <div style="font-size:11px;color:var(--muted)">${esc(d.client_tel||'')}</div>
      </td>
      <td>
        <div style="font-weight:700">${esc(d.unit_code||'—')}</div>
        <div style="font-size:11px;color:var(--muted)">${esc(d.project_code||'')}</div>
      </td>
      <td><span class="badge b-${d.status}">${esc(SL[d.status]||d.status)}</span></td>
      <td><span class="badge b-${d.interest_level||'medium'}">${esc(IL[d.interest_level]||d.interest_level||'—')}</span></td>
      <td class="amount">${fmt(price)}</td>
      <td>
        ${d.progress_percent !== null ? `<div class="prog-wrap">
          <div class="prog-bar"><div class="prog-fill" style="width:${Math.min(100,prog)}%"></div></div>
          <span style="font-size:11px;color:var(--muted)">${Math.round(prog)}%</span>
        </div>` : '<span style="color:var(--muted);font-size:12px">—</span>'}
      </td>
      <td style="font-size:12px;color:var(--muted)">${(d.created_at||'').substring(0,10)}</td>
    </tr>`;
  }).join('');

  const pages = Math.max(1, Math.ceil(total/LIMIT));
  const cur   = Math.floor(offset/LIMIT)+1;
  document.getElementById('pageInfo').textContent = `Page ${cur}/${pages} — ${total} dossier${total>1?'s':''}`;
  document.getElementById('btnPrev').disabled = offset === 0;
  document.getElementById('btnNext').disabled = offset + LIMIT >= total;
}

function doSearch() { offset = 0; loadDossiers(); }
function goPage(dir) { offset = Math.max(0, offset + dir*LIMIT); loadDossiers(); }
function clearFilters() {
  document.getElementById('searchInput').value = '';
  document.getElementById('statusFilter').value = '';
  document.getElementById('projectFilter').value = '';
  doSearch();
}
document.getElementById('searchInput').addEventListener('keydown', e => { if(e.key==='Enter') doSearch(); });

// ── Client autocomplete (modal) ──────────────────────────────────
let acTimer = null;
document.getElementById('ndClientSearch').addEventListener('input', function() {
  clearTimeout(acTimer);
  const q = this.value.trim();
  if (q.length < 2) { clearAC(); return; }
  acTimer = setTimeout(() => searchClients(q), 250);
});

async function searchClients(q) {
  const res  = await fetch('api/clients_list.php?q=' + encodeURIComponent(q) + '&limit=8');
  const data = await res.json();
  const list = data.clients || [];
  const dd   = document.getElementById('ndClientDd');
  if (!list.length) { clearAC(); return; }
  dd.innerHTML = `<div style="position:absolute;z-index:200;background:#fff;border:1px solid #ddd;border-radius:10px;width:100%;box-shadow:0 8px 24px rgba(0,0,0,.1);max-height:200px;overflow:auto">`
    + list.map(c => `<div onclick="pickClient(${c.id},${JSON.stringify(esc(c.nom))})" style="padding:9px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f0f0" onmouseenter="this.style.background='#f5fbfd'" onmouseleave="this.style.background=''">`
      + `<strong>${esc(c.nom)}</strong> <span style="color:var(--muted)">${esc(c.tel||c.email||'')}</span></div>`).join('')
    + '</div>';
}
function pickClient(id, nom) {
  document.getElementById('ndClientId').value = id;
  document.getElementById('ndClientSearch').value = nom.replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>');
  clearAC();
}
function clearAC() { document.getElementById('ndClientDd').innerHTML = ''; }

// ── Modal ────────────────────────────────────────────────────────
function openModal() { document.getElementById('newOverlay').classList.add('open'); }
function closeModal() { document.getElementById('newOverlay').classList.remove('open'); document.getElementById('modalFlash').innerHTML = ''; }
document.getElementById('newOverlay').addEventListener('click', function(e){ if(e.target===this) closeModal(); });

async function saveNewDossier() {
  const clientId = parseInt(document.getElementById('ndClientId').value)||0;
  if (!clientId) { showModalFlash('Veuillez sélectionner un client.','err'); return; }

  const body = {
    client_id:     clientId,
    project_id:    parseInt(document.getElementById('ndProject').value)||null,
    unit_code:     document.getElementById('ndUnit').value.trim()||null,
    status:        document.getElementById('ndStatus').value,
    interest_level:document.getElementById('ndInterest').value,
    budget:        parseFloat(document.getElementById('ndBudget').value)||null,
    source:        document.getElementById('ndSource').value.trim()||null,
    notes:         document.getElementById('ndNotes').value.trim()||null,
    assigned_to:   parseInt(document.getElementById('ndAssigned')?.value)||null,
  };

  const res  = await fetch('api/crm_dossier_save.php', {method:'POST', body:JSON.stringify(body)});
  const data = await res.json();
  if (data.error) { showModalFlash(data.error,'err'); return; }
  window.location = 'crm_dossier.php?id=' + data.id;
}

function showModalFlash(msg, type) {
  document.getElementById('modalFlash').innerHTML = `<div class="flash ${type}">${esc(msg)}</div>`;
}

// Init
<?php if($initStatus || $initProject): ?>
loadDossiers();
<?php else: ?>
loadDossiers();
<?php endif; ?>

<?php if($openNew==='true'): ?>
openModal();
<?php endif; ?>
</script>
</body>
</html>
