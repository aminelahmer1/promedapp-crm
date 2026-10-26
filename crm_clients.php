<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$projects = $pdo->query("SELECT id,code,nom FROM projects ORDER BY code")->fetchAll();
$users = [];
try { $users = $pdo->query("SELECT id,full_name AS nom FROM users ORDER BY full_name")->fetchAll(); } catch(Throwable $e){}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Clients CRM – PromedApp</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php include __DIR__.'/includes/crm_style.php'; ?>
<style>
.search-wrap{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.search-wrap .inp{flex:1;min-width:200px}
.filters{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px;align-items:center;padding:10px 14px;background:var(--paper);border:1px solid var(--border);border-radius:10px}
.filters label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin-right:2px}
.mini-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.ms{background:var(--paper);border:1px solid var(--border);border-radius:10px;padding:11px 14px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.ms .ms-n{font-size:22px;font-weight:800;color:var(--brand);line-height:1;margin-bottom:2px}
.ms .ms-l{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
.action-btns{display:flex;gap:4px}
.contact-badge{font-size:11px;padding:2px 7px;border-radius:4px;font-weight:700;white-space:nowrap}
.contact-ok{background:var(--ok-lt);color:var(--ok)}
.contact-warn{background:var(--warn-lt);color:var(--warn)}
.contact-err{background:var(--err-lt);color:var(--err)}
.nf-badge{font-size:10px;padding:1px 6px;border-radius:4px;font-weight:700}
.nf-late{background:var(--err-lt);color:var(--err)}
.nf-soon{background:var(--warn-lt);color:var(--warn)}
.nf-ok{background:var(--ok-lt);color:var(--ok)}
.nl-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center}
.nl-overlay.open{display:flex}
.nl-box{background:#fff;border-radius:14px;padding:28px 28px 20px;width:520px;max-width:96vw;max-height:92vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,.18)}
.nl-box h3{margin:0 0 18px;color:var(--brand);font-size:17px}
.nl-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.nl-full{grid-column:1/-1}
.nl-box label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);display:block;margin-bottom:3px}
.nl-box input,.nl-box select,.nl-box textarea{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:7px;font-size:13px;font-family:inherit}
.nl-box textarea{resize:vertical;min-height:60px}
.nl-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:18px;padding-top:14px;border-top:1px solid var(--border)}
</style>
</head>
<body>
<div class="app">

<!-- ══ PANEL GAUCHE ══ -->
<aside class="panel">
  <div class="panel-title">CRM Promed</div>
  <div class="panel-sub">Suivi commercial</div>

  <div class="nav-section">
    <div class="nav-section-title">Navigation</div>
    <a class="nav-link" href="crm_dashboard.php">⊟ Dashboard</a>
    <a class="nav-link active" href="crm_clients.php">Clients</a>
    <a class="nav-link" href="crm_pipeline.php">Pipeline Kanban</a>
    <a class="nav-link" href="promises.php">Promesses de vente</a>
    <a class="nav-link" href="crm_units.php">Biens</a>
  </div>
  <div class="nav-section">
    <div class="nav-section-title">Projets</div>
    <?php foreach($projects as $pj): ?>
    <a class="nav-link" href="crm_clients.php?project=<?=urlencode($pj['code'])?>"><?=htmlspecialchars($pj['code'])?></a>
    <?php endforeach; ?>
  </div>
  <div class="nav-section">
    <div class="nav-section-title">Priorité</div>
    <a class="nav-link" href="crm_clients.php?priority=hot">🔴 Très chaud</a>
    <a class="nav-link" href="crm_clients.php?priority=high">🟠 Chaud</a>
    <a class="nav-link" href="crm_clients.php?priority=vip">⭐ VIP</a>
  </div>
  <div class="nav-section">
    <div class="nav-section-title">Statut prospect</div>
    <a class="nav-link" href="crm_clients.php?status=new">Nouveaux</a>
    <a class="nav-link" href="crm_clients.php?status=contacted">Contactés</a>
    <a class="nav-link" href="crm_clients.php?status=interested">Intéressés</a>
    <a class="nav-link" href="crm_clients.php?status=visit">En visite</a>
    <a class="nav-link" href="crm_clients.php?status=negotiation">Négociation</a>
    <a class="nav-link" href="crm_clients.php?status=reserved">Réservés</a>
  </div>
