<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: crm_clients.php'); exit; }

$projects = $pdo->query("SELECT id,code,nom FROM projects ORDER BY code")->fetchAll();
try { $users = $pdo->query("SELECT id,full_name AS nom FROM users ORDER BY full_name")->fetchAll(); }
catch(Throwable $e){ $users = []; }
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Profil client – PromedApp</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php include __DIR__.'/includes/crm_style.php'; ?>
<style>
.fh{background:var(--paper);border:1px solid var(--border);border-radius:12px;padding:18px 22px;margin-bottom:14px}
.fh-top{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}
.fh-name{font-size:22px;font-weight:800;margin:0 0 4px}
.fh-meta{display:flex;gap:12px;flex-wrap:wrap;font-size:13px;color:var(--muted);margin-bottom:8px}
.fh-meta a{color:var(--brand);text-decoration:none}
.fh-actions{display:flex;gap:6px;flex-wrap:wrap}
.blocks{display:grid;grid-template-columns:1fr 320px;gap:14px;align-items:start}
.bloc{background:var(--paper);border:1px solid var(--border);border-radius:11px;margin-bottom:14px;overflow:hidden}
.bloc-head{padding:10px 16px;border-bottom:1px solid #f0f2f5;display:flex;align-items:center;justify-content:space-between}
.bloc-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}
.bloc-body{padding:14px 16px}
.fl-row{display:grid;grid-template-columns:130px 1fr;gap:4px 8px;padding:5px 0;border-bottom:1px solid #f5f7fa;align-items:start;font-size:13px}
.fl-row:last-child{border:none}
.fl-l{color:var(--muted);font-weight:600;font-size:12px}
/* Promise cards */
.prom-card{border:1px solid var(--border);border-radius:9px;padding:12px 14px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.prom-card:last-child{margin:0}
.prom-card.locked{background:#fafafa;border-color:#ccc}
.prom-card.signed{background:#f0fdf4;border-color:#a7f3d0}
.prom-card.draft{background:#fffbf2;border-color:#fde68a}
.prom-num{font-weight:700;font-size:13px}
.prom-unit{font-size:12px;color:var(--muted)}
.prom-price{font-size:13px;font-weight:700;color:var(--brand)}
/* Unit cards */
.uc{border:1px solid var(--border);border-radius:9px;padding:10px 13px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;gap:8px}
.uc.fav{border-color:#a56d2d;background:#fffbf2}
.uc-code{font-weight:700}
.uc-sub{font-size:11px;color:var(--muted);margin-top:1px}
/* Timeline */
.tl-item{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #f0f2f5}
.tl-item:last-child{border:none}
.tl-icon{width:28px;height:28px;border-radius:50%;background:#edf4f9;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
.tl-body{flex:1;min-width:0}
.tl-subj{font-size:13px;font-weight:600}
.tl-desc{font-size:12px;color:var(--muted);margin-top:1px}
.tl-meta{font-size:11px;color:var(--muted);margin-top:3px}
/* Follow-up form */
.fu-form{background:#f5f7fa;border:1px solid var(--border);border-radius:9px;padding:14px;margin-top:10px;display:none}
.fu-form.open{display:block}
.fu-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px}
.flash2{padding:10px 16px;border-radius:8px;font-size:13px;font-weight:700;margin-bottom:10px;display:none}
.flash2.ok{background:#d1fae5;color:#065f46}
.flash2.err{background:#fee2e2;color:#991b1b}
/* Status pipeline */
.status-steps{display:flex;flex-wrap:wrap;gap:6px;padding:12px 14px}
.ss{flex:1;min-width:60px;text-align:center;padding:7px 4px;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;border:2px solid transparent;opacity:.5;transition:.15s}
.ss.active{opacity:1;border-color:var(--brand)}
.ss:hover{opacity:.8}
/* Modal add unit */
.mu-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;align-items:center;justify-content:center}
.mu-overlay.open{display:flex}
.mu-box{background:#fff;border-radius:12px;padding:24px;width:420px;max-width:95vw;box-shadow:0 8px 40px rgba(0,0,0,.15)}
.mu-box h3{margin:0 0 16px;color:var(--brand);font-size:16px}
.mu-box label{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);display:block;margin:10px 0 3px}
.mu-box input,.mu-box select{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:7px;font-size:13px;font-family:inherit}
.mu-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:16px;padding-top:12px;border-top:1px solid var(--border)}
</style>
</head>
<body>
<div class="app">

<!-- ══ PANEL GAUCHE ══ -->
<aside class="panel">
  <div class="panel-title">CRM Promed</div>
  <div class="panel-sub">Profil client</div>

  <div class="nav-section">
    <div class="nav-section-title">CRM</div>
    <a class="nav-link" href="crm_dashboard.php">⊟ Tableau de bord</a>
    <a class="nav-link" href="crm_clients.php">Clients</a>
    <a class="nav-link" href="promises.php">Promesses de vente</a>
  </div>
  <div class="nav-section">
    <div class="nav-section-title">Projets</div>
    <?php foreach($projects as $pj): ?>
    <a class="nav-link" href="dashboard_<?=strtolower($pj['code'])?>.php"><?=htmlspecialchars($pj['code'])?></a>
    <?php endforeach; ?>
  </div>
  <div class="nav-section">
    <div class="nav-section-title">Actions rapides</div>
    <a class="nav-link" href="#" onclick="openAddUnit();return false">+ Unité intéressée</a>
    <a class="nav-link" href="#" onclick="openFollowup();return false">+ Suivi / note</a>
  </div>
</aside>

<!-- ══ CONTENU ══ -->
<div id="mainContent">
  <div id="topFlash" class="flash2"></div>

  <!-- Header client -->
  <div class="fh" id="clientHeader">
    <div class="fh-top">
      <div>
        <div class="fh-name" id="hName">Chargement…</div>
        <div class="fh-meta" id="hMeta"></div>
        <div id="hBadges" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px"></div>
      </div>
      <div class="fh-actions">
        <button class="btn btn-outline btn-sm" onclick="openFollowup()">+ Suivi</button>
        <button class="btn btn-outline btn-sm" onclick="openAddUnit()">+ Unité</button>
        <button class="btn btn-brand btn-sm" id="btnCreatePromise" onclick="createPromise()">Créer promesse</button>
      </div>
    </div>
  </div>

  <div class="blocks">
    <!-- COLONNE PRINCIPALE -->
    <div>

      <!-- Info client -->
      <div class="bloc">
        <div class="bloc-head">
          <span class="bloc-title">Informations client</span>
          <a href="#" onclick="toggleEditInfo();return false" class="btn btn-outline btn-sm" id="btnEditInfo">Modifier</a>
        </div>
        <div class="bloc-body" id="infoView"></div>
        <div class="bloc-body" id="infoEdit" style="display:none">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
            <div><label class="lbl">Nom complet</label><input id="e_nom" class="inp"></div>
            <div><label class="lbl">Téléphone</label><input id="e_tel" class="inp"></div>
            <div><label class="lbl">WhatsApp</label><input id="e_wa" class="inp"></div>
            <div><label class="lbl">Email</label><input id="e_email" class="inp" type="email"></div>
            <div><label class="lbl">CIN / Passeport</label><input id="e_cin" class="inp"></div>
            <div><label class="lbl">Source</label>
              <select id="e_src" class="sel">
                <option value="">—</option>
                <option>Facebook</option><option>Instagram</option><option>Site web</option>
                <option>Référence</option><option>Salon</option><option>LinkedIn</option>
                <option>Appel entrant</option><option>Visite spontanée</option><option>Autre</option>
              </select>
            </div>
            <div><label class="lbl">Commercial</label>
              <select id="e_assigned" class="sel">
                <option value="">—</option>
                <?php foreach($users as $u): ?>
                <option value="<?=(int)$u['id']?>"><?=htmlspecialchars($u['nom'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div><label class="lbl">Profession</label><input id="e_prof" class="inp"></div>
          </div>
          <div style="margin-bottom:10px"><label class="lbl">Notes</label><textarea id="e_notes" class="inp" style="resize:vertical;min-height:60px"></textarea></div>
          <div style="display:flex;gap:8px">
            <button class="btn btn-brand btn-sm" onclick="saveInfo()">Enregistrer</button>
            <button class="btn btn-outline btn-sm" onclick="toggleEditInfo()">Annuler</button>
          </div>
        </div>
      </div>

      <!-- Besoins -->
      <div class="bloc">
        <div class="bloc-head">
          <span class="bloc-title">Besoin immobilier</span>
        </div>
        <div class="bloc-body" id="needsView"><span class="t-muted">Aucun besoin enregistré</span></div>
      </div>

      <!-- Promesses de vente -->
      <div class="bloc">
        <div class="bloc-head">
          <span class="bloc-title">Promesses de vente</span>
          <button class="btn btn-brand btn-sm" onclick="createPromise()">+ Créer promesse</button>
        </div>
        <div class="bloc-body" id="promisesView"><span class="t-muted">Aucune promesse</span></div>
      </div>

      <!-- Suivi des activités -->
      <div class="bloc">
        <div class="bloc-head">
          <span class="bloc-title">Historique des suivis</span>
          <button class="btn btn-outline btn-sm" onclick="openFollowup()">+ Ajouter</button>
        </div>
        <div class="bloc-body">
          <!-- Quick action buttons -->
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
            <?php foreach(['call'=>'📞 Appel','whatsapp'=>'💬 WhatsApp','email'=>'✉️ Email','meeting'=>'🤝 RDV','note'=>'📝 Note'] as $t=>$l): ?>
            <button class="btn btn-outline btn-sm" onclick="openFollowup('<?=$t?>')"><?=$l?></button>
            <?php endforeach; ?>
          </div>

          <!-- Follow-up form -->
          <div class="fu-form" id="fuForm">
            <div class="fu-grid">
              <div>
                <label class="lbl">Type</label>
                <select id="fu_type" class="sel">
                  <option value="call">Appel</option>
                  <option value="whatsapp">WhatsApp</option>
                  <option value="email">Email</option>
                  <option value="meeting">RDV</option>
                  <option value="note">Note</option>
                  <option value="visit">Visite</option>
                </select>
              </div>
              <div>
                <label class="lbl">Date</label>
                <input id="fu_date" type="datetime-local" class="inp">
              </div>
            </div>
            <div style="margin-bottom:8px"><label class="lbl">Objet</label><input id="fu_subject" class="inp" placeholder="Ex: Relance client, envoi brochure…"></div>
            <div style="margin-bottom:8px"><label class="lbl">Résultat / description</label><textarea id="fu_desc" class="inp" style="resize:vertical;min-height:50px" placeholder="Ce qui a été dit, décidé…"></textarea></div>
            <div style="margin-bottom:8px"><label class="lbl">Prochain suivi (optionnel)</label><input id="fu_due" type="datetime-local" class="inp"></div>
            <div style="display:flex;gap:8px">
              <button class="btn btn-brand btn-sm" onclick="saveFollowup()">Enregistrer</button>
              <button class="btn btn-outline btn-sm" onclick="document.getElementById('fuForm').classList.remove('open')">Annuler</button>
            </div>
          </div>

          <div id="activitiesView"><span class="t-muted">Aucun suivi enregistré</span></div>
        </div>
      </div>

    </div>

    <!-- COLONNE DROITE -->
    <div>

      <!-- Statut pipeline -->
      <div class="bloc">
        <div class="bloc-head"><span class="bloc-title">Statut prospect</span></div>
        <div class="status-steps" id="statusSteps"></div>
      </div>

      <!-- Unités intéressées -->
      <div class="bloc">
        <div class="bloc-head">
          <span class="bloc-title">Unités intéressées</span>
          <button class="btn btn-outline btn-sm" onclick="openAddUnit()">+</button>
        </div>
        <div class="bloc-body" id="unitsView"><span class="t-muted">Aucune unité liée</span></div>
      </div>

      <!-- Paiements résumé -->
      <div class="bloc" id="payBloc" style="display:none">
        <div class="bloc-head"><span class="bloc-title">Paiements</span></div>
        <div class="bloc-body" id="payView"></div>
      </div>

      <!-- Visites -->
      <div class="bloc">
        <div class="bloc-head"><span class="bloc-title">Visites</span></div>
        <div class="bloc-body" id="visitsView"><span class="t-muted">Aucune visite</span></div>
      </div>

    </div>
  </div>
</div>
</div>

<!-- Modal : Ajouter unité -->
<div id="muOverlay" class="mu-overlay" onclick="if(event.target===this)closeAddUnit()">
  <div class="mu-box">
    <h3>Lier une unité</h3>
    <label>Projet</label>
    <select id="mu_project" class="sel">
      <option value="">— Tous —</option>
      <?php foreach($projects as $pj): ?>
      <option value="<?=(int)$pj['id']?>"><?=htmlspecialchars($pj['code'].' – '.$pj['nom'])?></option>
      <?php endforeach; ?>
    </select>
    <label>Code unité *</label>
    <input id="mu_code" class="inp" placeholder="Ex: A1-101, D06…">
    <label>Niveau d'intérêt</label>
    <select id="mu_interest" class="sel">
      <option value="medium">Moyen</option>
      <option value="hot">Très chaud</option>
      <option value="high">Chaud</option>
      <option value="low">Faible</option>
    </select>
    <div style="display:flex;align-items:center;gap:6px;margin-top:10px">
      <input type="checkbox" id="mu_fav" style="width:auto">
      <label for="mu_fav" style="font-size:13px;font-weight:400;margin:0">Marquer comme favori ⭐</label>
    </div>
    <div class="mu-foot">
      <button class="btn btn-outline btn-sm" onclick="closeAddUnit()">Annuler</button>
      <button class="btn btn-brand btn-sm" onclick="saveUnit()">Enregistrer</button>
    </div>
  </div>
</div>

<script>
<?php include __DIR__.'/includes/crm_js_helpers.php'; ?>

const CLIENT_ID = <?=(int)$id?>;

const CRMSL = {new:'Nouveau',contacted:'Contacté',interested:'Intéressé',visit:'Visite',negotiation:'Négociation',reserved:'Réservé',lost:'Perdu',sold:'Vendu'};
const CRMBG = {new:'#f0f0f0',contacted:'#e0f0ff',interested:'#fef3c7',visit:'#f0ecfa',negotiation:'#ffd',reserved:'#d1fae5',lost:'#fee2e2',sold:'#dbeafe'};
const CRMCL = {new:'#555',contacted:'#145f7d',interested:'#92400e',visit:'#5b21b6',negotiation:'#b45309',reserved:'#065f46',lost:'#991b1b',sold:'#1e40af'};
const ACT_ICON = {call:'📞',whatsapp:'💬',email:'✉️',meeting:'🤝',visit:'🏠',note:'📝',task:'☑️',reminder:'⏰'};
const PROM_STATUS = {draft:'Brouillon',signed:'Signée',converted_to_sale:'Vente confirmée',cancelled:'Annulée'};

let clientData = null;

async function load() {
  try {
    const r = await fetch(`api/crm_client_get.php?id=${CLIENT_ID}`).then(r=>r.json());
    if (r.error) { flash(r.error, 'err'); return; }
    clientData = r;
    renderHeader(r.client);
    renderInfo(r.client);
    renderNeeds(r.needs);
    renderPromises(r.promises || [], r.dossiers || []);
    renderActivities(r.activities || []);
    renderUnits(r.units || []);
    renderStatusSteps(r.client);
    renderVisits(r.visits || []);
    renderPayments(r.promises || []);
  } catch(e) { console.error(e); }
}

function renderHeader(c) {
  document.title = (c.nom||'Client') + ' – PromedApp';
  document.getElementById('hName').textContent = c.nom || '—';
  const meta = [];
  if (c.tel) meta.push(`<a href="tel:${esc(c.tel)}">${esc(c.tel)}</a>`);
  if (c.whatsapp) meta.push(`<a href="https://wa.me/${esc(c.whatsapp)}" target="_blank">💬 WA</a>`);
  if (c.email) meta.push(`<a href="mailto:${esc(c.email)}">${esc(c.email)}</a>`);
  if (c.commercial_nom) meta.push(`👤 ${esc(c.commercial_nom)}`);
  document.getElementById('hMeta').innerHTML = meta.join(' · ');

  const badges = [];
  const st = c.crm_status || 'new';
  badges.push(`<span class="badge" style="background:${CRMBG[st]||'#eee'};color:${CRMCL[st]||'#555'}">${esc(CRMSL[st]||st)}</span>`);
  if (c.interest_level) badges.push(pbadge(c.interest_level));
  document.getElementById('hBadges').innerHTML = badges.join('');
}

function renderInfo(c) {
  const rows = [
    ['Nom', c.nom], ['Téléphone', c.tel], ['WhatsApp', c.whatsapp],
    ['Email', c.email], ['CIN', c.cin], ['Source', c.source],
    ['Profession', c.profession], ['Commercial', c.commercial_nom],
    ['Dernier contact', c.last_contact_at ? c.last_contact_at.substring(0,10) : null],
    ['Prochain suivi', c.next_followup_at ? c.next_followup_at.substring(0,10) : null],
  ];
  document.getElementById('infoView').innerHTML = rows
    .filter(([,v])=>v)
    .map(([l,v])=>`<div class="fl-row"><span class="fl-l">${l}</span><span>${esc(v)}</span></div>`)
    .join('') || '<span class="t-muted">—</span>';

  // Pre-fill edit form
  document.getElementById('e_nom').value = c.nom||'';
  document.getElementById('e_tel').value = c.tel||'';
  document.getElementById('e_wa').value  = c.whatsapp||'';
  document.getElementById('e_email').value = c.email||'';
  document.getElementById('e_cin').value = c.cin||'';
  document.getElementById('e_src').value = c.source||'';
  document.getElementById('e_prof').value = c.profession||'';
  document.getElementById('e_notes').value = c.notes||'';
  if (c.assigned_to) document.getElementById('e_assigned').value = c.assigned_to;
}

function renderNeeds(n) {
  if (!n || !Object.keys(n).length) return;
  const rows = [
    ['Projet', n.project_code||n.project_nom],
    ['Type de bien', n.property_type],
    ['Surface', n.surface_min&&n.surface_max ? `${n.surface_min}–${n.surface_max} m²` : null],
    ['Budget', n.budget_min&&n.budget_max ? `${fmtNum(n.budget_min)} – ${fmtNum(n.budget_max)} DT` : null],
    ['Financement', n.financing_mode],
    ['Délai d\'achat', n.buying_delay],
    ['Étage préféré', n.preferred_floor],
  ];
  const opts = ['wants_parking','wants_cellier','wants_pool','wants_terrace','wants_open_view']
    .filter(k=>n[k]).map(k=>k.replace('wants_','').replace('_',' '));

  let html = rows.filter(([,v])=>v).map(([l,v])=>
    `<div class="fl-row"><span class="fl-l">${l}</span><span>${esc(v)}</span></div>`).join('');
  if (opts.length) html += `<div style="margin-top:8px;display:flex;gap:5px;flex-wrap:wrap">
    ${opts.map(o=>`<span style="padding:2px 8px;background:#e2eef5;color:var(--brand);border-radius:5px;font-size:11px;font-weight:700">${esc(o)}</span>`).join('')}
  </div>`;
  document.getElementById('needsView').innerHTML = html || '<span class="t-muted">—</span>';
}

function renderPromises(promises, dossiers) {
  let items = [...promises];

  // Also surface dossiers that have promises linked (backwards compat)
  if (!items.length && dossiers.length) {
    document.getElementById('promisesView').innerHTML = dossiers.map(d=>`
      <div class="prom-card">
        <div>
          <div class="prom-num">${esc(d.dossier_number||'—')}</div>
          <div class="prom-unit">${esc(d.project_code||'')} ${esc(d.unit_code||'')} · ${esc(d.status||'')}</div>
        </div>
        <div style="display:flex;gap:6px">
          <a href="fiche_client.php?unit_code=${encodeURIComponent(d.unit_code||'')}" class="btn btn-outline btn-sm">Ouvrir fiche</a>
        </div>
      </div>`).join('') || '<span class="t-muted">Aucune promesse</span>';
    return;
  }

  if (!items.length) { document.getElementById('promisesView').innerHTML = '<span class="t-muted">Aucune promesse — cliquez "Créer promesse" pour commencer.</span>'; return; }

  document.getElementById('promisesView').innerHTML = items.map(p=>{
    const st = p.status||'draft';
    const stLabel = PROM_STATUS[st]||st;
    const stColor = st==='signed'?'#065f46':st==='converted_to_sale'?'#1e40af':st==='cancelled'?'#991b1b':'#92400e';
    const stBg    = st==='signed'?'#d1fae5':st==='converted_to_sale'?'#dbeafe':st==='cancelled'?'#fee2e2':'#fef3c7';
    const price   = p.total_ttc||p.prix_ttc||p.sale_price||0;
    return `<div class="prom-card ${p.is_locked?'locked':st}">
      <div>
        <div class="prom-num">${esc(p.promise_number||p.id||'—')}</div>
        <div class="prom-unit">${esc(p.unit_code||'')} ${p.project_code?'· '+esc(p.project_code):''}</div>
        ${price?`<div class="prom-price">${fmtNum(price)} DT TTC</div>`:''}
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px">
        <span class="badge" style="background:${stBg};color:${stColor}">${esc(stLabel)}</span>
        ${p.is_locked?'<span style="font-size:11px;color:#999">🔒 Verrouillée</span>':''}
        <div style="display:flex;gap:5px">
          ${p.unit_code?`<a href="fiche_client.php?unit_code=${encodeURIComponent(p.unit_code)}" class="btn btn-brand btn-sm">Ouvrir</a>`:''}
          ${p.id?`<a href="promise_view.php?id=${p.id}" class="btn btn-outline btn-sm">Détail</a>`:''}
        </div>
      </div>
    </div>`;
  }).join('');
}

function renderActivities(acts) {
  if (!acts.length) { document.getElementById('activitiesView').innerHTML = '<span class="t-muted">Aucun suivi enregistré</span>'; return; }
  document.getElementById('activitiesView').innerHTML = acts.map(a=>{
    const dt = (a.activity_date||a.created_at||'').substring(0,16).replace('T',' ');
    return `<div class="tl-item">
      <div class="tl-icon">${ACT_ICON[a.activity_type]||'📌'}</div>
      <div class="tl-body">
        ${a.subject?`<div class="tl-subj">${esc(a.subject)}</div>`:''}
        ${a.description?`<div class="tl-desc">${esc(a.description)}</div>`:''}
        <div class="tl-meta">${esc(dt)} ${a.user_nom?'· '+esc(a.user_nom):''} ${a.dossier_number?'· '+esc(a.dossier_number):''}</div>
      </div>
    </div>`;
  }).join('');
}

function renderUnits(units) {
  if (!units.length) { document.getElementById('unitsView').innerHTML = '<span class="t-muted">Aucune unité liée</span>'; return; }
  document.getElementById('unitsView').innerHTML = units.map(u=>`
    <div class="uc ${u.is_favorite?'fav':''}">
      <div>
        <div class="uc-code">${u.is_favorite?'⭐ ':''}${esc(u.unit_code)}</div>
        <div class="uc-sub">${esc(u.project_code||'')} ${u.interest_level?'· '+esc(u.interest_level):''}</div>
      </div>
      <a href="fiche_client.php?unit_code=${encodeURIComponent(u.unit_code)}" class="btn btn-brand btn-sm">Créer promesse</a>
    </div>`).join('');
}

function renderStatusSteps(c) {
  const statuses = ['new','contacted','interested','visit','negotiation','reserved','sold','lost'];
  const cur = c.crm_status||'new';
  document.getElementById('statusSteps').innerHTML = statuses.map(s=>`
    <div class="ss ${s===cur?'active':''}"
         style="background:${CRMBG[s]||'#eee'};color:${CRMCL[s]||'#555'}"
         onclick="changeStatus('${s}')">${esc(CRMSL[s]||s)}</div>`).join('');
}

function renderVisits(visits) {
  if (!visits.length) { document.getElementById('visitsView').innerHTML = '<span class="t-muted">Aucune visite</span>'; return; }
  document.getElementById('visitsView').innerHTML = visits.map(v=>`
    <div style="padding:7px 0;border-bottom:1px solid #f0f2f5;font-size:12px">
      <strong>${esc(v.visit_date||'')} ${v.visit_time?v.visit_time.substring(0,5):''}</strong>
      <span class="badge" style="font-size:10px;margin-left:4px">${esc(v.status)}</span><br>
      <span class="t-muted">${esc(v.location||'')} ${v.project_code?'· '+esc(v.project_code):''}</span>
    </div>`).join('');
}

function renderPayments(promises) {
  const withSchedule = promises.filter(p=>p.paid_amount||p.remaining_amount);
  if (!withSchedule.length) return;
  document.getElementById('payBloc').style.display='';
  document.getElementById('payView').innerHTML = withSchedule.map(p=>`
    <div style="margin-bottom:10px">
      <div style="font-size:12px;font-weight:700;margin-bottom:4px">${esc(p.promise_number||'Promesse')}</div>
      <div style="font-size:12px">Payé : <strong>${fmtNum(p.paid_amount||0)} DT</strong></div>
      <div style="font-size:12px">Reste : <strong style="color:var(--err)">${fmtNum(p.remaining_amount||0)} DT</strong></div>
      ${p.id?`<a href="promise_view.php?id=${p.id}#payments" class="btn btn-outline btn-sm" style="margin-top:6px">Voir paiements</a>`:''}
    </div>`).join('');
}

// ── Follow-up ─────────────────────────────────────────────────────────
function openFollowup(type) {
  const now = new Date();
  const pad = n=>String(n).padStart(2,'0');
  const local = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
  const d = document.getElementById('fu_date'); if(d&&!d.value) d.value=local;
  if(type) document.getElementById('fu_type').value=type;
  document.getElementById('fuForm').classList.add('open');
  setTimeout(()=>document.getElementById('fu_subject').focus(),80);
}

async function saveFollowup() {
  const subj = document.getElementById('fu_subject').value.trim();
  const desc = document.getElementById('fu_desc').value.trim();
  if (!subj && !desc) { flash('Objet ou description obligatoire','err'); return; }
  const body = {
    client_id: CLIENT_ID,
    activity_type: document.getElementById('fu_type').value,
    subject: subj, description: desc,
    followup_date: document.getElementById('fu_date').value||null,
    due_date: document.getElementById('fu_due').value||null,
  };
  const r = await fetch('api/crm_followup_save.php',{method:'POST',body:JSON.stringify(body)}).then(r=>r.json());
  if(r.error){flash(r.error,'err');return;}
  flash('Suivi enregistré','ok');
  document.getElementById('fu_subject').value='';
  document.getElementById('fu_desc').value='';
  document.getElementById('fu_due').value='';
  document.getElementById('fuForm').classList.remove('open');
  load();
}

// ── Info save ─────────────────────────────────────────────────────────
function toggleEditInfo() {
  document.getElementById('infoView').style.display = document.getElementById('infoView').style.display==='none' ? '' : 'none';
  document.getElementById('infoEdit').style.display = document.getElementById('infoEdit').style.display==='none' ? '' : 'none';
  document.getElementById('btnEditInfo').textContent = document.getElementById('infoEdit').style.display===''?'Annuler':'Modifier';
}

async function saveInfo() {
  const body = {
    id: CLIENT_ID,
    nom:          document.getElementById('e_nom').value.trim(),
    tel:          document.getElementById('e_tel').value.trim(),
    whatsapp:     document.getElementById('e_wa').value.trim()||null,
    email:        document.getElementById('e_email').value.trim()||null,
    cin:          document.getElementById('e_cin').value.trim()||null,
    source:       document.getElementById('e_src').value||null,
    profession:   document.getElementById('e_prof').value.trim()||null,
    notes:        document.getElementById('e_notes').value.trim()||null,
    assigned_to:  document.getElementById('e_assigned').value||null,
  };
  const r = await fetch('api/crm_client_save.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(r=>r.json());
  if(r.error){flash(r.error,'err');return;}
  flash('Informations sauvegardées','ok');
  toggleEditInfo();
  load();
}

// ── Status change ─────────────────────────────────────────────────────
async function changeStatus(status) {
  if(status === 'lost') {
    const reason = prompt('Raison de la perte (optionnel):');
    const r = await fetch('api/crm_client_status.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:CLIENT_ID,status,lost_reason:reason||''})}).then(r=>r.json());
    if(r.error){flash(r.error,'err');return;}
  } else {
    const r = await fetch('api/crm_client_status.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:CLIENT_ID,status})}).then(r=>r.json());
    if(r.error){flash(r.error,'err');return;}
  }
  flash('Statut mis à jour','ok');
  load();
}

// ── Create promise ─────────────────────────────────────────────────────
function createPromise() {
  // If client has a linked unit, open fiche_client with that unit
  const units = clientData?.units || [];
  const favUnit = units.find(u=>u.is_favorite) || units[0];
  if (favUnit) {
    window.open(`fiche_client.php?unit_code=${encodeURIComponent(favUnit.unit_code)}`, '_self');
  } else {
    // Open fiche_client and let user pick the unit
    window.open('fiche_client.php', '_self');
  }
}

// ── Unit modal ─────────────────────────────────────────────────────────
function openAddUnit() {
  document.getElementById('mu_code').value='';
  document.getElementById('muOverlay').classList.add('open');
}
function closeAddUnit() { document.getElementById('muOverlay').classList.remove('open'); }

async function saveUnit() {
  const code = document.getElementById('mu_code').value.trim();
  if (!code) { alert('Code unité obligatoire'); return; }
  const r = await fetch('api/crm_client_unit_add.php',{
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      client_id: CLIENT_ID,
      project_id: document.getElementById('mu_project').value||null,
      unit_code: code,
      interest_level: document.getElementById('mu_interest').value,
      is_favorite: document.getElementById('mu_fav').checked?1:0,
    })
  }).then(r=>r.json());
  if(r.error){flash(r.error,'err');return;}
  closeAddUnit();
  flash('Unité ajoutée','ok');
  load();
}

// ── Helpers ────────────────────────────────────────────────────────────
function flash(msg, type) {
  const el = document.getElementById('topFlash');
  el.textContent=msg; el.className='flash2 '+type; el.style.display='block';
  setTimeout(()=>{el.style.display='none';},4000);
}
function fmtNum(n) { return Number(n||0).toLocaleString('fr-TN',{minimumFractionDigits:0,maximumFractionDigits:3}); }

load();
</script>
</body></html>
