<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$projects = $pdo->query("SELECT id,code,nom FROM projects ORDER BY code")->fetchAll();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Pipeline CRM – PromedApp</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php include __DIR__.'/includes/crm_style.php'; ?>
<style>
.pipe-filters{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.kb-wrap{overflow-x:auto;padding-bottom:12px}
.kanban{display:flex;gap:10px;min-width:max-content;align-items:flex-start}
.kb-col{background:#f5f7fa;border:1px solid var(--border);border-radius:11px;width:200px;flex-shrink:0}
.kb-head{padding:10px 12px 9px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;border-radius:11px 11px 0 0}
.kb-head-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.kb-cnt{font-size:14px;font-weight:800;color:var(--muted)}
.kb-body{padding:7px;min-height:140px}
.kb-card{background:#fff;border:1px solid var(--border);border-radius:8px;padding:10px 11px;margin-bottom:7px;cursor:grab;transition:box-shadow .12s,opacity .12s;user-select:none;font-size:12.5px}
.kb-card:last-child{margin:0}
.kb-card:hover{box-shadow:0 3px 12px rgba(0,0,0,.1)}
.kb-card.dragging{opacity:.45;cursor:grabbing}
.kb-col.drag-over{background:#e6f2f8;border-color:var(--brand)}
.kbc-name{font-weight:700;font-size:13px;margin-bottom:2px}
.kbc-tel{font-size:11px;color:var(--muted)}
.kbc-meta{display:flex;gap:4px;margin-top:6px;flex-wrap:wrap;align-items:center}
/* Column header colors */
.kh-new{background:#f5f5f5}
.kh-contacted{background:#e0f0ff}
.kh-interested{background:#fef9ec}
.kh-visit{background:#f0ecfa}
.kh-negotiation{background:#fef3c7}
.kh-reserved{background:#d1fae5}
.kh-lost{background:#fee2e2}
/* Modal */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;align-items:center;justify-content:center}
.overlay.open{display:flex}
.modal{background:#fff;border-radius:14px;padding:26px;width:420px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal h2{margin:0 0 16px;font-size:16px;color:var(--brand)}
.modal-btns{display:flex;gap:8px;margin-top:16px}
.nf-date{font-size:11px;padding:2px 7px;border-radius:5px;font-weight:700}
.nf-soon{background:var(--warn-lt);color:var(--warn)}
.nf-late{background:var(--err-lt);color:var(--err)}
.nf-ok{background:var(--ok-lt);color:var(--ok)}
</style>
</head>
<body>
<div class="app">

<!-- ══ PANEL GAUCHE ══ -->
<aside class="panel">
  <div class="panel-title">CRM Promed</div>
  <div class="panel-sub">Pipeline prospects</div>
  <div class="nav-section">
    <div class="nav-section-title">Navigation</div>
    <a class="nav-link" href="crm_dashboard.php">⊟ Dashboard</a>
    <a class="nav-link" href="crm_clients.php">Clients</a>
    <a class="nav-link active" href="crm_pipeline.php">Pipeline</a>
    <a class="nav-link" href="crm_dossiers.php">Dossiers</a>
    <a class="nav-link" href="crm_units.php">Biens</a>
  </div>
  <div class="nav-section">
    <div class="nav-section-title">Projets</div>
    <?php foreach($projects as $pj): ?>
    <a class="nav-link" href="crm_pipeline.php?project=<?=urlencode($pj['code'])?>"><?=htmlspecialchars($pj['code'])?></a>
    <?php endforeach; ?>
  </div>
  <div class="nav-section">
    <div class="nav-section-title">Aide</div>
    <div style="font-size:11px;color:var(--muted);padding:4px 8px;line-height:1.8">
      Glissez les cartes pour changer le statut prospect. Un suivi est enregistré automatiquement.
    </div>
  </div>
</aside>

<!-- ══ CONTENU ══ -->
<div>
  <div class="pipe-filters">
    <select id="pfProject" class="sel" onchange="load()">
      <option value="">Tous les projets</option>
      <?php foreach($projects as $pj): ?>
      <option value="<?=htmlspecialchars($pj['code'])?>"><?=htmlspecialchars($pj['code'])?></option>
      <?php endforeach; ?>
    </select>
    <input id="pfSearch" class="inp" style="width:220px" type="text" placeholder="🔎 Rechercher client…" oninput="debounceLoad()">
    <button class="btn btn-outline btn-sm" onclick="load()">↻ Actualiser</button>
    <a href="fiche_client.php" class="btn btn-brand btn-sm">➕ Nouveau client</a>
    <span id="pipeStats" style="margin-left:auto;font-size:12px;color:var(--muted)"></span>
  </div>

  <div class="kb-wrap">
    <div class="kanban" id="kanban">
      <div class="empty"><span class="spin"></span> Chargement…</div>
    </div>
  </div>
</div>
</div>

<!-- ══ MODAL PERTE ══ -->
<div class="overlay" id="lostModal">
  <div class="modal">
    <h2>Raison de perte</h2>
    <p style="font-size:13px;color:var(--muted);margin:0 0 12px">Pourquoi ce prospect est-il perdu ?</p>
    <select class="sel" id="lostReason" style="width:100%;margin-bottom:8px">
      <option value="price_too_high">Prix trop élevé</option>
      <option value="bought_elsewhere">A acheté ailleurs</option>
      <option value="unreachable">Non joignable</option>
      <option value="insufficient_budget">Budget insuffisant</option>
      <option value="project_not_suitable">Projet ne convient pas</option>
      <option value="decision_postponed">Décision reportée</option>
      <option value="other">Autre</option>
    </select>
    <input type="text" class="inp" id="lostComment" style="width:100%" placeholder="Commentaire (optionnel)">
    <div class="modal-btns">
      <button class="btn btn-danger" style="flex:1" onclick="confirmLost()">Confirmer perte</button>
      <button class="btn btn-outline" style="flex:1" onclick="cancelDrag()">Annuler</button>
    </div>
  </div>
</div>

<!-- ══ MODAL RÉSERVATION ══ -->
<div class="overlay" id="reservedModal">
  <div class="modal">
    <h2>Informations réservation</h2>
    <label style="font-size:12px;font-weight:700;color:var(--muted)">Code unité réservée</label>
    <input type="text" class="inp" id="resUnit" style="width:100%;margin:4px 0 10px" placeholder="Ex: S3-A-201">
    <label style="font-size:12px;font-weight:700;color:var(--muted)">Date d'expiration réservation</label>
    <input type="date" class="inp" id="resExpiry" style="width:100%;margin:4px 0 10px">
    <label style="font-size:12px;font-weight:700;color:var(--muted)">Avance versée (TND)</label>
    <input type="number" class="inp" id="resAdvance" style="width:100%;margin:4px 0 10px" placeholder="0">
    <div class="modal-btns">
      <button class="btn btn-brand" style="flex:1" onclick="confirmReserved()">Confirmer réservation</button>
      <button class="btn btn-outline" style="flex:1" onclick="cancelDrag()">Annuler</button>
    </div>
  </div>
</div>

<script>
<?php include __DIR__.'/includes/crm_js_helpers.php'; ?>

const COLS = [
  {key:'new',         label:'Nouveau',      hcls:'kh-new',         dot:'#9ca3af'},
  {key:'contacted',   label:'Contacté',     hcls:'kh-contacted',   dot:'#145f7d'},
  {key:'interested',  label:'Intéressé',    hcls:'kh-interested',  dot:'#f59e0b'},
  {key:'visit',       label:'Visite',       hcls:'kh-visit',       dot:'#7c3aed'},
  {key:'negotiation', label:'Négociation',  hcls:'kh-negotiation', dot:'#d97706'},
  {key:'reserved',    label:'Réservé',      hcls:'kh-reserved',    dot:'#10b981'},
  {key:'lost',        label:'Perdu',        hcls:'kh-lost',        dot:'#ef4444'},
];

let _drag = null, _dragTargetCol = null, _dragClientId = null;

function renderKanban(clients) {
  const grouped = {};
  COLS.forEach(c => grouped[c.key] = []);
  clients.forEach(c => {
    const col = c.status || 'new';
    if (grouped[col]) grouped[col].push(c);
    else grouped['new'].push(c);
  });

  const total = clients.length;
  document.getElementById('pipeStats').textContent = `${total} prospect${total>1?'s':''}`;

  document.getElementById('kanban').innerHTML = COLS.map(col => {
    const cards = grouped[col.key] || [];
    return `<div class="kb-col" data-col="${col.key}"
                 ondragover="onOver(event,this)" ondrop="onDrop(event,this)" ondragleave="onLeave(event,this)">
      <div class="kb-head ${col.hcls}">
        <div class="kb-head-label" style="color:${col.dot}">${col.label}</div>
        <div class="kb-cnt">${cards.length}</div>
      </div>
      <div class="kb-body">
        ${cards.map(c => {
          const nf = c.next_followup_at;
          let nfBadge = '';
          if (nf) {
            const days = Math.ceil((new Date(nf)-new Date())/86400000);
            const cls  = days < 0 ? 'nf-late' : days <= 2 ? 'nf-soon' : 'nf-ok';
            nfBadge = `<span class="nf-date ${cls}">🔔 ${days<0?'J+'+Math.abs(days):days===0?'Auj.':'J-'+days}</span>`;
          }
          return `<div class="kb-card" draggable="true"
               data-id="${c.id}"
               ondragstart="onStart(event,this)"
               ondragend="onEnd(event,this)"
               onclick="location='crm_fiche_client.php?id=${c.id}'">
            <div class="kbc-name">${esc(c.nom||'—')}</div>
            <div class="kbc-tel">${esc(c.tel||'')}</div>
            <div class="kbc-meta">
              ${c.project_code?`<span class="badge bs-interested" style="font-size:9px">${esc(c.project_code)}</span>`:''}
              ${c.property_type?`<span style="font-size:10px;color:var(--muted)">${esc(c.property_type)}</span>`:''}
              ${pbadge(c.interest_level)}
              ${nfBadge}
            </div>
            ${c.commercial?`<div style="font-size:10px;color:var(--muted);margin-top:4px">👤 ${esc(c.commercial)}</div>`:''}
          </div>`;
        }).join('')}
      </div>
    </div>`;
  }).join('');
}

// ── Drag & Drop ────────────────────────────────────────────
function onStart(e, el) {
  _drag = el;
  _dragClientId = parseInt(el.dataset.id);
  el.classList.add('dragging');
  e.dataTransfer.effectAllowed = 'move';
}
function onEnd(e, el) {
  el.classList.remove('dragging');
  document.querySelectorAll('.kb-col').forEach(c => c.classList.remove('drag-over'));
}
function onOver(e, col) { e.preventDefault(); e.dataTransfer.dropEffect='move'; col.classList.add('drag-over'); }
function onLeave(e, col) { col.classList.remove('drag-over'); }

function onDrop(e, col) {
  e.preventDefault(); col.classList.remove('drag-over');
  if (!_drag) return;
  const newStatus = col.dataset.col;
  _dragTargetCol = col;

  if (newStatus === 'lost') {
    document.getElementById('lostModal').classList.add('open');
    return;
  }
  if (newStatus === 'reserved') {
    document.getElementById('reservedModal').classList.add('open');
    document.getElementById('resExpiry').value = '';
    document.getElementById('resUnit').value = '';
    return;
  }
  applyDrop(newStatus, {});
}

function cancelDrag() {
  document.getElementById('lostModal').classList.remove('open');
  document.getElementById('reservedModal').classList.remove('open');
  _drag = null; _dragTargetCol = null; _dragClientId = null;
}

function confirmLost() {
  const reason  = document.getElementById('lostReason').value;
  const comment = document.getElementById('lostComment').value.trim();
  document.getElementById('lostModal').classList.remove('open');
  applyDrop('lost', {lost_reason: reason + (comment?' — '+comment:'')});
}

function confirmReserved() {
  const unit   = document.getElementById('resUnit').value.trim();
  const expiry = document.getElementById('resExpiry').value;
  const adv    = parseFloat(document.getElementById('resAdvance').value)||0;
  document.getElementById('reservedModal').classList.remove('open');
  applyDrop('reserved', {unit_code: unit, reservation_expires: expiry, advance: adv});
}

async function applyDrop(newStatus, extra) {
  if (!_drag || !_dragClientId) return;
  // Move card visually
  if (_dragTargetCol) {
    const body = _dragTargetCol.querySelector('.kb-body');
    if (body) {
      body.appendChild(_drag);
      _drag.classList.remove('dragging');
      const cnt = _dragTargetCol.querySelector('.kb-cnt');
      if (cnt) cnt.textContent = _dragTargetCol.querySelectorAll('.kb-card').length;
    }
  }
  // Persist
  try {
    await fetch('api/crm_client_status.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({id: _dragClientId, status: newStatus, ...extra})
    });
  } catch(err) { console.error(err); }
  _drag = null; _dragTargetCol = null; _dragClientId = null;
}

// ── Load ──────────────────────────────────────────────────
let _t;
function debounceLoad() { clearTimeout(_t); _t = setTimeout(load, 320); }

async function load() {
  document.getElementById('kanban').innerHTML = '<div class="empty"><span class="spin"></span> Chargement…</div>';
  try {
    const p = new URLSearchParams({
      project: document.getElementById('pfProject').value,
      q:       document.getElementById('pfSearch').value.trim(),
      limit:   300
    });
    const r = await fetch('api/crm_pipeline_clients.php?'+p).then(r=>r.json());
    if (r.error) { console.error(r.error); return; }
    renderKanban(r.clients||[]);
  } catch(e) { console.error(e); }
}

// Init URL params
const _up = new URLSearchParams(location.search);
if (_up.get('project')) document.getElementById('pfProject').value = _up.get('project');

load();
</script>
</body></html>
