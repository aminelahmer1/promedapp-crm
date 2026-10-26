<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$projects = $pdo->query("SELECT id,code,nom FROM projects ORDER BY code")->fetchAll();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Biens CRM – PromedApp</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php include __DIR__.'/includes/crm_style.php'; ?>
<style>
.filters2{display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:14px;padding:10px 14px;background:var(--paper);border:1px solid var(--border);border-radius:10px}
.filters2 label{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);letter-spacing:.04em}
.unit-detail{background:var(--paper);border:1px solid var(--border);border-radius:11px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.ud-title{font-size:16px;font-weight:800;color:var(--brand);margin-bottom:14px}
.interest-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:4px}
.id-hot{background:var(--err)}.id-high{background:var(--warn)}.id-medium{background:#6b7280}.id-low{background:#d1d5db}.id-vip{background:var(--gold)}
</style>
</head>
<body>
<div class="app">

<!-- ══ PANEL GAUCHE ══ -->
<aside class="panel">
  <div class="panel-title">CRM Promed</div>
  <div class="panel-sub">Biens immobiliers</div>
  <div class="nav-section">
    <div class="nav-section-title">Navigation</div>
    <a class="nav-link" href="crm_dashboard.php">⊟ Dashboard</a>
    <a class="nav-link" href="crm_clients.php">Clients</a>
    <a class="nav-link" href="crm_pipeline.php">Pipeline</a>
    <a class="nav-link" href="crm_dossiers.php">Dossiers</a>
    <a class="nav-link active" href="crm_units.php">Biens</a>
  </div>
  <div class="nav-section">
    <div class="nav-section-title">Filtres rapides</div>
    <?php foreach($projects as $pj): ?>
    <a class="nav-link" href="crm_units.php?project=<?=urlencode($pj['code'])?>"><?=htmlspecialchars($pj['code'])?></a>
    <?php endforeach; ?>
  </div>
  <div class="nav-section">
    <div class="nav-section-title">Par type</div>
    <?php foreach(['S1','S2','S3','S4','Duplex'] as $t): ?>
    <a class="nav-link" href="crm_units.php?type=<?=urlencode($t)?>"><?=$t?></a>
    <?php endforeach; ?>
  </div>
</aside>

<!-- ══ CONTENU ══ -->
<div>

  <!-- Filtres -->
  <div class="filters2">
    <label>Projet</label>
    <select id="fProject" class="sel" onchange="load()">
      <option value="">Tous</option>
      <?php foreach($projects as $pj): ?>
      <option value="<?=htmlspecialchars($pj['code'])?>"><?=htmlspecialchars($pj['code'])?></option>
      <?php endforeach; ?>
    </select>
    <label style="margin-left:8px">Type</label>
    <select id="fType" class="sel" onchange="load()">
      <option value="">Tous</option>
      <?php foreach(['S1','S2','S3','S4','Duplex','Villa','Bureau'] as $t): ?>
      <option value="<?=$t?>"><?=$t?></option>
      <?php endforeach; ?>
    </select>
    <label style="margin-left:8px">Recherche</label>
    <input id="fQ" class="inp" style="width:200px" placeholder="Code unité…" oninput="debounceLoad()">
    <button class="btn btn-outline btn-sm" onclick="resetFilters()">✕</button>
    <button class="btn btn-outline btn-sm" onclick="load()">↻</button>
    <span id="unitCount" style="margin-left:auto;font-size:12px;color:var(--muted)"></span>
  </div>

  <!-- Layout: table + detail -->
  <div style="display:grid;grid-template-columns:1fr 400px;gap:14px;align-items:start">

    <!-- Table -->
    <div class="card">
      <div class="card-head">
        <h3>Biens <span id="cntBadge" class="cnt-badge cb-grey">…</span></h3>
      </div>
      <div style="overflow-x:auto">
        <table>
          <thead><tr>
            <th>Projet</th>
            <th>Code unité</th>
            <th>Type</th>
            <th>Surface</th>
            <th>Prix</th>
            <th>Statut</th>
            <th>Intéressés</th>
            <th>Dossiers</th>
          </tr></thead>
          <tbody id="unitBody">
            <tr><td colspan="8" class="empty"><span class="spin"></span></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Detail panel -->
    <div id="detailPanel" style="display:none">
      <div class="unit-detail">
        <div class="ud-title" id="detailTitle">—</div>
        <div id="detailInfo" style="margin-bottom:16px"></div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:8px">Clients intéressés</div>
        <div id="detailClients"></div>
      </div>
    </div>

  </div>
</div>
</div>

<script>
<?php include __DIR__.'/includes/crm_js_helpers.php'; ?>

const UNIT_STATUS = {available:'Disponible',reserved:'Réservé',sold:'Vendu',unavailable:'Indisponible'};
const IDOTCLS = {hot:'id-hot',high:'id-high',medium:'id-medium',low:'id-low',vip:'id-vip'};

let _selectedUnit = null;

function params() {
  return new URLSearchParams({
    project: document.getElementById('fProject').value,
    type:    document.getElementById('fType').value,
    q:       document.getElementById('fQ').value.trim(),
  });
}

function resetFilters(){
  ['fProject','fType'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('fQ').value='';
  load();
}

let _t;
function debounceLoad(){ clearTimeout(_t); _t=setTimeout(load,300); }

async function load() {
  document.getElementById('unitBody').innerHTML='<tr><td colspan="8" class="empty"><span class="spin"></span></td></tr>';
  document.getElementById('detailPanel').style.display='none';

  try {
    const r = await fetch('api/crm_units_list.php?'+params()).then(r=>r.json());
    if(r.error){ console.error(r.error); return; }
    const units = r.units||[];
    document.getElementById('cntBadge').textContent = units.length;
    document.getElementById('unitCount').textContent = units.length + ' bien(s)';

    if(!units.length){
      document.getElementById('unitBody').innerHTML='<tr><td colspan="8" class="empty">Aucun bien trouvé</td></tr>';
      return;
    }

    document.getElementById('unitBody').innerHTML = units.map(u => {
      const st = u.status||u.dos_status||'';
      const code = u.unit_code||'—';
      const ptype = u.property_type||'—';
      const surf = u.surface ? u.surface+' m²' : '—';
      const price = u.price ? Math.round(u.price).toLocaleString('fr-FR')+' TND' : '—';
      const stBadge = UNIT_STATUS[st] ? `<span class="badge bs-${esc(st)}">${esc(UNIT_STATUS[st])}</span>` : `<span class="t-muted">—</span>`;
      const interested = parseInt(u.interested_count)||0;
      const dossiers   = parseInt(u.dossier_count)||0;
      return `<tr onclick="loadDetail('${esc(code)}','${esc(u.project_code||'')}','${esc(ptype)}')" style="cursor:pointer">
        <td style="font-weight:700;font-size:12px">${esc(u.project_code||'—')}</td>
        <td class="tnum">${esc(code)}</td>
        <td>${esc(ptype)}</td>
        <td class="t-muted">${esc(surf)}</td>
        <td class="t-muted">${esc(price)}</td>
        <td>${stBadge}</td>
        <td style="text-align:center">
          ${interested>0?`<span class="cnt-badge cb-warn">${interested}</span>`:'<span class="t-muted">0</span>'}
        </td>
        <td style="text-align:center">
          ${dossiers>0?`<span class="cnt-badge cb-ok">${dossiers}</span>`:'<span class="t-muted">0</span>'}
        </td>
      </tr>`;
    }).join('');

  } catch(e){ console.error(e); }
}

async function loadDetail(unitCode, projectCode, ptype) {
  document.getElementById('detailPanel').style.display='block';
  document.getElementById('detailTitle').textContent = unitCode;
  document.getElementById('detailInfo').innerHTML = `
    <div class="fl-row"><div class="fl-l" style="color:var(--muted);font-size:12px">Projet</div><div style="font-size:13px">${esc(projectCode)}</div></div>
    <div class="fl-row" style="border-bottom:none"><div class="fl-l" style="color:var(--muted);font-size:12px">Type</div><div style="font-size:13px">${esc(ptype)}</div></div>`;
  document.getElementById('detailClients').innerHTML = '<span class="spin"></span>';

  try {
    const r = await fetch('api/crm_units_list.php?unit_code='+encodeURIComponent(unitCode)).then(r=>r.json());
    const clients = r.clients_for_unit||[];
    if(!clients.length){
      document.getElementById('detailClients').innerHTML='<div style="color:var(--muted);font-size:13px">Aucun client intéressé.</div>';
      return;
    }
    const ILbl = {hot:'Très chaud',high:'Chaud',medium:'Moyen',low:'Froid',vip:'VIP'};
    const SLbl = {new:'Nouveau',contacted:'Contacté',interested:'Intéressé',visit:'Visite',negotiation:'Négociation',reserved:'Réservé',lost:'Perdu'};
    document.getElementById('detailClients').innerHTML = clients.map(c=>
      `<a href="crm_fiche_client.php?id=${c.id}" style="display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid #f0f2f5;text-decoration:none;color:inherit">
        <div>
          <div style="font-weight:700;font-size:13px">
            <span class="interest-dot ${IDOTCLS[c.unit_interest||'medium']||'id-medium'}"></span>
            ${esc(c.nom)}
          </div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px">${esc(c.tel||'')} · ${esc(SLbl[c.status]||c.status||'')}</div>
        </div>
        <div style="display:flex;gap:5px;align-items:center">
          ${pbadge(c.unit_interest)}
          ${c.is_favorite=='1'?'<span title="Favori">⭐</span>':''}
        </div>
      </a>`
    ).join('');
  } catch(e){ document.getElementById('detailClients').innerHTML='Erreur.'; }
}

// Init URL params
const _up = new URLSearchParams(location.search);
if(_up.get('project')) document.getElementById('fProject').value = _up.get('project');
if(_up.get('type'))    document.getElementById('fType').value    = _up.get('type');
if(_up.get('q'))       document.getElementById('fQ').value       = _up.get('q');

load();
</script>
</body></html>
