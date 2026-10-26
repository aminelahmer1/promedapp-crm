const PROJECT_UNITS_URL = 'api/project_units.php?project=AVA';
const PROJECT_ACCESSORIES_URL = 'api/project_accessories.php?project=AVA';
const BASEMENT_POSITIONS_URL = 'api/ava_basement_positions.php';
const ASSET_DETAILS_URL = 'api/ava_asset_details.php';
const STATUS_SAVE_URL = 'api/ava_update_status.php';
const CLIENT_GET_URL = 'api/client_get.php';

const FLOORS = [
  { id: 'RDC', label: 'RDC', image: 'assets/plans/ava/rdc.png' },
  { id: '1ER ÉTAGE', label: '1er étage', image: 'assets/plans/ava/1er.jpg' },
  { id: '2ÈME ÉTAGE', label: '2ème étage', image: 'assets/plans/ava/2eme.jpg' },
  { id: '3ÉME ÉTAGE', label: '3éme étage', image: 'assets/plans/ava/3eme.jpg' },
  { id: 'SOUS-SOL', label: 'Sous-sol', image: 'assets/plans/ava/sous_sol.png' }
];

const POSITIONS = {
  'RDC': {
    'D1': { x: 1.8, y: 2.3, w: 25, h: 39},
    'D2': { x: 27, y: 2.5, w: 19, h: 39 },
    'D3': { x: 46.3, y: 2.5, w: 19, h: 39},
    'D4': { x: 66, y: 2.5, w: 22.7, h:39 },
    'D8': { x: 1.8, y: 45, w: 25, h: 46 },
    'D7': { x: 27, y: 45, w: 19, h: 46 },
    'D6': { x: 46.3, y: 45, w: 19, h: 46 },
    'D5': { x: 66, y: 45, w: 32, h: 46 }
  },
  '1ER ÉTAGE': {
    'A1-1': { x: 1.5, y: 2.0, w: 46.5, h: 46.5 },
    'A1-2': { x: 52.0, y: 2.0, w: 46.5, h: 46.5 },
    'A1-4': { x: 1.5, y: 50.5, w: 46.5, h: 46.0 },
    'A1-3': { x: 52.0, y: 50.5, w: 46.5, h: 46.0 }
  },
  '2ÈME ÉTAGE': {
    'A2-1': { x: 1.5, y: 2.0, w: 46.5, h: 46.5 },
    'A2-2': { x: 52.0, y: 2.0, w: 46.5, h: 46.5 },
    'A2-4': { x: 1.5, y: 50.5, w: 46.5, h: 46.0 },
    'A2-3': { x: 52.0, y: 50.5, w: 46.5, h: 46.0 }
  },
  '3ÉME ÉTAGE': {
    'A3-1': { x: 1.5, y: 2.0, w: 46.5, h: 46.5 },
    'A3-2': { x: 52.0, y: 2.0, w: 46.5, h: 46.5 },
    'A3-4': { x: 1.5, y: 50.5, w: 46.5, h: 46.0 },
    'A3-3': { x: 52.0, y: 50.5, w: 46.5, h: 46.0 }
  },
};

const state = {
  floor: FLOORS[0].id,
  type: 'ALL',
  view: 'plan',
  selectedCode: null,
  assets: [],
  basementPositions: []
};

const els = {};

