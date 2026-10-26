<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentCode = strtoupper(trim((string)($_SESSION['current_project_code'] ?? 'FLAVA')));
if ($currentCode !== 'FLAVA') {
  header('Location: select_project.php');
  exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <title>FLAVA – Gestion des plans</title>
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
    .mf-overlay { position: absolute; top: 0; left: 0; pointer-events: none; }
    .mf-overlay .unit-rect { pointer-events: auto; }

    /* Sous-sol : image réduite */
    .plan-wrapper.is-sous-sol { max-height: 42vh; }
    .plan-wrapper.is-sous-sol #planImage { width: auto; max-width: 100%; max-height: 40vh; }

    /* Sous-sol panel */
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
      font-size: 12px; transition: transform .1s;
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
      <span class="logo">FLAVA</span>
      <span class="subtitle">Gestion des plans – Admin</span>
    </div>
    <div class="user-bar">
      <a class="header-link" href="liste_prix_flava.php">Liste des Prix</a>
      <a class="header-link" href="documents.php">Documents</a>
      <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
      <a class="header-link" href="admin_users.php" style="color:#fde68a">⚙ Utilisateurs</a>
      <?php endif; ?>
      <span class="user-name">
        Connecté : <?php echo htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
      </span>
      <a class="logout-link" href="logout.php">Se déconnecter</a>
    </div>
  </header>

  <main class="layout">
    <!-- Panneau gauche : filtres + plan -->
    <section class="left">
      <div class="toolbar">
        <div class="filters">
          <label>
            Bloc
            <select id="selectBlock">
              <option value="ALL">Tous</option>
              <option value="A" selected>Bloc A</option>
              <option value="B">Bloc B</option>
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

      <div class="plan-wrapper">
        <div class="plan-inner">
          <img id="planImage" src="" alt="Plan d'étage FLAVA" />
          <div id="unitOverlay"></div>
        </div>
        <div id="multiFloorContainer" class="multi-floor-container hidden"></div>
      </div>

      <div id="listWrapper" class="list-wrapper hidden">
        <div class="list-head">
          <div class="list-title">Liste de vente</div>
          <label class="only-dispo">
            <input type="checkbox" id="onlyAvailable" checked />
            Disponible uniquement
          </label>
        </div>

        <div class="table-wrap">
          <table class="units-table" id="unitsTable">
            <thead>
              <tr>
                <th>Code</th>
                <th>Bloc</th>
                <th>Étage</th>
                <th>Typologie</th>
                <th>Surface</th>
                <th>Prix/m² HT (indexé)</th>
                <th>Total HT</th>
                <th>Statut</th>
              </tr>
            </thead>
            <tbody id="unitsTbody"></tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- Panneau droit : fiche unité -->
    <aside class="right">
      <div id="unitEmpty" class="unit-empty">
        <p>Sélectionnez un appartement sur le plan.</p>
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
          <div class="unit-preview unit-preview--ava" id="unitPreviewBox">
            <img id="unitPreviewImg" alt="Plan de l'unité" />
            <iframe id="unitPreviewPdf" title="Plan de l'unité" class="unit-preview-pdf hidden"></iframe>
            <div id="unitPreviewEmpty" class="unit-preview-empty">Aucun plan disponible</div>
          </div>
        </div>

        <div class="unit-section">
          <h3>Informations générales</h3>
          <dl class="info-list">
            <div>
              <dt>Bloc</dt><dd id="uBlock"></dd>
            </div>
            <div>
              <dt>Étage</dt><dd id="uFloor"></dd>
            </div>
            <div>
              <dt>Type</dt><dd id="uType"></dd>
            </div>
            <div>
              <dt>Surface</dt><dd id="uSurface"></dd>
            </div>
          </dl>
        </div>

        <div class="unit-section">
          <h3>Prix (calcul auto)</h3>
          <dl class="info-list">
            <div>
              <dt>Prix/m² HT (indexé)</dt><dd id="uPriceBase"></dd>
            </div>
            <div>
              <dt>Prix/m² TTC</dt><dd id="uPriceMonth"></dd>
            </div>
            <div>
              <dt>Total HT / TTC</dt><dd id="uTotalMonth"></dd>
            </div>
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
            <div>
              <dt>Nom & prénom</dt><dd id="cName"></dd>
            </div>
            <div>
              <dt>Téléphone</dt><dd id="cPhone"></dd>
            </div>
            <div>
              <dt>Email</dt><dd id="cEmail"></dd>
            </div>
          </dl>
        </div>
<div class="unit-section" id="promiseSection" style="display:none">
  <h3>Fiche client</h3>
  <button id="promiseBtn" class="btn-secondary">
    Ouvrir la fiche client
  </button>
</div>
      </div>
    </aside>
  </main>

  <footer class="app-footer">
    <small>© PROMED – Outil interne FLAVA (MVP gestion des plans)</small>
  </footer>

  <div id="planModal" class="plan-modal hidden" aria-hidden="true">
    <div class="plan-modal-backdrop"></div>
    <div class="plan-modal-content plan-modal-content--flava">
      <button id="planModalClose" class="plan-modal-close" type="button">×</button>
      <img id="planModalImg" alt="Plan agrandi" class="hidden" />
      <iframe id="planModalPdf" title="Plan agrandi" class="plan-modal-pdf hidden"></iframe>
      <div id="planModalEmpty" class="plan-modal-empty hidden">Aucun plan disponible.</div>
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

  <script>window.__PROMEDAPP_SKIP_FLAVA_LEGACY__ = true;</script>
  <?php if (strtolower(trim((string)($_SESSION['role'] ?? ''))) === 'agence'): ?>
  <?php
    $agUStmt = $pdo->prepare("SELECT UPPER(unit_code) FROM agence_unit_access WHERE user_id = ? AND UPPER(project_code) = 'FLAVA'");
    $agUStmt->execute([(int)$_SESSION['user_id']]);
    $agFlavaCodes = $agUStmt->fetchAll(PDO::FETCH_COLUMN);
  ?>
  <script>window.__AGENCE_FLAVA_UNITS__ = <?= json_encode($agFlavaCodes, JSON_UNESCAPED_UNICODE) ?>;</script>
  <?php endif; ?>
  <script src="assets/app_flava.js?v=<?= filemtime(__DIR__.'/assets/app_flava.js'); ?>"></script>
  <script src="assets/app_flava_v2.js?v=<?= filemtime(__DIR__.'/assets/app_flava_v2.js'); ?>"></script>
  <script>fetch('api/reservation_expire.php').catch(function(){});</script>
<!-- ===== Modal Fiche Client (ajout) ===== -->
<div id="clientModal" class="modal hidden">
  <div class="modal-content">
    <h2>Fiche client – Unité <span id="fcUnit"></span></h2>

    <form id="clientForm">

      <label>Nom & prénom
        <input type="text" name="nom" required>
      </label>

      <label>Date de naissance
        <input type="date" name="date_naissance" required>
      </label>

      <label>Numéro CIN
        <input type="text" name="cin" required>
      </label>

      <label>CIN délivrée le
        <input type="date" name="cin_delivre_le" required>
      </label>

      <label>Téléphone
        <input type="text" name="tel" required>
      </label>

      <label>Email
        <input type="email" name="email">
      </label>

      <label>Objet de la vente
        <textarea name="objet" id="fcObjet" rows="3"></textarea>
      </label>

      <!-- champs caché pour le code unité -->
      <input type="hidden" name="unit_code" id="fcUnitCode">

      <div class="modal-actions">
        <button type="button" class="btn-secondary" id="clientCancelBtn">
          Annuler
        </button>
        <button type="submit" class="btn-primary">
          Enregistrer la fiche client
        </button>
      </div>

    </form>
  </div>
</div>

</body>
</html>
