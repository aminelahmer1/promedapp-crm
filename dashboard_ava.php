<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ($role === 'commercial') {
  // dashboard AVA uniquement pour commerciaux autorisés
  // (les autres codes sont filtrés côté select_project.php)
}

$currentCode = strtoupper(trim((string)($_SESSION['current_project_code'] ?? 'AVA')));
if ($currentCode !== 'AVA') {
  header('Location: select_project.php');
  exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <title>AVA – Gestion des plans</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="assets/styles.css" />
  <style>
    .multi-floor-container {
      display: flex;
      flex-direction: column;
      gap: 24px;
      padding: 12px 16px;
      width: 100%;
    }
    .mf-section { display: flex; flex-direction: column; gap: 6px; }
    .mf-label {
      font-size: 11px;
      font-weight: 700;
      color: #6b7280;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      padding-bottom: 4px;
      border-bottom: 1px solid #e5e7eb;
    }
    .mf-plan-inner { position: relative; display: inline-block; width: 100%; }
    .mf-plan-img { display: block; width: 100%; height: auto; }
    .mf-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
    .mf-overlay .unit-rect { pointer-events: auto; }

    .plan-wrapper.is-sous-sol { max-height: 42vh; }
    .plan-wrapper.is-sous-sol #planImage { width: auto; max-width: 100%; max-height: 40vh; }

    .sous-sol-panel { padding: 16px; overflow-y: auto; display: flex; flex-direction: column; gap: 18px; }
    .ss-section { display: flex; flex-direction: column; gap: 8px; }
    .ss-section-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; border-bottom: 1px solid #e5e7eb; padding-bottom: 6px; }
    .ss-section-title { font-size: 13px; font-weight: 700; color: #111; }
    .ss-price-tag { font-size: 11px; color: #6b7280; }
    .ss-park-grid { display: flex; flex-wrap: wrap; gap: 4px; }
    .ss-acc-btn {
      display: flex; flex-direction: column; align-items: center;
      min-width: 48px; padding: 4px 6px; border-radius: 7px;
      border: 1.5px solid transparent; font: inherit; cursor: pointer; line-height: 1.4;
      transition: transform .1s;
    }
    .ss-acc-btn:hover { transform: scale(1.06); }
    .ss-acc-btn.d { background: #f0fdf4; border-color: #86efac; color: #15803d; }
    .ss-acc-btn.r { background: #fffbeb; border-color: #fcd34d; color: #b45309; }
    .ss-acc-btn.v { background: #fff1f2; border-color: #fca5a5; color: #b91c1c; }
    .ss-code { font-size: 10px; font-weight: 700; }
    .ss-sub  { font-size: 9px; font-weight: 400; }
    .ss-cell-list { display: flex; flex-direction: column; gap: 4px; }
    .ss-cell-card {
      display: flex; align-items: center; gap: 8px;
      padding: 6px 10px; border-radius: 8px; border: 1.5px solid transparent;
      font-size: 12px; transition: transform .1s; cursor: pointer; font: inherit; width: 100%; text-align: left;
    }
    .ss-cell-card:hover { transform: translateX(3px); }
    .ss-cell-card.d { background: #f0fdf4; border-color: #86efac; }
    .ss-cell-card.r { background: #fffbeb; border-color: #fcd34d; }
    .ss-cell-card.v { background: #fff1f2; border-color: #fca5a5; }
    .ss-surf { color: #6b7280; font-size: 11px; }
    .ss-badge { margin-left: auto; font-size: 10px; font-weight: 600; opacity: .75; }
  </style>
</head>
<body>
  <header class="app-header">
    <div class="brand">
      <span class="logo">AVA</span>
      <span class="subtitle">Gestion des plans – Admin</span>
    </div>
    <div class="user-bar">
      <a class="header-link" href="home_ava.php">← Accueil</a>
      <a class="header-link" href="liste_prix_ava.php">Liste des Prix</a>
      <a class="header-link" href="documents.php">Documents</a>
      <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
      <a class="header-link" href="admin_users.php" style="color:#fde68a">⚙ Utilisateurs</a>
      <?php endif; ?>
      <span class="user-name">Connecté : <?php echo htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
      <a class="logout-link" href="logout.php">Se déconnecter</a>
    </div>
  </header>

  <main class="layout">
    <section class="left">
      <div class="toolbar">
        <div class="filters">
          <label>
            Projet
            <select id="selectBlock" disabled>
              <option value="AVA" selected>AVA</option>
            </select>
          </label>

          <label>
            Étage
            <select id="selectFloor">
              <option value="ALL">Tous les étages</option>
            </select>
          </label>

          <label>
            Typologie
            <select id="selectType">
              <!-- rempli par JS -->
            </select>
          </label>
        </div>

        <div class="view-toggle" role="group" aria-label="Mode d'affichage">
          <button id="viewPlanBtn" class="seg-btn is-active" type="button">Plan</button>
          <button id="viewListBtn" class="seg-btn" type="button">Liste</button>
        </div>

        <div class="legend">
          <span class="leg-chip leg-dispo">Disponible</span>
          <span class="leg-chip leg-reserve">Réservé</span>
          <span class="leg-chip leg-vendu">Vendu</span>
        </div>
      </div>

      <div class="plan-wrapper" id="planWrapper">
        <div class="plan-inner ava-plan-inner">
          <img id="planImage" src="" alt="Plan d'étage AVA" />
          <div id="unitOverlay"></div>
          <div id="planMissing" class="plan-missing hidden">Plan non disponible pour cet étage.</div>
        </div>
        <div id="multiFloorContainer" class="multi-floor-container hidden"></div>
      </div>

      <div id="listWrapper" class="list-wrapper hidden">
        <div class="list-head">
          <div class="list-title">Liste AVA</div>
          <label class="only-dispo">
            <input type="checkbox" id="onlyAvailable" />
            Disponible uniquement
          </label>
        </div>
        <div class="table-wrap">
          <table class="units-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Étage</th>
                <th>Type</th>
                <th>Surface</th>
                <th>Prix/m² HT</th>
                <th>Total HT</th>
                <th>Statut</th>
              </tr>
            </thead>
            <tbody id="unitsTbody"></tbody>
          </table>
        </div>
      </div>
    </section>

    <aside class="right">
      <div id="unitEmpty" class="unit-empty">
        <p>Sélectionnez une unité AVA sur le plan.</p>
      </div>

      <div id="sousSolPanel" class="sous-sol-panel hidden"></div>

      <div id="unitCard" class="unit-card hidden">
        <header class="unit-head">
          <div>
            <h2 id="uCode"></h2>
            <p id="uMeta" class="u-meta"></p>
          </div>
          <span id="uStatusBadge" class="status-badge"></span>
        </header>

        <div class="unit-preview-wrapper">
          <div class="unit-preview unit-preview--ava">
            <img id="unitPreviewImg" alt="Plan individuel de l'unité" />
          </div>
        </div>

        <div class="unit-section">
          <h3>Informations générales</h3>
          <dl class="info-list">
            <div><dt>Bloc</dt><dd id="uBlock"></dd></div>
            <div><dt>Étage</dt><dd id="uFloor"></dd></div>
            <div><dt>Type</dt><dd id="uType"></dd></div>
            <div><dt>Surface</dt><dd id="uSurface"></dd></div>
          </dl>
        </div>

        <div class="unit-section">
          <h3>Prix (calcul auto)</h3>
          <dl class="info-list">
            <div><dt>Prix/m² HT (indexé)</dt><dd id="uPriceBase"></dd></div>
            <div><dt>Prix/m² TTC</dt><dd id="uPriceMonth"></dd></div>
            <div><dt>Total HT / TTC</dt><dd id="uTotalMonth"></dd></div>
          </dl>
        </div>

        <div class="unit-section">
          <h3>Statut</h3>
          <label class="status-row">
            <span>Nouveau statut</span>
            <select id="uStatusSelect">
              <option value="Disponible">Disponible</option>
              <option value="Réservé">Réservé</option>
              <option value="Vendu">Vendu</option>
            </select>
          </label>
          <button id="uSaveBtn" class="btn-primary">Enregistrer</button>
        </div>

        <div class="unit-section" id="clientSection" style="display:none">
          <h3>Client</h3>
          <dl class="info-list">
            <div><dt>Nom & prénom</dt><dd id="cName"></dd></div>
            <div><dt>Téléphone</dt><dd id="cPhone"></dd></div>
            <div><dt>Email</dt><dd id="cEmail"></dd></div>
          </dl>
        </div>

        <div class="unit-section" id="promiseSection" style="display:none">
          <h3>Promesse de vente</h3>
          <div id="promiseContent" style="font-size:12px"></div>
          <div style="display:flex;flex-direction:column;gap:6px;margin-top:8px">
            <button id="promiseBtn" class="btn-secondary" style="width:100%;font-size:12px">📝 Ouvrir la fiche client</button>
            <a id="promisePaiementsBtn" href="#" target="_blank" style="display:none;text-align:center;font-size:12px;padding:8px 0;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;border-radius:8px;text-decoration:none;font-weight:600">📊 Voir suivi des paiements</a>
          </div>
        </div>
      </div>
    </aside>
  </main>

  <footer class="app-footer">
    <small>© PROMED – Outil interne AVA</small>
  </footer>

  <div id="planModal" class="plan-modal hidden" aria-hidden="true">
    <div class="plan-modal-backdrop"></div>
    <div class="plan-modal-content">
      <button id="planModalClose" class="plan-modal-close" type="button">×</button>
      <img id="planModalImg" alt="Plan agrandi" />
      <div class="plan-modal-actions">
        <button id="planModalDownload" class="plan-modal-btn" type="button" title="Télécharger">
          ⬇ Télécharger
        </button>
        <button id="planModalPrint" class="plan-modal-btn" type="button" title="Imprimer">
          🖨 Imprimer
        </button>
        <button id="planModalSend" class="plan-modal-btn" type="button" title="Envoyer par email">
          ✉ Email
        </button>
        <button id="planModalShare" class="plan-modal-btn" type="button" title="Partager">
          ↗ Partager
        </button>
      </div>
    </div>
  </div>

  <script src="assets/app_ava.js?v=<?= filemtime(__DIR__.'/assets/app_ava.js'); ?>"></script>
  <script>fetch('api/reservation_expire.php').catch(function(){});</script>
</body>
</html>
