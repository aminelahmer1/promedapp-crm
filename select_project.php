<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ------------------------------
// 1) Logos manuels (tu peux adapter)
// ------------------------------
$promedLogo = 'assets/images/PromedGroup.png'; 

$projectLogos = [
    'FLAVA' => 'assets/images/LogoFlava.png',
    'AVA'   => 'assets/images/logoava.avif',
];

// logo par société (clé = societe.sigle ou id)
$societeLogos = [
    'PROMED' => 'assets/images/LogoPROMED.png',
    'DHILEL' => 'assets/images/DHILEL.png',
];

// ------------------------------
// 2) Soumission : choix d'un projet
// ------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['project_id'])) {
    $projectId = (int)$_POST['project_id'];

    // On recharge le projet pour stocker les infos utiles en session
    $stmt = $pdo->prepare("
        SELECT p.id, p.code, p.nom, p.date_fin_prevue,
               s.raison_sociale, s.sigle
        FROM projects p
        LEFT JOIN societes s ON s.id = p.societe_id
        WHERE p.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $projectId]);
    $project = $stmt->fetch();

    if ($project) {
        $_SESSION['current_project_id']   = $project['id'];
        $_SESSION['current_project_code'] = $project['code'];
        $_SESSION['current_project_name'] = $project['nom'];
        $_SESSION['current_project_societe'] = $project['raison_sociale'];
        $_SESSION['current_project_date_fin'] = $project['date_fin_prevue'];

    switch ($project['code']) {
        case 'FLAVA':
            header('Location: home_flava.php');
            break;

        case 'AVA':
            header('Location: home_ava.php');
            break;

        default:
           
            header('Location: dashboard.php');
            break;
    }
    exit;
}
}

// ------------------------------
// 3) Charger tous les projets dynamiques
// ------------------------------
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));

// Tous les rôles : uniquement AVA + FLAVA
$stmt = $pdo->query("
    SELECT p.id, p.code, p.nom, p.localisation, p.ville, p.pays,
           p.date_fin_prevue, s.raison_sociale, s.sigle
    FROM projects p LEFT JOIN societes s ON s.id = p.societe_id
    WHERE UPPER(p.code) IN ('AVA','FLAVA') ORDER BY p.nom
");
$projects = $stmt->fetchAll();
$isAgence = ($role === 'agence');

// Pour une agence : filtrer selon les unités assignées
if ($isAgence) {
    $userId = (int)$_SESSION['user_id'];
    try {
        $agStmt = $pdo->prepare("SELECT DISTINCT UPPER(project_code) FROM agence_unit_access WHERE user_id = ?");
        $agStmt->execute([$userId]);
        $agCodes = $agStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($agCodes)) {
            $projects = array_values(array_filter($projects, function($p) use ($agCodes) {
                return in_array(strtoupper($p['code']), $agCodes);
            }));
        }
    } catch (Throwable $e) { /* table absente : on garde AVA+FLAVA */ }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <title>Choix du projet – PROMED</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
 <style>
:root {
  --bg: #f8f8f8;
  --card-bg: #ffffff;
  --accent: #111111;
  --border-soft: #e6e6e6;
}

* { box-sizing:border-box; }

body {
  margin:0;
  min-height:100vh;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  background:#ffffff;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:40px;
}

/* Container principal */
.shell {
  width:100%;
  max-width:1040px;
  background:var(--card-bg);
  border-radius:28px;
  padding:40px 40px 32px;
  border:1px solid #f0f0f0;
  box-shadow:0 18px 40px rgba(0,0,0,0.06);
}

/* Header */
.header {
  text-align:center;
  margin-bottom:32px;
}

.logo-main {
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:220px;
  height:150px;
  margin-bottom:12px;
}

.logo-main img {
  max-width:100%;
  max-height:140px;
  display:block;
}

.title {
  font-size:24px;
  font-weight:600;
  margin:0;
  letter-spacing:0.4px;
  color:#111;
}

.subtitle {
  margin:6px 0 0;
  font-size:14px;
  color:#888;
}

/* Grille de projets */
.projects-grid {
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));
  gap:22px;
  margin-top:30px;
}

