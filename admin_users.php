<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id']))           { header('Location: login.php');         exit; }
if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: select_project.php'); exit; }

$users = $pdo->query("
    SELECT id, username, full_name, email, phone, role, is_active, created_at
    FROM users ORDER BY role DESC, full_name ASC
")->fetchAll();

// Charge les unités assignées à chaque agence (pour pré-remplissage du modal)
$agenceByUser = [];
try {
    foreach ($pdo->query("SELECT user_id, project_code, unit_code FROM agence_unit_access ORDER BY project_code, unit_code") as $row) {
        $agenceByUser[(int)$row['user_id']][$row['project_code']][] = $row['unit_code'];
    }
} catch (Throwable $e) { /* table absente : pas grave */ }

$flash = $_GET['flash'] ?? '';
$promedLogo = 'assets/images/PromedGroup.png';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Utilisateurs — PROMED Admin</title>
<style>
:root { --bg:#f8f8f8; --card-bg:#ffffff; --accent:#111111; --border-soft:#e6e6e6; --brand:#145f7d; }
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#ffffff;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:40px 20px}

.shell{width:100%;max-width:1040px;background:var(--card-bg);border-radius:28px;padding:40px 40px 32px;border:1px solid #f0f0f0;box-shadow:0 18px 40px rgba(0,0,0,.06)}

.header{text-align:center;margin-bottom:32px}
.logo-main{display:inline-flex;align-items:center;justify-content:center;width:220px;height:100px;margin-bottom:12px}
.logo-main img{max-width:100%;max-height:90px;display:block}
.title{font-size:22px;font-weight:600;margin:0;letter-spacing:.4px;color:#111}
.subtitle{margin:6px 0 0;font-size:14px;color:#888}

.page-toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.page-toolbar h2{font-size:16px;font-weight:700;color:#222}

.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border-radius:999px;border:none;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:.15s;font-family:inherit}
.btn-dark{background:#111;color:#fff}
.btn-dark:hover{background:#000;box-shadow:0 4px 12px rgba(0,0,0,.18)}
.btn-sm{padding:5px 14px;font-size:12px;border-radius:999px;border:none;cursor:pointer;font-weight:600;font-family:inherit}
.btn-outline{background:transparent;color:#555;border:1px solid #ddd}
.btn-outline:hover{background:#f4f4f4}
.btn-danger-sm{background:#fff0f0;color:#b3261e;border:1px solid #fecaca}
.btn-danger-sm:hover{background:#fee2e2}
.btn-success-sm{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.btn-success-sm:hover{background:#dcfce7}
.btn-delete{background:transparent;color:#ccc;border:1px solid #e5e7eb}
.btn-delete:hover{background:#fff0f0;color:#b3261e;border-color:#fecaca}

.flash{padding:10px 16px;border-radius:12px;margin-bottom:18px;font-size:13px;font-weight:600}
.flash-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.flash-err{background:#fff0f0;color:#b3261e;border:1px solid #fecaca}

.card{border-radius:20px;border:1px solid var(--border-soft);overflow:hidden}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;min-width:720px;border-collapse:collapse;font-size:13px}
th{background:#fafafa;color:#999;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:10px 16px;text-align:left;border-bottom:1px solid #f0f0f0;white-space:nowrap}
td{padding:13px 16px;border-bottom:1px solid #f8f8f8;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#fdfdfdd0}

.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.badge-admin{background:#e0f2fe;color:#0369a1}
.badge-commercial{background:#f0fdf4;color:#166534}
.badge-agence{background:#fef3c7;color:#92400e}
.badge-off{background:#fff0f0;color:#b3261e}
.badge-on{background:#f0fdf4;color:#166534}

.footer-row{margin-top:28px;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#999}
.footer-row a{color:#b3261e;text-decoration:none}
.footer-row a:hover{text-decoration:underline}
.back-link{color:#555;text-decoration:none;font-size:13px}
.back-link:hover{color:#111}

/* Modal */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:999;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto}
.overlay.open{display:flex}
.modal{background:#fff;border-radius:24px;padding:32px;width:100%;max-width:540px;box-shadow:0 24px 60px rgba(0,0,0,.18);margin:auto}
.modal-title{font-size:17px;font-weight:700;margin-bottom:22px;color:#111}
.form-group{margin-bottom:14px}
.form-group > label{display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em}
.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
input[type=text],input[type=email],input[type=password],input[type=tel],select{width:100%;padding:10px 13px;border:1px solid #e6e6e6;border-radius:12px;font-size:13px;font-family:inherit;color:#111;background:#fff;outline:none;transition:.15s}
input:focus,select:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(20,95,125,.1)}
.hint{font-size:11px;color:#bbb;margin-top:4px}
.form-error{font-size:12px;color:#b3261e;background:#fff0f0;padding:9px 12px;border-radius:10px;border:1px solid #fecaca;margin-top:12px;display:none}
.modal-actions{display:flex;gap:10px;margin-top:22px}
.modal-actions .btn{flex:1;justify-content:center}
.btn-modal-cancel{background:#f4f4f4;color:#555;border:none}
.btn-modal-cancel:hover{background:#e8e8e8}

/* Agence unit picker */
.agence-panel{background:#fffbf0;border:1px solid #fde68a;border-radius:16px;padding:16px;margin-top:6px}
.agence-panel-title{font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px}
.proj-check-row{display:flex;gap:20px;margin-bottom:4px}
.proj-check-item{display:flex;align-items:center;gap:7px;font-size:13px;font-weight:600;color:#555;cursor:pointer;user-select:none}
.proj-check-item input[type=checkbox]{width:16px;height:16px;accent-color:var(--brand);cursor:pointer;flex-shrink:0}
.unit-proj-section{margin-top:14px;padding-top:14px;border-top:1px solid #fde68a}
.unit-proj-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:6px}
.unit-proj-head-label{font-size:11px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.06em}
.unit-proj-actions{display:flex;gap:10px;align-items:center}
.unit-sel-all{font-size:11px;color:#145f7d;cursor:pointer;font-weight:600;text-decoration:underline}
.unit-sel-all:hover{color:#0f4a63}
.unit-count{font-size:11px;color:#d97706;font-weight:700}
.unit-chip-grid{display:flex;flex-wrap:wrap;gap:4px;min-height:24px}
.unit-chip{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:600;cursor:pointer;border:1.5px solid #e5e7eb;background:#fff;color:#666;transition:.1s;user-select:none}
.unit-chip:hover{border-color:#d97706;color:#92400e;background:#fffbf0}
.unit-chip.selected{background:#fef3c7;border-color:#f59e0b;color:#92400e}
.unit-loading{font-size:12px;color:#bbb;font-style:italic}

@media(max-width:640px){body{padding:20px 12px}.shell{padding:24px 16px 20px;border-radius:20px}.form-row-2{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="shell">

  <header class="header">
    <div class="logo-main">
      <?php if (is_file($promedLogo)): ?>
        <img src="<?= htmlspecialchars($promedLogo) ?>" alt="PROMED">
      <?php else: ?>
        <span style="font-weight:700;font-size:20px;color:#111">PROMED</span>
      <?php endif; ?>
    </div>
    <h1 class="title">Gestion des utilisateurs</h1>
    <p class="subtitle">Commerciaux, agences et administrateurs du CRM</p>
  </header>

  <?php if ($flash === 'saved'): ?>
    <div class="flash flash-ok">✅ Utilisateur enregistré avec succès.</div>
  <?php elseif ($flash === 'toggled'): ?>
    <div class="flash flash-ok">✅ Statut du compte mis à jour.</div>
  <?php elseif ($flash === 'error'): ?>
    <div class="flash flash-err">❌ Une erreur est survenue.</div>
  <?php endif; ?>

  <div class="page-toolbar">
    <h2>👥 <?= count($users) ?> utilisateur<?= count($users) > 1 ? 's' : '' ?></h2>
    <button class="btn btn-dark" onclick="openModal()">+ Nouvel utilisateur</button>
  </div>

  <div class="card">
   <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Nom complet</th>
          <th>Identifiant</th>
          <th>Téléphone</th>
          <th>Rôle</th>
          <th>Statut</th>
          <th>Créé le</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <?php
          $roleMap = [
            'admin'      => ['badge-admin',     'Admin'],
            'agence'     => ['badge-agence',     'Agence'],
            'commercial' => ['badge-commercial', 'Commercial'],
          ];
          [$badgeClass, $badgeLabel] = $roleMap[$u['role']] ?? ['badge-commercial', 'Commercial'];

          $userAgenceUnits = $agenceByUser[(int)$u['id']] ?? [];
          $userModalData = [
            'id'           => $u['id'],
            'username'     => $u['username'],
            'full_name'    => $u['full_name'],
            'email'        => $u['email'] ?? '',
            'phone'        => $u['phone'] ?? '',
            'role'         => $u['role'],
            'agence_units' => empty($userAgenceUnits) ? new stdClass() : $userAgenceUnits,
          ];
        ?>
        <tr>
          <td style="font-weight:600;color:#111"><?= htmlspecialchars($u['full_name'] ?? '—') ?></td>
          <td style="color:#145f7d;font-size:12px"><?= htmlspecialchars($u['username']) ?></td>
          <td style="color:#888;font-size:12px"><?= htmlspecialchars($u['phone'] ?? '—') ?></td>
          <td><span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
          <td>
            <span class="badge <?= $u['is_active'] ? 'badge-on' : 'badge-off' ?>">
              <?= $u['is_active'] ? 'Actif' : 'Inactif' ?>
            </span>
          </td>
          <td style="color:#bbb;font-size:11px"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
          <td style="white-space:nowrap">
            <div style="display:flex;gap:6px;align-items:center">
            <button class="btn-sm btn-outline"
              onclick='openModal(<?= htmlspecialchars(json_encode($userModalData), ENT_QUOTES) ?>)'>✏ Modifier</button>
            <?php if ($u['id'] != $_SESSION['user_id']): ?>
              <?php if ($u['is_active']): ?>
                <button class="btn-sm btn-danger-sm"
                  onclick="toggleUser(<?= $u['id'] ?>, 0, '<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>')">Désactiver</button>
              <?php else: ?>
                <button class="btn-sm btn-success-sm"
                  onclick="toggleUser(<?= $u['id'] ?>, 1, '<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>')">Réactiver</button>
              <?php endif; ?>
              <button class="btn-sm btn-delete"
                onclick="deleteUser(<?= $u['id'] ?>, '<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>')">🗑</button>
            <?php else: ?>
              <span style="font-size:11px;color:#ccc">vous-même</span>
            <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
   </div>
  </div>

  <div class="footer-row">
    <div style="display:flex;gap:20px;align-items:center">
      <a href="select_project.php" class="back-link">← Sélection projet</a>
      <a href="admin_stats.php" style="color:#145f7d;font-weight:600;text-decoration:none;font-size:13px">📊 Statistiques</a>
    </div>
    <a href="logout.php">Se déconnecter</a>
  </div>
</div>

<!-- Modal -->
<div class="overlay" id="modalOverlay">
  <div class="modal">
    <div class="modal-title" id="modalTitle">Nouvel utilisateur</div>
    <form onsubmit="submitUser(event)">
      <input type="hidden" id="userId">

      <div class="form-group">
        <label>Nom complet *</label>
        <input type="text" id="fFullName" placeholder="Prénom Nom" required>
      </div>

      <div class="form-row-2">
        <div class="form-group">
          <label>Identifiant (login) *</label>
          <input type="text" id="fUsername" placeholder="prenom.nom" required>
        </div>
        <div class="form-group">
          <label>Téléphone</label>
          <input type="tel" id="fPhone" placeholder="+216 XX XXX XXX">
        </div>
      </div>

      <div class="form-group">
        <label>Email (optionnel)</label>
        <input type="email" id="fEmail" placeholder="contact@promed.tn">
      </div>

      <div class="form-row-2">
        <div class="form-group">
          <label>Rôle *</label>
          <select id="fRole" onchange="onRoleChange()">
            <option value="commercial">Commercial</option>
            <option value="agence">Agence</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div class="form-group">
          <label id="fPasswordLabel">Mot de passe *</label>
          <input type="password" id="fPassword" placeholder="••••••••">
          <div class="hint" id="fPasswordHint"></div>
        </div>
      </div>

      <!-- Panel agence : visible seulement quand role=agence -->
      <div id="agencePanel" class="agence-panel" style="display:none">
        <div class="agence-panel-title">🏢 Unités accessibles à l'agence</div>

        <div class="proj-check-row">
          <label class="proj-check-item">
            <input type="checkbox" id="agProjAVA" onchange="onProjCheck('AVA', this.checked)">
            AVA
          </label>
          <label class="proj-check-item">
            <input type="checkbox" id="agProjFLAVA" onchange="onProjCheck('FLAVA', this.checked)">
            FLAVA
          </label>
        </div>

        <div id="unitPickerAVA" style="display:none" class="unit-proj-section">
          <div class="unit-proj-head">
            <span class="unit-proj-head-label">Appartements AVA</span>
            <div class="unit-proj-actions">
              <span class="unit-sel-all" onclick="selectAllUnits('AVA', true)">Tout cocher</span>
              <span class="unit-sel-all" onclick="selectAllUnits('AVA', false)">Tout décocher</span>
              <span class="unit-count" id="countAVA"></span>
            </div>
          </div>
          <div class="unit-chip-grid" id="unitsGridAVA"><span class="unit-loading">Chargement…</span></div>
        </div>

        <div id="unitPickerFLAVA" style="display:none" class="unit-proj-section">
          <div class="unit-proj-head">
            <span class="unit-proj-head-label">Appartements FLAVA</span>
            <div class="unit-proj-actions">
              <span class="unit-sel-all" onclick="selectAllUnits('FLAVA', true)">Tout cocher</span>
              <span class="unit-sel-all" onclick="selectAllUnits('FLAVA', false)">Tout décocher</span>
              <span class="unit-count" id="countFLAVA"></span>
            </div>
          </div>
          <div class="unit-chip-grid" id="unitsGridFLAVA"><span class="unit-loading">Chargement…</span></div>
        </div>
      </div>

      <div class="form-error" id="formError"></div>

      <div class="modal-actions">
        <button type="submit" class="btn btn-dark" id="submitBtn">💾 Enregistrer</button>
        <button type="button" class="btn btn-modal-cancel" onclick="closeModal()">Annuler</button>
      </div>
    </form>
  </div>
</div>

<script>
// ─── Agence unit state ──────────────────────────────────────────────────────
const agState = {
  AVA:   { loaded: false, units: [], selected: new Set() },
  FLAVA: { loaded: false, units: [], selected: new Set() },
};

function onRoleChange() {
  const role = document.getElementById('fRole').value;
  const panel = document.getElementById('agencePanel');
  if (role === 'agence') {
    panel.style.display = 'block';
  } else {
    panel.style.display = 'none';
    // Décocher et masquer les pickers
    ['AVA','FLAVA'].forEach(proj => {
      document.getElementById('agProj'+proj).checked = false;
      document.getElementById('unitPicker'+proj).style.display = 'none';
      agState[proj].selected.clear();
      updateCount(proj);
    });
  }
}

async function onProjCheck(proj, checked) {
  const picker = document.getElementById('unitPicker'+proj);
  if (checked) {
    picker.style.display = 'block';
    if (!agState[proj].loaded) {
      await loadUnits(proj, null);
    } else {
      renderUnits(proj);
    }
  } else {
    picker.style.display = 'none';
    agState[proj].selected.clear();
    updateCount(proj);
  }
}

async function loadUnits(proj, preSelected) {
  const grid = document.getElementById('unitsGrid'+proj);
  grid.innerHTML = '<span class="unit-loading">Chargement…</span>';
  try {
    const res  = await fetch('api/project_units.php?project='+proj+'&exclude_sold=1');
    const data = await res.json();
    agState[proj].units  = data.units || [];
    agState[proj].loaded = true;
    if (preSelected && preSelected.length) {
      const upSet = new Set(preSelected.map(c => c.toUpperCase()));
      agState[proj].selected = new Set(
        agState[proj].units
          .filter(u => upSet.has(u.code.toUpperCase()))
          .map(u => u.code)
      );
    }
    renderUnits(proj);
  } catch(e) {
    grid.innerHTML = '<span style="font-size:12px;color:#b3261e">Erreur de chargement</span>';
  }
}

function renderUnits(proj) {
  const grid  = document.getElementById('unitsGrid'+proj);
  const units = agState[proj].units;
  const sel   = agState[proj].selected;

  if (!units.length) {
    grid.innerHTML = '<span class="unit-loading">Aucune unité trouvée</span>';
    updateCount(proj); return;
  }

  grid.innerHTML = '';
  for (const u of units) {
    const chip = document.createElement('span');
    chip.className = 'unit-chip' + (sel.has(u.code) ? ' selected' : '');
    chip.textContent = u.code;
    if (u.type) chip.title = u.type + (u.surface ? ' · '+u.surface+'m²' : '');
    chip.onclick = () => {
      if (sel.has(u.code)) { sel.delete(u.code); chip.classList.remove('selected'); }
      else                  { sel.add(u.code);    chip.classList.add('selected');    }
      updateCount(proj);
    };
    grid.appendChild(chip);
  }
  updateCount(proj);
}

function selectAllUnits(proj, selectAll) {
  const sel = agState[proj].selected;
  if (selectAll) agState[proj].units.forEach(u => sel.add(u.code));
  else           sel.clear();
  renderUnits(proj);
}

function updateCount(proj) {
  const el = document.getElementById('count'+proj);
  if (!el) return;
  const n = agState[proj].selected.size;
  el.textContent = n > 0 ? n+' sélectionné'+(n>1?'s':'') : '';
}

function getAgenceUnitsPayload() {
  const payload = {};
  for (const proj of ['AVA','FLAVA']) {
    if (document.getElementById('agProj'+proj)?.checked) {
      payload[proj] = [...agState[proj].selected];
    }
  }
  return payload;
}

// ─── Modal principal ─────────────────────────────────────────────────────────
function openModal(user = null) {
  document.getElementById('userId').value    = user?.id ?? '';
  document.getElementById('fFullName').value = user?.full_name ?? '';
  document.getElementById('fUsername').value = user?.username  ?? '';
  document.getElementById('fEmail').value    = user?.email     ?? '';
  document.getElementById('fPhone').value    = user?.phone     ?? '';
  document.getElementById('fPassword').value = '';
  document.getElementById('formError').style.display = 'none';

  // Réinitialiser le picker agence
  ['AVA','FLAVA'].forEach(proj => {
    document.getElementById('agProj'+proj).checked = false;
    document.getElementById('unitPicker'+proj).style.display = 'none';
    agState[proj].selected.clear();
    updateCount(proj);
  });

  const role = user?.role ?? 'commercial';
  document.getElementById('fRole').value = role;

  const isEdit = !!user;
  document.getElementById('modalTitle').textContent     = isEdit ? '✏ Modifier le compte'  : '+ Nouvel utilisateur';
  document.getElementById('fPasswordLabel').textContent = isEdit ? 'Nouveau mot de passe'   : 'Mot de passe *';
  document.getElementById('fPasswordHint').textContent  = isEdit ? "Laisser vide = inchangé" : '';
  document.getElementById('fPassword').required         = !isEdit;
  document.getElementById('submitBtn').textContent      = '💾 Enregistrer';
  document.getElementById('submitBtn').disabled         = false;

  // Panel agence
  const agPanel = document.getElementById('agencePanel');
  if (role === 'agence') {
    agPanel.style.display = 'block';
    const agUnits = user?.agence_units ?? {};
    for (const [proj, codes] of Object.entries(agUnits)) {
      if (!['AVA','FLAVA'].includes(proj) || !codes.length) continue;
      document.getElementById('agProj'+proj).checked = true;
      document.getElementById('unitPicker'+proj).style.display = 'block';
      if (agState[proj].loaded) {
        agState[proj].selected = new Set(
          agState[proj].units.filter(u => codes.map(c=>c.toUpperCase()).includes(u.code.toUpperCase())).map(u=>u.code)
        );
        renderUnits(proj);
      } else {
        loadUnits(proj, codes);
      }
    }
  } else {
    agPanel.style.display = 'none';
  }

  document.getElementById('modalOverlay').classList.add('open');
  setTimeout(() => document.getElementById('fFullName').focus(), 80);
}

function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
}

document.getElementById('modalOverlay').addEventListener('click', e => {
  if (e.target === document.getElementById('modalOverlay')) closeModal();
});

async function submitUser(e) {
  e.preventDefault();
  const err  = document.getElementById('formError');
  const btn  = document.getElementById('submitBtn');
  const id   = document.getElementById('userId').value;
  const pass = document.getElementById('fPassword').value.trim();
  const role = document.getElementById('fRole').value;

  err.style.display = 'none';
  if (!id && !pass)           { showErr('Le mot de passe est obligatoire.'); return; }
  if (pass && pass.length < 6){ showErr('Mot de passe : 6 caractères minimum.'); return; }

  if (role === 'agence') {
    const totalSel = ['AVA','FLAVA'].reduce((n,p) => n + (document.getElementById('agProj'+p)?.checked ? agState[p].selected.size : 0), 0);
    if (totalSel === 0) { showErr('Veuillez sélectionner au moins une unité pour le compte agence.'); return; }
  }

  btn.disabled = true; btn.textContent = '⏳ Enregistrement…';

  try {
    const payload = {
      id:           id ? parseInt(id) : null,
      username:     document.getElementById('fUsername').value.trim(),
      full_name:    document.getElementById('fFullName').value.trim(),
      email:        document.getElementById('fEmail').value.trim()  || null,
      phone:        document.getElementById('fPhone').value.trim()  || null,
      role,
      password:     pass || null,
      agence_units: role === 'agence' ? getAgenceUnitsPayload() : null,
    };
    const res  = await fetch('api/user_save.php', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload),
    });
    const json = await res.json();
    if (json.success) {
      location.href = 'admin_users.php?flash=saved';
    } else {
      showErr(json.error || 'Erreur inconnue');
      btn.disabled = false; btn.textContent = '💾 Enregistrer';
    }
  } catch(ex) {
    showErr('Erreur réseau : ' + ex.message);
    btn.disabled = false; btn.textContent = '💾 Enregistrer';
  }
}

async function deleteUser(id, name) {
  if (!confirm('Supprimer définitivement le compte de "' + name + '" ?\n\nCette action est irréversible.')) return;
  const res  = await fetch('api/user_save.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id, delete_user: true}),
  });
  const json = await res.json();
  json.success ? (location.href='admin_users.php?flash=saved') : alert('Erreur : '+(json.error||'inconnue'));
}

async function toggleUser(id, active, name) {
  if (!confirm((active ? 'Réactiver' : 'Désactiver') + ' le compte de "' + name + '" ?')) return;
  const res  = await fetch('api/user_save.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({id, toggle_active: active}),
  });
  const json = await res.json();
  json.success ? (location.href='admin_users.php?flash=toggled') : alert('Erreur : '+(json.error||'inconnue'));
}

function showErr(msg) {
  const el = document.getElementById('formError');
  el.textContent = msg; el.style.display = 'block';
}
</script>
</body>
</html>