function q(id){ return document.getElementById(id); }
function fmtMoney(n){ return (Math.round((Number(n) || 0) * 100) / 100).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
function fmtSurface(n){ return `${(Math.round((Number(n) || 0) * 100) / 100).toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2})} m²`; }
function floorKey(v){ return (v || '').toString().trim().toUpperCase().replace(/EME/g, 'ÈME').replace(/ER /g, 'ER '); }
function statusClass(label){ return label === 'Vendu' ? 'vendu' : (label === 'Réservé' ? 'reserve' : 'dispo'); }
function withCache(url){ return url + (url.includes('?') ? '&' : '?') + '_=' + Date.now(); }

// Les prix arrivent déjà calculés pour le mois par le serveur (même calcul que la Liste des prix)
function indexedPrice(price){
  return Number(price) || 0;
}

function isMultiFloorMode(){
  return state.floor === 'ALL';
}

function floorRank(id){
  const idx = FLOORS.findIndex(f => f.id === id);
  return idx === -1 ? 999 : idx;
}

function cacheDom(){
  ['selectFloor','selectType','selectBlock','planImage','unitOverlay','planMissing',
   'viewPlanBtn','viewListBtn','listWrapper','planWrapper','onlyAvailable','unitsTbody',
   'unitEmpty','unitCard','uCode','uMeta','uBlock','uFloor','uType','uSurface',
   'uPriceBase','uPriceMonth','uTotalMonth','uStatusBadge','uStatusSelect','uSaveBtn',
   'unitPreviewImg','clientSection','cName','cPhone','cEmail',
   'promiseSection','promiseBtn','promiseContent','promisePaiementsBtn',
   'planModal','planModalImg','planModalClose','planModalDownload','planModalPrint',
   'planModalSend','planModalShare','multiFloorContainer','sousSolPanel'
  ].forEach(id => els[id] = q(id));
  els.planInner = document.querySelector('.ava-plan-inner');
}

function currentFloorConfig(){
  return FLOORS.find(f => f.id === state.floor) || FLOORS[0];
}

function isBasement(){
  return state.floor === 'SOUS-SOL';
}

async function loadAssets(){
  const [unitsRes, accRes, basementRes] = await Promise.all([
    fetch(withCache(PROJECT_UNITS_URL)),
    fetch(withCache(PROJECT_ACCESSORIES_URL)),
    fetch(withCache(BASEMENT_POSITIONS_URL)).catch(() => null)
  ]);

  const unitsPayload = await unitsRes.json();
  const units = (unitsPayload.units || []).map((u) => ({
    code: u.code,
    floor: floorKey(u.floor),
    type: u.type || '',
    surface: Number(u.surface || 0),
    price_m2_base: Number(u.price_m2_base || 0),
    price_ht: Number(u.price_ht || 0),
    status: u.status || 'Disponible',
    plan_image: u.plan_image || '',
    block: u.block || 'AVA',
    kind: 'UNIT'
  }));

  const accPayload = accRes.ok ? await accRes.json() : { accessories: [] };
  const accessories = (accPayload.accessories || []).map((a) => ({
    code: a.code,
    floor: 'SOUS-SOL',
    type: a.type || a.accessory_type || '',
    surface: Number(a.surface || 0),
    price_m2_base: Number(a.price_m2_base || 0),
    price_ht: Number(a.price_ht || 0),
    price_ttc: Number(a.price_ttc || 0),
    status: a.status || 'Disponible',
    plan_image: a.plan_image || '',
    block: a.block || 'AVA',
    kind: 'ACCESSORY'
  }));

  let basementPositions = [];
  if (basementRes && basementRes.ok) {
    const bp = await basementRes.json();
    basementPositions = bp.positions || [];
  }

  state.assets = [...units, ...accessories];
  state.basementPositions = basementPositions;
}

function initFloorSelect(preferredFloor = ''){
  els.selectFloor.querySelectorAll('option:not([value="ALL"])').forEach(o => o.remove());
  FLOORS.forEach((f) => {
    const opt = document.createElement('option');
    opt.value = f.id;
    opt.textContent = f.label;
    els.selectFloor.appendChild(opt);
  });
  const floorIds = FLOORS.map(f => f.id);
  let selected;
  if (preferredFloor === 'ALL') {
    selected = 'ALL';
  } else {
    selected = floorIds.includes(preferredFloor) ? preferredFloor : (floorIds[0] || '');
  }
  els.selectFloor.value = selected;
  state.floor = selected;
}

function initTypeSelect(){
  if (!els.selectType) return;
  const types = Array.from(new Set(
    state.assets.filter(a => a.kind !== 'ACCESSORY').map(a => a.type).filter(Boolean)
  )).sort((a, b) => a.localeCompare(b, 'fr'));
  els.selectType.innerHTML = '<option value="ALL">Toutes</option>';
  types.forEach(t => {
    const opt = document.createElement('option');
    opt.value = t;
    opt.textContent = t;
    els.selectType.appendChild(opt);
  });
}

function ensurePlanSelection(){
  const allFloorIds = FLOORS.map(f => f.id);
  if (!state.floor || state.floor === 'ALL' || !allFloorIds.includes(state.floor)) {
    initFloorSelect(allFloorIds[0] || '');
  }
}

function renderPlan(){
  if (state.view !== 'plan') return;

  if (isMultiFloorMode()) {
    els.planInner.classList.add('hidden');
    els.planWrapper.classList.remove('is-sous-sol');
    els.multiFloorContainer.classList.remove('hidden');
    els.sousSolPanel.classList.add('hidden');
    renderMultiFloor();
    return;
  }

  els.planInner.classList.remove('hidden');
  els.multiFloorContainer.classList.add('hidden');

  const isSousSol = isBasement();
  els.planWrapper.classList.toggle('is-sous-sol', isSousSol);

  const floor = currentFloorConfig();
  els.planImage.src = floor.image;
  els.planMissing.classList.add('hidden');
  els.unitOverlay.innerHTML = '';

  if (isSousSol) {
    renderSousSol();
    return;
  }

  els.sousSolPanel.classList.add('hidden');

  const render = () => {
    els.unitOverlay.innerHTML = '';
    const floorPositions = POSITIONS[state.floor] || {};
    Object.entries(floorPositions).forEach(([code, pos]) => {
      const asset = state.assets.find(a => a.code === code);
      if (!asset || asset.kind === 'ACCESSORY') return;
      if (state.type !== 'ALL' && asset.type !== state.type) return;
      const node = document.createElement('button');
      node.type = 'button';
      node.className = `unit-rect unit-${statusClass(asset.status)}`;
      node.style.left = `${pos.x}%`;
      node.style.top = `${pos.y}%`;
      node.style.width = `${pos.w}%`;
      node.style.height = `${pos.h}%`;
      node.innerHTML = `<span>${code}</span>`;
      if (state.selectedCode === code) node.classList.add('is-selected');
      node.addEventListener('click', () => selectAsset(code));
      els.unitOverlay.appendChild(node);
    });
  };

  if (els.planImage.complete && els.planImage.naturalWidth) render();
  else els.planImage.onload = render;
}

function renderMultiFloor(){
  const container = els.multiFloorContainer;
  container.innerHTML = '';

  const floorsToShow = FLOORS.filter(f => f.id !== 'SOUS-SOL');

  floorsToShow.forEach(floor => {
    const floorPositions = POSITIONS[floor.id] || {};
    const hasMatchingUnits = Object.keys(floorPositions).some(code => {
      const asset = state.assets.find(a => a.code === code);
      if (!asset || asset.kind === 'ACCESSORY') return false;
      if (state.type !== 'ALL' && asset.type !== state.type) return false;
      return true;
    });
    if (!hasMatchingUnits) return;

    const section = document.createElement('div');
    section.className = 'mf-section';
    section.dataset.floor = floor.id;

    const label = document.createElement('div');
    label.className = 'mf-label';
    label.textContent = floor.label;
    section.appendChild(label);

    const planInner = document.createElement('div');
    planInner.className = 'mf-plan-inner';

    const img = document.createElement('img');
    img.className = 'mf-plan-img';
    img.src = floor.image;
    img.alt = `Plan ${floor.label}`;
    planInner.appendChild(img);

    const overlay = document.createElement('div');
    overlay.className = 'mf-overlay';
    planInner.appendChild(overlay);

    section.appendChild(planInner);
    container.appendChild(section);

    const drawOverlay = () => drawMfOverlay(overlay, floor.id);
    if (img.complete && img.naturalWidth) drawOverlay();
    else img.onload = drawOverlay;
  });
}

function drawMfOverlay(overlay, floorId){
  overlay.innerHTML = '';
  const floorPositions = POSITIONS[floorId] || {};
  Object.entries(floorPositions).forEach(([code, pos]) => {
    const asset = state.assets.find(a => a.code === code);
    if (!asset || asset.kind === 'ACCESSORY') return;
    if (state.type !== 'ALL' && asset.type !== state.type) return;
    const node = document.createElement('button');
    node.type = 'button';
    node.className = `unit-rect unit-${statusClass(asset.status)}`;
    node.style.left = `${pos.x}%`;
    node.style.top = `${pos.y}%`;
    node.style.width = `${pos.w}%`;
    node.style.height = `${pos.h}%`;
    node.innerHTML = `<span>${code}</span>`;
    if (state.selectedCode === code) node.classList.add('is-selected');
    node.addEventListener('click', () => selectAsset(code));
    overlay.appendChild(node);
  });
}

function redrawMultiFloorOverlays(){
  els.multiFloorContainer.querySelectorAll('.mf-section').forEach(section => {
    const floorId = section.dataset.floor;
    const overlay = section.querySelector('.mf-overlay');
    if (overlay) drawMfOverlay(overlay, floorId);
  });
}

function renderSousSol(){
  const accessories = state.assets.filter(a => a.kind === 'ACCESSORY');
  if (!accessories.length) {
    els.sousSolPanel.classList.add('hidden');
    return;
  }

  const parkings = accessories.filter(a => (a.type || '').toUpperCase().includes('PARK'));
  const celliers = accessories.filter(a => !(a.type || '').toUpperCase().includes('PARK'));

  const stCls = { 'Disponible': 'd', 'Réservé': 'r', 'Vendu': 'v' };
  let html = '';

  if (parkings.length) {
    html += `<div class="ss-section">
      <div class="ss-section-head">
        <span class="ss-section-title">Parkings</span>
      </div>
      <div class="ss-park-grid">`;
    parkings.forEach(p => {
      const cls = stCls[p.status] || 'd';
      const pHt = p.price_ht || Math.round(indexedPrice(p.price_m2_base) * p.surface * 100) / 100;
      const priceStr = pHt ? ` – ${fmtMoney(pHt)} TND HT` : '';
      html += `<button class="ss-acc-btn ${cls}" title="${p.code} – ${p.status}${priceStr}" onclick="selectAsset('${p.code}')">
        <span class="ss-code">${p.code}</span>
        <span class="ss-sub">${p.status}</span>
      </button>`;
    });
    html += `</div></div>`;
  }

  if (celliers.length) {
    html += `<div class="ss-section">
      <div class="ss-section-head">
        <span class="ss-section-title">Celliers</span>
      </div>
      <div class="ss-cell-list">`;
    celliers.forEach(c => {
      const cls = stCls[c.status] || 'd';
      const pHt = c.price_ht || Math.round(indexedPrice(c.price_m2_base) * c.surface * 100) / 100;
      html += `<button class="ss-cell-card ${cls}" onclick="selectAsset('${c.code}')">
        <span class="ss-code">${c.code}</span>
        <span class="ss-surf">${c.surface ? fmtSurface(c.surface) : ''}</span>
        <span class="ss-badge">${pHt ? fmtMoney(pHt) + ' TND' : ''}</span>
      </button>`;
    });
    html += `</div></div>`;
  }

  els.sousSolPanel.innerHTML = html;
  els.sousSolPanel.classList.remove('hidden');
  els.unitEmpty.classList.add('hidden');
  els.unitCard.classList.add('hidden');
}

function renderList(){
  const onlyAvailable = !!els.onlyAvailable?.checked;
  const rows = state.assets.filter(a => {
    if (a.kind === 'ACCESSORY') return false;
    if (state.floor !== 'ALL' && a.floor !== state.floor) return false;
    if (state.type !== 'ALL' && a.type !== state.type) return false;
    if (onlyAvailable && a.status !== 'Disponible') return false;
    return true;
  });
  els.unitsTbody.innerHTML = rows.map(asset => {
    const pht = indexedPrice(asset.price_m2_base);
    const totalHt = asset.price_ht || Math.round(pht * (asset.surface || 0) * 100) / 100;
    const floorLabel = FLOORS.find(f => f.id === asset.floor)?.label || asset.floor;
    return `<tr data-code="${asset.code}">
      <td>${asset.code}</td>
      <td>${floorLabel}</td>
      <td>${asset.type || '-'}</td>
      <td>${fmtSurface(asset.surface)}</td>
      <td>${fmtMoney(pht)} TND</td>
      <td>${fmtMoney(totalHt)} TND</td>
      <td><span class="row-badge ${statusClass(asset.status)}">${asset.status}</span></td>
    </tr>`;
  }).join('');
  els.unitsTbody.querySelectorAll('tr').forEach(tr => tr.addEventListener('click', () => selectAsset(tr.dataset.code)));
}

function syncView(){
  const isPlan = state.view === 'plan';
  els.planWrapper.classList.toggle('hidden', !isPlan);
  els.listWrapper.classList.toggle('hidden', isPlan);
  els.viewPlanBtn.classList.toggle('is-active', isPlan);
  els.viewListBtn.classList.toggle('is-active', !isPlan);
}

async function fetchDetails(code){
  const asset = state.assets.find(a => a.code === code);
  const kind = asset?.kind || 'UNIT';
  const res = await fetch(withCache(`${ASSET_DETAILS_URL}?kind=${encodeURIComponent(kind)}&code=${encodeURIComponent(code)}`));
  if (!res.ok) throw new Error('Impossible de charger les détails');
  return res.json();
}

async function fillClient(code, kind){
  if (kind === 'ACCESSORY') {
    els.clientSection.style.display = 'none';
    return;
  }
  try {
    const res = await fetch(withCache(`${CLIENT_GET_URL}?project=AVA&unit_code=${encodeURIComponent(code)}`));
    const json = await res.json();
    if (!json || json.error) {
      els.clientSection.style.display = 'none';
      return;
    }
    els.clientSection.style.display = '';
    els.cName.textContent = json.nom || '-';
    els.cPhone.textContent = json.tel || '-';
    els.cEmail.textContent = json.email || '-';
  } catch (e) {
    els.clientSection.style.display = 'none';
  }
}

async function fillPromise(code, kind) {
  const sec     = els.promiseSection;
  const content = els.promiseContent;
  const paiBtn  = els.promisePaiementsBtn;

  if (kind === 'ACCESSORY') { sec.style.display = 'none'; return; }

  content.innerHTML = '<span style="color:#9ca3af">Chargement...</span>';
  paiBtn.style.display = 'none';

  try {
    const res  = await fetch(withCache(`api/promise_get.php?unit_code=${encodeURIComponent(code)}`));
    const data = await res.json();

    if (!data.promise || data.error || data.promise.error === 'not_found') {
      content.innerHTML = '<span style="color:#9ca3af">Aucune promesse enregistrée.</span>';
      return;
    }

    const p     = data.promise;
    const sch   = data.schedule;
    const insts = data.installments || [];

    const SC = {
      draft:              { bg:'#e2eef5', color:'#145f7d', label:'Brouillon' },
      signed:             { bg:'#ecfdf5', color:'#166534', label:'Signée ✅' },
      cancelled:          { bg:'#fef2f2', color:'#991b1b', label:'Annulée'  },
      converted_to_sale:  { bg:'#fef3c7', color:'#92400e', label:'Vendue'   },
    };
    const sc    = SC[p.status] || { bg:'#f4f6f8', color:'#555', label: p.status };
    const paid  = sch ? parseFloat(sch.paid_amount   || 0) : 0;
    const total = sch ? parseFloat(sch.total_amount  || 0) : parseFloat(p.sale_price || 0);
    const rem   = sch ? parseFloat(sch.remaining_amount || 0) : Math.max(0, total - paid);
    const prog  = total > 0 ? Math.round(paid / total * 1000) / 10 : 0;

    let html = `<div style="background:${sc.bg};border-radius:8px;padding:8px 10px;margin-bottom:8px">
      <div style="font-size:10px;font-weight:700;color:${sc.color};letter-spacing:.05em">${p.promise_number || '—'}</div>
      <div style="font-size:13px;font-weight:800;color:${sc.color};margin-top:2px">${sc.label}</div>
      ${p.sale_price ? `<div style="font-size:11px;color:${sc.color};margin-top:3px;opacity:.85">Total : ${fmtMoney(parseFloat(p.sale_price))} TND TTC</div>` : ''}
    </div>`;

    if (total > 0) {
      html += `<div style="margin-bottom:8px">
        <div style="font-size:11px;color:#6b7280;margin-bottom:3px">
          Payé : <strong style="color:#111">${fmtMoney(paid)} TND</strong> / ${fmtMoney(total)} TND
          <span style="float:right;color:${prog>=100?'#16a34a':'#374151'};font-weight:700">${prog}%</span>
        </div>
        <div style="height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden">
          <div style="width:${Math.min(100,prog)}%;height:100%;background:${prog>=100?'#16a34a':'#145f7d'};border-radius:3px;transition:width .3s"></div>
        </div>
        ${rem > 0 ? `<div style="font-size:10px;color:#6b7280;margin-top:3px">Reste : <strong>${fmtMoney(rem)} TND</strong></div>` : ''}
      </div>`;
    }

    if (insts.length > 0) {
      const IS = {
        pending:        { label:'En attente', color:'#6b7280' },
        partially_paid: { label:'Partiel',    color:'#b45309' },
        paid:           { label:'✓ Payée',    color:'#16a34a' },
        overdue:        { label:'⚠ Retard',   color:'#dc2626' },
      };
      html += `<div style="border-top:1px solid #e5e7eb;padding-top:6px">
        <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px">Échéancier (${insts.length} tranches)</div>`;
      insts.forEach(inst => {
        const is  = IS[inst.status] || { label: inst.status, color:'#6b7280' };
        const due = inst.due_date ? inst.due_date.split('-').reverse().join('/') : '—';
        html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid #f3f4f6">
          <span style="color:#374151;font-size:11px">${inst.label || '—'}</span>
          <span style="text-align:right">
            <span style="color:#111;font-weight:600;font-size:11px">${fmtMoney(parseFloat(inst.expected_amount))} TND</span>
            <span style="display:block;font-size:9px;color:${is.color}">${is.label} · ${due}</span>
          </span>
        </div>`;
      });
      html += `</div>`;
    }

    content.innerHTML = html;

    if (p.id) {
      paiBtn.href = `paiements.php?promise_id=${p.id}`;
      paiBtn.style.display = '';
    }

  } catch(e) {
    content.innerHTML = '<span style="color:#9ca3af">Erreur de chargement.</span>';
  }
}

async function selectAsset(code){
  state.selectedCode = code;
  if (isMultiFloorMode()) {
    redrawMultiFloorOverlays();
  } else {
    renderPlan();
  }
  renderList();
  els.sousSolPanel.classList.add('hidden');
  els.unitEmpty.classList.add('hidden');
  els.unitCard.classList.remove('hidden');

  const asset = state.assets.find(a => a.code === code);
  const kind = asset?.kind || 'UNIT';
  const detail = await fetchDetails(code);

  const pht = indexedPrice(detail.price_m2_ht || detail.price_m2_base || 0);
  const pttc = detail.price_m2_ttc ? Number(detail.price_m2_ttc) : Math.round(pht * 1.19 * 100) / 100;
  const totalHt = detail.price_ht ? Number(detail.price_ht) : Math.round(pht * (detail.surface || 0) * 100) / 100;
  const totalTtc = detail.price_ttc ? Number(detail.price_ttc) : Math.round(totalHt * 1.19 * 100) / 100;

  const floorLabel = FLOORS.find(f => f.id === detail.floor)?.label || detail.floor || currentFloorConfig().label;

  els.uCode.textContent = detail.code;
  els.uMeta.textContent = `${detail.block || 'AVA'} · ${floorLabel}`;
  els.uBlock.textContent = detail.block || 'AVA';
  els.uFloor.textContent = floorLabel;
  els.uType.textContent = detail.type || detail.designation || '-';
  els.uSurface.textContent = fmtSurface(detail.surface || 0);
  els.uPriceBase.textContent = `${fmtMoney(pht)} TND/m² HT`;
  els.uPriceMonth.textContent = `${fmtMoney(pttc)} TND/m² TTC`;
  els.uTotalMonth.textContent = `${fmtMoney(totalHt)} TND HT · ${fmtMoney(totalTtc)} TND TTC`;
  els.uStatusBadge.textContent = detail.status;
  els.uStatusBadge.className = `status-badge badge-${statusClass(detail.status)}`;
  els.uStatusSelect.value = detail.status;
  els.uSaveBtn.onclick = () => saveStatus(detail.code, kind, detail.status);

  if (detail.plan_image) {
    els.unitPreviewImg.src = detail.plan_image;
    els.unitPreviewImg.classList.remove('hidden');
    els.unitPreviewImg.onclick = () => openModal(detail.plan_image);
    els.unitPreviewImg.style.cursor = 'zoom-in';
  } else {
    els.unitPreviewImg.removeAttribute('src');
    els.unitPreviewImg.classList.add('hidden');
  }

  if (kind === 'UNIT') {
    els.promiseSection.style.display = '';
    els.promiseBtn.onclick = () => {
      window.location.href = `fiche_client.php?project=AVA&unit_code=${encodeURIComponent(code)}`;
    };
  } else {
    els.promiseSection.style.display = 'none';
  }

  fillClient(detail.code, kind);
  fillPromise(detail.code, kind);
}

async function saveStatus(code, kind, oldStatus){
  const status = els.uStatusSelect.value;
  const res = await fetch(STATUS_SAVE_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ kind, code, status })
  });
  const json = await res.json();
  if (!res.ok || json.error) {
    alert(json.error || 'Erreur lors de l’enregistrement du statut.');
    return;
  }

  const item = state.assets.find(a => a.code === code);
  if (item) item.status = status;

  els.uStatusBadge.textContent = status;
  els.uStatusBadge.className = `status-badge badge-${statusClass(status)}`;
  els.promiseSection.style.display = kind === 'ACCESSORY' ? 'none' : '';
  renderPlan();
  renderList();

  if (kind === 'UNIT' && oldStatus !== 'Vendu' && status === 'Vendu') {
    window.location.href = `fiche_client.php?project=AVA&unit_code=${encodeURIComponent(code)}`;
  }
}

function openModal(src){
  els.planModalImg.src = src;
  els.planModal.classList.remove('hidden');

  els.planModalDownload.onclick = () => {
    const a = document.createElement('a');
    a.href = src;
    a.download = src.split('/').pop() || 'plan.jpg';
    a.click();
  };

  els.planModalPrint.onclick = () => {
    const win = window.open('', '_blank');
    win.document.head.innerHTML = '<title>Plan</title><style>body{margin:0;display:flex;justify-content:center;align-items:center;min-height:100vh;}img{max-width:100%;}</style>';
    const img = win.document.createElement('img');
    img.src = src;
    img.onload = () => { win.print(); win.close(); };
    win.document.body.appendChild(img);
  };

  els.planModalSend.onclick = () => {
    const subject = encodeURIComponent('Plan - ' + (src.split('/').pop() || 'unité'));
    const body = encodeURIComponent('Veuillez trouver ci-joint le plan de l\'unité.\n\nLien : ' + window.location.origin + '/' + src);
    window.location.href = `mailto:?subject=${subject}&body=${body}`;
  };

  if (navigator.share) {
    els.planModalShare.style.display = '';
    els.planModalShare.onclick = async () => {
      try {
        const resp = await fetch(src);
        const blob = await resp.blob();
        const ext  = src.split('.').pop().toLowerCase();
        const mime = ext === 'png' ? 'image/png' : 'image/jpeg';
        const file = new File([blob], src.split('/').pop(), { type: mime });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
          await navigator.share({ files: [file], title: 'Plan AVA' });
        } else {
          await navigator.share({ title: 'Plan AVA', url: window.location.origin + '/' + src });
        }
      } catch (e) { /* annulé par l'utilisateur */ }
    };
  } else {
    els.planModalShare.style.display = 'none';
  }
}

function closeModal(){
  els.planModal.classList.add('hidden');
  els.planModalImg.removeAttribute('src');
}

function clearSelection(){
  state.selectedCode = null;
  const showSousSol = isBasement() && state.view === 'plan' && !isMultiFloorMode();
  if (showSousSol) {
    els.sousSolPanel.classList.remove('hidden');
    els.unitEmpty.classList.add('hidden');
  } else {
    els.unitEmpty.classList.remove('hidden');
    els.sousSolPanel.classList.add('hidden');
  }
  els.unitCard.classList.add('hidden');
}

function bindEvents(){
  els.selectFloor.addEventListener('change', () => {
    state.floor = els.selectFloor.value;
    state.selectedCode = null;
    els.unitCard.classList.add('hidden');
    if (isBasement() && !isMultiFloorMode()) {
      els.unitEmpty.classList.add('hidden');
    } else {
      els.unitEmpty.classList.remove('hidden');
      els.sousSolPanel.classList.add('hidden');
    }
    renderPlan();
    renderList();
  });

  if (els.selectType) {
    els.selectType.addEventListener('change', () => {
      state.type = els.selectType.value;
      state.selectedCode = null;
      els.unitCard.classList.add('hidden');
      if (!(isBasement() && !isMultiFloorMode())) {
        els.unitEmpty.classList.remove('hidden');
        els.sousSolPanel.classList.add('hidden');
      }
      renderPlan();
      renderList();
    });
  }

  els.onlyAvailable?.addEventListener('change', renderList);
  els.viewPlanBtn.addEventListener('click', () => { state.view = 'plan'; syncView(); renderPlan(); });
  els.viewListBtn.addEventListener('click', () => { state.view = 'list'; syncView(); });
  els.planModalClose.addEventListener('click', closeModal);
  els.planModal.addEventListener('click', (e) => {
    if (e.target === els.planModal || e.target.classList.contains('plan-modal-backdrop')) closeModal();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
}

(async function init(){
  cacheDom();
  initFloorSelect(state.floor);
  bindEvents();
  await loadAssets();
  initTypeSelect();
  renderPlan();
  renderList();
  syncView();

  const _urlUnit = new URLSearchParams(window.location.search).get('unit_code');
  if (_urlUnit) {
    await selectAsset(_urlUnit);
  }
})();