</aside>

<!-- ══ CONTENU ══ -->
<div>

  <div class="search-wrap">
    <input id="searchInp" class="inp" type="text" placeholder="🔎  Rechercher nom, téléphone, email…" oninput="debounceLoad()">
    <button class="btn btn-brand" onclick="openNewLead()">➕ Nouveau client</button>
    <a href="crm_pipeline.php" class="btn btn-outline">🧭 Pipeline</a>
  </div>

  <div class="filters">
    <label>Statut</label>
    <select id="fStatus" class="sel" onchange="load()">
      <option value="">Tous</option>
      <option value="new">Nouveau</option>
      <option value="contacted">Contacté</option>
      <option value="interested">Intéressé</option>
      <option value="visit">Visite</option>
      <option value="negotiation">Négociation</option>
      <option value="reserved">Réservé</option>
      <option value="lost">Perdu</option>
    </select>
    <label style="margin-left:8px">Projet</label>
    <select id="fProject" class="sel" onchange="load()">
      <option value="">Tous</option>
      <?php foreach($projects as $pj): ?>
      <option value="<?=htmlspecialchars($pj['code'])?>"><?=htmlspecialchars($pj['code'])?></option>
      <?php endforeach; ?>
    </select>
    <label style="margin-left:8px">Type bien</label>
    <select id="fType" class="sel" onchange="load()">
      <option value="">Tous</option>
      <?php foreach(['S1','S2','S3','S4','Duplex','Villa','Bureau'] as $t): ?>
      <option value="<?=$t?>"><?=$t?></option>
      <?php endforeach; ?>
    </select>
    <label style="margin-left:8px">Commercial</label>
    <select id="fCommercial" class="sel" onchange="load()">
      <option value="">Tous</option>
      <?php foreach($users as $u): ?>
      <option value="<?=(int)$u['id']?>"><?=htmlspecialchars($u['nom'])?></option>
      <?php endforeach; ?>
    </select>
    <label style="margin-left:8px">Intérêt</label>
    <select id="fPriority" class="sel" onchange="load()">
      <option value="">Tous</option>
      <option value="hot">🔴 Très chaud</option>
      <option value="high">🟠 Chaud</option>
      <option value="medium">Moyen</option>
      <option value="low">Froid</option>
      <option value="vip">⭐ VIP</option>
    </select>
    <button class="btn btn-outline btn-sm" onclick="resetFilters()">✕ Effacer</button>
    <span id="totalCount" style="margin-left:auto;font-size:12px;color:var(--muted)"></span>
  </div>

  <!-- Mini stats -->
  <div class="mini-stats" id="miniStats">
    <div class="ms"><div class="ms-n"><span class="spin"></span></div><div class="ms-l">Chargement…</div></div>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="card-head">
      <h3>Prospects <span id="listCount" class="cnt-badge cb-grey">…</span></h3>
      <div style="display:flex;gap:8px">
        <button class="btn btn-outline btn-sm" onclick="load()">↻ Actualiser</button>
        <button class="btn btn-brand btn-sm" onclick="openNewLead()">+ Nouveau lead</button>
      </div>
    </div>
    <div style="overflow-x:auto">
      <table>
        <thead><tr>
          <th>Nom</th>
          <th>Téléphone</th>
          <th>Statut</th>
          <th>Projet / Besoin</th>
          <th>Intérêt</th>
          <th>Dernier contact</th>
          <th>Prochain suivi</th>
          <th>Commercial</th>
          <th>Actions</th>
        </tr></thead>
        <tbody id="clientBody">
          <tr><td colspan="9" class="empty"><span class="spin"></span></td></tr>
        </tbody>
      </table>
    </div>
    <div style="padding:10px 14px;border-top:1px solid #f0f2f5;display:flex;gap:8px;align-items:center">
      <button id="btnPrev" class="btn btn-outline btn-sm" onclick="page--;load()" disabled>← Préc.</button>
      <span id="pageInfo" style="font-size:12px;color:var(--muted)"></span>
      <button id="btnNext" class="btn btn-outline btn-sm" onclick="page++;load()">Suiv. →</button>
    </div>
  </div>

</div>
</div>