/* Cards */
.project-form { margin:0; }

.project-btn {
  display:block;
  width:100%;
  border:none;
  background:none;
  padding:0;
  text-align:left;
  cursor:pointer;
  font:inherit;
}

.project-card {
  position:relative;
  padding:24px 22px 20px;
  border-radius:20px;
  border:1px solid var(--border-soft);
  background:#fff;
  transition:all .18s ease;
  min-height:190px;
}

.project-card:hover {
  transform:translateY(-4px);
  box-shadow:0 14px 30px rgba(0,0,0,0.08);
  border-color:#dcdcdc;
}

/* Logo projet */
.project-logo-small {
  position:absolute;
  top:45px;
  right:40px;
  width:100px;
  height:100px;
  background:#fff;

  display:flex;
  align-items:center;
  justify-content:center;
  padding:10px;
}

.project-logo-small img {
  max-width:80px;
  max-height:80px;
  display:block;
}

/* Textes */
.project-badge {
  display:inline-block;
  font-size:11px;
  padding:3px 10px;
  border-radius:999px;
  background:#111;
  color:#fff;
  margin-bottom:10px;
}

.project-name {
  font-size:18px;
  font-weight:600;
  margin:0 0 4px;
  color:#222;
}

.project-location {
  font-size:13px;
  color:#666;
  margin:0 0 8px;
}

.project-meta {
  font-size:12px;
  color:#999;
  line-height:1.5;
}

/* Footer */
.footer-row {
  margin-top:30px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  font-size:12px;
  color:#999;
}

.logout-link {
  color:#b3261e;
  text-decoration:none;
}

.logout-link:hover {
  text-decoration:underline;
}

/* Message vide */
.empty-msg {
  margin-top:24px;
  text-align:center;
  font-size:14px;
  color:#999;
}

/* Toggle Vente / Location */
.mode-toggle {
  display:flex;
  justify-content:center;
  margin-bottom:6px;
}
.mode-seg {
  display:inline-flex;
  background:#f4f4f4;
  border-radius:999px;
  padding:4px;
  gap:4px;
}
.mode-btn {
  border:none;
  background:transparent;
  font:inherit;
  font-size:13px;
  font-weight:500;
  padding:7px 22px;
  border-radius:999px;
  cursor:pointer;
  color:#666;
  transition:all .18s ease;
}
.mode-btn.active {
  background:#111;
  color:#fff;
  box-shadow:0 2px 8px rgba(0,0,0,0.15);
}
.mode-hint {
  text-align:center;
  font-size:12px;
  color:#bbb;
  margin:0 0 8px;
}

/* Section panels */
.section-panel { display:none; }
.section-panel.visible { display:block; }

/* Location badge */
.project-badge.loc {
  background:#1a6b3a;
}

/* Responsive */
@media (max-width:640px) {
  body {
    padding:20px;
  }

  .shell {
    padding:26px 18px 20px;
    border-radius:20px;
  }
}
</style>