<!-- ══ MODAL : Nouveau Lead ══ -->
<div id="nlOverlay" class="nl-overlay" onclick="if(event.target===this)closeNewLead()">
  <div class="nl-box">
    <h3>Nouveau lead / prospect</h3>
    <div class="nl-grid">

      <div class="nl-full">
        <label>Nom complet *</label>
        <input id="nl_nom" type="text" placeholder="Ex: Ben Salah Mohamed">
      </div>

      <div>
        <label>Téléphone *</label>
        <input id="nl_tel" type="tel" placeholder="21600000">
      </div>
      <div>
        <label>WhatsApp</label>
        <input id="nl_wa" type="tel" placeholder="(si différent)">
      </div>

      <div class="nl-full">
        <label>Email</label>
        <input id="nl_email" type="email" placeholder="nom@email.com">
      </div>

      <div>
        <label>Source</label>
        <select id="nl_source">
          <option value="">— Choisir —</option>
          <option>Facebook</option><option>Instagram</option><option>Site web</option>
          <option>Référence</option><option>Salon</option><option>LinkedIn</option>
          <option>Appel entrant</option><option>Visite spontanée</option><option>Autre</option>
        </select>
      </div>
      <div>
        <label>Niveau d'intérêt</label>
        <select id="nl_interest">
          <option value="medium">Moyen</option>
          <option value="hot">Très chaud</option>
          <option value="high">Chaud</option>
          <option value="low">Froid</option>
          <option value="vip">VIP</option>
        </select>
      </div>

      <div>
        <label>Projet intéressé</label>
        <select id="nl_project">
          <option value="">— Aucun —</option>
          <?php foreach($projects as $pj): ?>
          <option value="<?=(int)$pj['id']?>"><?=htmlspecialchars($pj['code'].' – '.$pj['nom'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Type de bien</label>
        <select id="nl_ptype">
          <option value="">— Tous —</option>
          <?php foreach(['S1','S2','S3','S4','Duplex','Villa','Bureau'] as $t): ?>
          <option><?=$t?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Budget min (DT)</label>
        <input id="nl_bmin" type="number" placeholder="Ex: 200000" step="1000">
      </div>
      <div>
        <label>Budget max (DT)</label>
        <input id="nl_bmax" type="number" placeholder="Ex: 350000" step="1000">
      </div>

      <div>
        <label>Commercial assigné</label>
        <select id="nl_assigned">
          <option value="">— Moi —</option>
          <?php foreach($users as $u): ?>
          <option value="<?=(int)$u['id']?>"><?=htmlspecialchars($u['nom'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Mode financement</label>
        <select id="nl_finmode">
          <option value="">— NC —</option>
          <option value="cash">Cash</option>
          <option value="bank_loan">Crédit bancaire</option>
          <option value="both">Cash + crédit</option>
        </select>
      </div>

      <div class="nl-full">
        <label>Notes rapides</label>
        <textarea id="nl_notes" placeholder="Premier contact, motivations, remarques…"></textarea>
      </div>

    </div>
    <div id="nlErr" style="display:none;color:var(--err);font-size:12px;margin-top:8px"></div>
    <div class="nl-foot">
      <button class="btn btn-outline btn-sm" onclick="closeNewLead()">Annuler</button>
      <button class="btn btn-brand" id="nlSaveBtn" onclick="saveNewLead()">Enregistrer et ouvrir la fiche</button>
    </div>
  </div>
</div>

<script>
<?php include __DIR__.'/includes/crm_js_helpers.php'; ?>

let page=0, total=0;
const PER=40;

const CRMSL = {new:'Nouveau',contacted:'Contacté',interested:'Intéressé',visit:'Visite',negotiation:'Négociation',reserved:'Réservé',lost:'Perdu',sold:'Vendu'};
const CRMBG = {new:'#f0f0f0',contacted:'#e0f0ff',interested:'#fef3c7',visit:'#f0ecfa',negotiation:'#fef3c7',reserved:'#d1fae5',lost:'#fee2e2',sold:'#dbeafe'};
const CRMCL = {new:'#555',contacted:'#145f7d',interested:'#92400e',visit:'#5b21b6',negotiation:'#b45309',reserved:'#065f46',lost:'#991b1b',sold:'#1e40af'};

// Init URL params
(function(){
  const p=new URLSearchParams(location.search);
  if(p.get('status'))         document.getElementById('fStatus').value    = p.get('status');
  if(p.get('project'))        document.getElementById('fProject').value   = p.get('project');
  if(p.get('priority'))       document.getElementById('fPriority').value  = p.get('priority');
  if(p.get('q'))              document.getElementById('searchInp').value  = p.get('q');
})();

function params(){
  return new URLSearchParams({
    q:             document.getElementById('searchInp').value.trim(),
    status:        document.getElementById('fStatus').value,
    project:       document.getElementById('fProject').value,
    property_type: document.getElementById('fType').value,
    commercial:    document.getElementById('fCommercial').value,
    priority:      document.getElementById('fPriority').value,
    limit: PER, offset: page*PER
  });
}

function resetFilters(){
  ['fStatus','fProject','fType','fCommercial','fPriority'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('searchInp').value='';
  page=0; load();
}

let _t;
function debounceLoad(){ clearTimeout(_t); _t=setTimeout(()=>{page=0;load();},320); }

function contactLabel(days){
  if(days===null||days===undefined)return '<span class="t-muted">—</span>';
  if(days===0) return '<span class="contact-badge contact-ok">Auj.</span>';
  if(days===1) return '<span class="contact-badge contact-ok">Hier</span>';
  if(days<=7)  return `<span class="contact-badge contact-ok">${days}j</span>`;
  if(days<=14) return `<span class="contact-badge contact-warn">${days}j</span>`;
  return `<span class="contact-badge contact-err">${days}j</span>`;
}

function nfLabel(dt) {
  if (!dt) return '<span class="t-muted">—</span>';
  const days = Math.ceil((new Date(dt)-new Date())/86400000);
  if (days < 0)  return `<span class="nf-badge nf-late">En retard ${Math.abs(days)}j</span>`;
  if (days === 0) return `<span class="nf-badge nf-soon">Aujourd'hui</span>`;
  if (days <= 3)  return `<span class="nf-badge nf-soon">Dans ${days}j</span>`;
  return `<span class="nf-badge nf-ok">Dans ${days}j</span>`;
}

function crmBadge(s) {
  if (!s) return '<span class="t-muted">—</span>';
  return `<span class="badge" style="background:${CRMBG[s]||'#eee'};color:${CRMCL[s]||'#555'}">${esc(CRMSL[s]||s)}</span>`;
}

async function load(){
  document.getElementById('clientBody').innerHTML='<tr><td colspan="9" class="empty"><span class="spin"></span></td></tr>';
  try{
    const r = await fetch('api/crm_clients_list.php?'+params()).then(r=>r.json());
    if(r.error){console.error(r.error);return;}

    total = r.total||0;
    const rows = r.clients||[];
    const s = r.stats||{};

    document.getElementById('miniStats').innerHTML=`
      <div class="ms"><div class="ms-n">${total.toLocaleString('fr-FR')}</div><div class="ms-l">Total</div></div>
      <div class="ms"><div class="ms-n" style="color:var(--err)">${s.hot||0}</div><div class="ms-l">Clients chauds</div></div>
      <div class="ms"><div class="ms-n" style="color:var(--warn)">${s.no_contact||0}</div><div class="ms-l">Sans suivi +14j</div></div>
      <div class="ms"><div class="ms-n" style="color:var(--ok)">${s.reserved||0}</div><div class="ms-l">Réservés</div></div>`;

    document.getElementById('listCount').textContent = total;
    document.getElementById('totalCount').textContent = `${rows.length} / ${total}`;
    document.getElementById('pageInfo').textContent = `Page ${page+1} / ${Math.max(1,Math.ceil(total/PER))}`;
    document.getElementById('btnPrev').disabled = page===0;
    document.getElementById('btnNext').disabled = (page+1)*PER>=total;

    if(!rows.length){
      document.getElementById('clientBody').innerHTML='<tr><td colspan="9" class="empty">Aucun client trouvé</td></tr>';
      return;
    }

    document.getElementById('clientBody').innerHTML = rows.map(c=>{
      const fiche = `crm_client.php?id=${c.id}`;
      const dosId = c.dossier_id;
      const ptype = c.property_type ? `<span class="t-muted" style="font-size:11px">${esc(c.property_type)}</span>` : '';
      return `<tr onclick="location='${fiche}'">
        <td>
          <div style="font-weight:700">${esc(c.nom||'—')}</div>
          ${c.source?`<div class="t-muted" style="font-size:11px">${esc(c.source)}</div>`:''}
        </td>
        <td class="t-muted">${esc(c.tel||'—')}</td>
        <td>${crmBadge(c.crm_status||c.status||'new')}</td>
        <td>
          <div style="font-weight:600;font-size:12px">${esc(c.project_code||'—')}</div>
          ${ptype}
        </td>
        <td>${pbadge(c.interest_level)}</td>
        <td>${contactLabel(c.days_since_contact)}</td>
        <td>${nfLabel(c.next_followup_at)}</td>
        <td class="t-muted">${esc(c.commercial||'—')}</td>
        <td onclick="event.stopPropagation()">
          <div class="action-btns">
            <a href="${fiche}" class="btn btn-outline btn-sm" title="Fiche CRM">👤</a>
            <a href="fiche_client.php" class="btn btn-outline btn-sm" title="Nouvelle promesse">📄</a>
          </div>
        </td>
      </tr>`;
    }).join('');

  }catch(e){console.error(e);}
}

// ── Nouveau Lead ─────────────────────────────────────────────────────
function openNewLead() {
  ['nl_nom','nl_tel','nl_wa','nl_email','nl_notes','nl_bmin','nl_bmax'].forEach(id=>{
    document.getElementById(id).value='';
  });
  ['nl_source','nl_project','nl_ptype','nl_assigned','nl_finmode'].forEach(id=>{
    document.getElementById(id).value='';
  });
  document.getElementById('nl_interest').value='medium';
  document.getElementById('nlErr').style.display='none';
  document.getElementById('nlOverlay').classList.add('open');
  setTimeout(()=>document.getElementById('nl_nom').focus(), 80);
}

function closeNewLead() {
  document.getElementById('nlOverlay').classList.remove('open');
}

async function saveNewLead() {
  const nom = document.getElementById('nl_nom').value.trim();
  const tel = document.getElementById('nl_tel').value.trim();
  const errEl = document.getElementById('nlErr');
  if (!nom) { errEl.textContent='Le nom est obligatoire.'; errEl.style.display='block'; return; }
  if (!tel)  { errEl.textContent='Le téléphone est obligatoire.'; errEl.style.display='block'; return; }
  errEl.style.display='none';

  const btn = document.getElementById('nlSaveBtn');
  btn.disabled=true; btn.textContent='Enregistrement…';

  const bmin = document.getElementById('nl_bmin').value;
  const bmax = document.getElementById('nl_bmax').value;
  const proj = document.getElementById('nl_project').value;
  const ptype = document.getElementById('nl_ptype').value;

  const payload = {
    nom,
    tel,
    whatsapp:        document.getElementById('nl_wa').value.trim()||null,
    email:           document.getElementById('nl_email').value.trim()||null,
    source:          document.getElementById('nl_source').value||null,
    interest_level:  document.getElementById('nl_interest').value,
    assigned_to:     document.getElementById('nl_assigned').value||null,
    financing_mode:  document.getElementById('nl_finmode').value||null,
    notes:           document.getElementById('nl_notes').value.trim()||null,
    budget_min:      bmin ? parseFloat(bmin) : null,
    budget_max:      bmax ? parseFloat(bmax) : null,
    crm_status:      'new',
  };

  // If project + type: attach as needs
  if (proj || ptype) {
    payload.needs = {
      project_id:    proj ? parseInt(proj) : null,
      property_type: ptype||null,
      budget_min:    bmin ? parseFloat(bmin) : null,
      budget_max:    bmax ? parseFloat(bmax) : null,
    };
  }

  try {
    const r = await fetch('api/crm_client_save.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    }).then(r=>r.json());

    if (r.error) {
      errEl.textContent = r.error; errEl.style.display='block';
      btn.disabled=false; btn.textContent='Enregistrer et ouvrir la fiche';
      return;
    }
    // Redirect to the new client profile
    window.location.href = 'crm_client.php?id=' + r.id;

  } catch(e) {
    errEl.textContent='Erreur réseau.'; errEl.style.display='block';
    btn.disabled=false; btn.textContent='Enregistrer et ouvrir la fiche';
  }
}

load();
</script>
</body></html>