</head>
<body>
  <div class="shell">
    <header class="header">
      <div class="logo-main">
        <?php if (is_file($promedLogo)): ?>
          <img src="<?= htmlspecialchars($promedLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="PROMED">
        <?php else: ?>
          <span>PROMED</span>
        <?php endif; ?>
      </div>
      <h1 class="title">Sélection du projet</h1>
      <p class="subtitle">Choisissez le mode puis le projet à administrer.</p>
    </header>

    <?php if (!$isAgence || !empty($agenceLocationCodes)): ?>
    <!-- Toggle Vente / Location -->
    <div class="mode-toggle">
      <div class="mode-seg">
        <button class="mode-btn active" id="btnVente"    onclick="setMode('vente')">Vente</button>
        <button class="mode-btn"        id="btnLocation" onclick="setMode('location')">Location</button>
      </div>
    </div>
    <p class="mode-hint" id="modeHint">Projets de vente</p>
    <?php endif; ?>

    <!-- VENTE : projets issus de la base de données -->
    <div class="section-panel visible" id="panelVente">
      <?php if (empty($projects)): ?>
        <p class="empty-msg">
          Aucun projet trouvé dans la base de données.<br>
          Ajoute d'abord des lignes dans la table <strong>projects</strong>.
        </p>
      <?php else: ?>
        <section class="projects-grid">
          <?php foreach ($projects as $p): ?>
            <?php
              $code  = $p['code'];
              $soc   = $p['raison_sociale'] ?: '';
              $sigle = $p['sigle'] ?: '';

              $projLogo = $projectLogos[$code] ?? null;
              $socLogo  = $societeLogos[$sigle] ?? null;

              $locParts = array_filter([$p['localisation'], $p['ville'], $p['pays']]);
              $locText  = implode(' · ', $locParts);

              $dateFin = $p['date_fin_prevue'] ? date('d/m/Y', strtotime($p['date_fin_prevue'])) : null;
            ?>
            <form method="post" class="project-form">
              <input type="hidden" name="project_id" value="<?= (int)$p['id']; ?>">
              <button type="submit" class="project-btn">
                <article class="project-card">
                  <?php if ($projLogo || $socLogo): ?>
                    <div class="project-logo-small">
                      <?php if ($projLogo && is_file($projLogo)): ?>
                        <img src="<?= htmlspecialchars($projLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>">
                      <?php elseif ($socLogo && is_file($socLogo)): ?>
                        <img src="<?= htmlspecialchars($socLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars($sigle, ENT_QUOTES, 'UTF-8'); ?>">
                      <?php else: ?>
                        <span><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>

                  <span class="project-badge">Résidentiel</span>

                  <h2 class="project-name">
                    <?= htmlspecialchars($p['nom'], ENT_QUOTES, 'UTF-8'); ?>
                  </h2>

                  <?php if ($locText): ?>
                    <p class="project-location">
                      <?= htmlspecialchars($locText, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                  <?php endif; ?>

                  <p class="project-meta">
                    <?php if ($soc): ?>
                      Société : <?= htmlspecialchars($soc, ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                    <?php if ($dateFin): ?>
                      <br>Livraison prévisionnelle : <?= htmlspecialchars($dateFin, ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                  </p>
                </article>
              </button>
            </form>
          <?php endforeach; ?>

          <?php if (!$isAgence): ?>
          <!-- EVOLO : carte statique vente -->
          <a href="prix.php?mode=sale&project=EVOLO" class="project-btn" style="text-decoration:none;">
            <article class="project-card">
              <div class="project-logo-small">
                <?php $promedSocLogo = 'assets/images/LogoPROMED.png'; ?>
                <?php if (is_file($promedSocLogo)): ?>
                  <img src="<?= htmlspecialchars($promedSocLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="PROMED">
                <?php else: ?>
                  <span>EVOLO</span>
                <?php endif; ?>
              </div>
              <span class="project-badge">Résidentiel</span>
              <h2 class="project-name">EVOLO</h2>
              <p class="project-location">Tunis · Tunisie</p>
              <p class="project-meta">Société : PROMED</p>
            </article>
          </a>
          <?php endif; ?>

        </section>
      <?php endif; ?>
    </div>

    <?php
    // Projets location disponibles (tous)
    $allLocationProjects = [
      ['code'=>'ZEN',             'nom'=>'ZEN',               'localisation'=>'Lac de Tunis · Tunis · Tunisie', 'type'=>'Commercial / Bureautique', 'societe'=>'PROMED'],
      ['code'=>'VITAL',           'nom'=>'VITAL',             'localisation'=>'Tunis · Tunisie',                'type'=>'Commercial / Bureau',       'societe'=>'PROMED'],
      ['code'=>'LAC DES FLAMANTS','nom'=>'LAC DES FLAMANTS',  'localisation'=>'Lac 2 · Tunis · Tunisie',        'type'=>'Commercial / Restaurant',   'societe'=>'PROMED'],
      ['code'=>'PROMED BUILDING', 'nom'=>'PROMED BUILDING',   'localisation'=>'Tunis · Tunisie',                'type'=>'Commerce',                  'societe'=>'PROMED'],
      ['code'=>'EVOLO',           'nom'=>'EVOLO',             'localisation'=>'Tunis · Tunisie',                'type'=>'Habitation / Commerce',     'societe'=>'PROMED'],
    ];

    // Pour agence : vérifier si elle a des projets location assignés
    $agenceLocationCodes = [];
    if ($isAgence) {
        try {
            $alStmt = $pdo->prepare("SELECT UPPER(project_code) FROM agence_location_access WHERE user_id = ?");
            $alStmt->execute([(int)$_SESSION['user_id']]);
            $agenceLocationCodes = $alStmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {}
    }
    $hasLocationAccess = !$isAgence || !empty($agenceLocationCodes);

    // Filtrer les projets location selon le rôle
    $locationProjects = $isAgence
        ? array_values(array_filter($allLocationProjects, function($lp) use ($agenceLocationCodes) {
            return in_array(strtoupper($lp['code']), $agenceLocationCodes);
          }))
        : $allLocationProjects;
    ?>

    <?php if ($hasLocationAccess): ?>
    <!-- LOCATION : projets statiques -->
    ?>
    <div class="section-panel" id="panelLocation">
      <section class="projects-grid">
        <?php foreach ($locationProjects as $lp): ?>
          <?php
            $socLogo = $societeLogos['PROMED'] ?? null;
          ?>
          <a href="<?= htmlspecialchars('prix.php?mode=location&project=' . urlencode($lp['code']), ENT_QUOTES, 'UTF-8'); ?>"
             class="project-btn" style="text-decoration:none;">
            <article class="project-card">
              <?php if ($socLogo && is_file($socLogo)): ?>
                <div class="project-logo-small">
                  <img src="<?= htmlspecialchars($socLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="PROMED">
                </div>
              <?php endif; ?>

              <span class="project-badge loc">Location</span>

              <h2 class="project-name">
                <?= htmlspecialchars($lp['nom'], ENT_QUOTES, 'UTF-8'); ?>
              </h2>

              <p class="project-location">
                <?= htmlspecialchars($lp['localisation'], ENT_QUOTES, 'UTF-8'); ?>
              </p>

              <p class="project-meta">
                Société : <?= htmlspecialchars($lp['societe'], ENT_QUOTES, 'UTF-8'); ?><br>
                <?= htmlspecialchars($lp['type'], ENT_QUOTES, 'UTF-8'); ?>
              </p>
            </article>
          </a>
        <?php endforeach; ?>
      </section>
    </div>
    <?php endif; // hasLocationAccess — fin section location ?>

    <div class="footer-row">
      <span class="user-tag">
        Connecté :
        <?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
          &nbsp;·&nbsp;
          <a href="admin_users.php" style="color:#145f7d;font-weight:600;text-decoration:none">
            ⚙ Utilisateurs
          </a>
          &nbsp;·&nbsp;
          <a href="admin_stats.php" style="color:#145f7d;font-weight:600;text-decoration:none">
            📊 Statistiques
          </a>
        <?php endif; ?>
      </span>
      <a class="logout-link" href="logout.php">Se déconnecter</a>
    </div>
  </div>

<script>
function setMode(mode) {
  const isVente = mode === 'vente';
  document.getElementById('btnVente').classList.toggle('active', isVente);
  document.getElementById('btnLocation').classList.toggle('active', !isVente);
  document.getElementById('panelVente').classList.toggle('visible', isVente);
  document.getElementById('panelLocation').classList.toggle('visible', !isVente);
  document.getElementById('modeHint').textContent = isVente
    ? 'Projets de vente'
    : 'Projets de location — liste des prix';
}
</script>
</body>
</html>
