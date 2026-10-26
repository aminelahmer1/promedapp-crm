<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentCode = strtoupper(trim((string)($_SESSION['current_project_code'] ?? 'AVA')));
if ($currentCode !== 'AVA') {
  header('Location: select_project.php');
  exit;
}

$projectLogos = ['FLAVA'=>'assets/images/LogoFlava.png','AVA'=>'assets/images/logoava.avif','EVOLO'=>'assets/images/LogoPROMED.png'];
$otherProjects = [];
try {
    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    if ($role === 'agence') {
        $agSp = $pdo->prepare("SELECT DISTINCT UPPER(project_code) FROM agence_unit_access WHERE user_id = ?");
        $agSp->execute([(int)$_SESSION['user_id']]);
        $agCodes = array_filter($agSp->fetchAll(PDO::FETCH_COLUMN), fn($c) => $c !== 'AVA');
        if (!empty($agCodes)) {
            $ph = implode(',', array_fill(0, count($agCodes), '?'));
            $sp = $pdo->prepare("SELECT id,code,nom FROM projects WHERE UPPER(code) IN ($ph) ORDER BY code");
            $sp->execute(array_values($agCodes));
            $otherProjects = $sp->fetchAll();
        }
    } else {
        $sp = $pdo->prepare("SELECT id,code,nom FROM projects WHERE UPPER(code) = 'FLAVA' ORDER BY code");
        $sp->execute();
        $otherProjects = $sp->fetchAll();
        // EVOLO : page de prix (comme sur la page Projets)
        $otherProjects[] = ['code' => 'EVOLO', 'nom' => 'EVOLO', 'href' => 'prix.php?mode=sale&project=EVOLO'];
    }
} catch(Throwable $e){}

$protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl    = $protocol . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$avaBrochureUrl = $baseUrl . '/uploads/documents/ava/BOOK%20AVA.pdf';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <title>AVA – Accueil</title>
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

.shell {
  width:100%;
  max-width:1040px;
  background:var(--card-bg);
  border-radius:28px;
  padding:40px 40px 32px;
  border:1px solid #f0f0f0;
  box-shadow:0 18px 40px rgba(0,0,0,0.06);
}

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

.projects-grid {
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));
  gap:22px;
  margin-top:30px;
}

.project-btn {
  display:block;
  width:100%;
  border:none;
  background:none;
  padding:0;
  text-align:left;
  cursor:pointer;
  font:inherit;
  text-decoration:none;
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

.project-card.disabled {
  opacity:.4;
  pointer-events:none;
  cursor:default;
}

.card-icon {
  position:absolute;
  top:22px;
  right:22px;
  width:52px;
  height:52px;
  border-radius:14px;
  background:#f5f5f5;
  display:flex;
  align-items:center;
  justify-content:center;
}

.card-icon svg {
  width:26px;
  height:26px;
  stroke:#555;
  fill:none;
  stroke-width:1.8;
  stroke-linecap:round;
  stroke-linejoin:round;
}

.project-badge {
  display:inline-block;
  font-size:11px;
  padding:3px 10px;
  border-radius:999px;
  background:#111;
  color:#fff;
  margin-bottom:10px;
}

.project-badge.soon {
  background:#f3f4f6;
  color:#9ca3af;
}

.project-name {
  font-size:18px;
  font-weight:600;
  margin:0 0 4px;
  color:#222;
}

.project-meta {
  font-size:12px;
  color:#999;
  line-height:1.5;
  margin:0;
}

.footer-row {
  margin-top:30px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  font-size:12px;
  color:#999;
}

.back-link {
  color:#111;
  text-decoration:none;
  font-size:13px;
  display:inline-flex;
  align-items:center;
  gap:5px;
}

.back-link:hover { text-decoration:underline; }

.logout-link {
  color:#b3261e;
  text-decoration:none;
}

.logout-link:hover { text-decoration:underline; }

/* Project switcher — top-left of shell */
.shell { position:relative; }
.proj-switcher {
  position:absolute;
  top:20px;
  left:24px;
  display:inline-block;
}
.ps-toggle {
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:6px 12px;
  border-radius:999px;
  border:1.5px solid #e0e0e0;
  background:#fff;
  font:inherit;
  font-size:12px;
  font-weight:600;
  color:#444;
  cursor:pointer;
  transition:border-color .15s, box-shadow .15s;
  white-space:nowrap;
}
.ps-toggle:hover {
  border-color:#bbb;
  box-shadow:0 2px 8px rgba(0,0,0,.07);
}
.ps-toggle svg {
  width:13px; height:13px;
  stroke:#888; fill:none;
  stroke-width:2.2; stroke-linecap:round; stroke-linejoin:round;
  transition:transform .2s;
}
.proj-switcher.open .ps-toggle svg { transform:rotate(180deg); }
.ps-dropdown {
  display:none;
  position:absolute;
  top:calc(100% + 6px);
  left:0;
  min-width:190px;
  background:#fff;
  border:1px solid #e5e5e5;
  border-radius:14px;
  box-shadow:0 8px 28px rgba(0,0,0,.12);
  overflow:hidden;
  z-index:50;
}
.proj-switcher.open .ps-dropdown { display:block; }
.ps-label {
  font-size:10px;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.06em;
  color:#aaa;
  padding:10px 14px 6px;
}
.ps-item {
  display:flex;
  align-items:center;
  gap:10px;
  padding:10px 14px;
  text-decoration:none;
  color:#111;
  font-size:13px;
  font-weight:600;
  transition:background .12s;
}
.ps-item:hover { background:#f5f5f5; }
.ps-item img {
  width:32px; height:24px;
  object-fit:contain;
  border-radius:4px;
}
.ps-item-code {
  font-size:11px;
  color:#888;
  font-weight:400;
}
.ps-divider { height:1px; background:#f0f0f0; margin:0 10px; }

/* Brochure card actions */
.brochure-card { display:flex; flex-direction:column; }
.brochure-card .project-meta { flex:1; }
.card-actions {
  display:flex;
  gap:8px;
  margin-top:16px;
}
.ca-btn {
  flex:1;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:5px;
  padding:8px 10px;
  border-radius:10px;
  border:1.5px solid #e0e0e0;
  background:#f9f9f9;
  font:inherit;
  font-size:12px;
  font-weight:600;
  color:#333;
  text-decoration:none;
  cursor:pointer;
  transition:all .15s;
}
.ca-btn:hover { background:#efefef; border-color:#ccc; }
.ca-btn svg {
  width:14px; height:14px;
  stroke:currentColor; fill:none;
  stroke-width:2; stroke-linecap:round; stroke-linejoin:round;
  flex-shrink:0;
}
.ca-wa {
  background:#dcf5e7;
  border-color:#a7dfbd;
  color:#1a7a4a;
}
.ca-wa:hover { background:#c8edda; border-color:#8ecfaa; }

@media (max-width:640px) {
  body { padding:20px; }
  .shell { padding:26px 18px 20px; border-radius:20px; }
}

/* LIGHTBOX */
.lb-overlay {
  display:none;
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.88);
  z-index:1000;
  flex-direction:column;
  align-items:center;
  justify-content:center;
}
.lb-overlay.open { display:flex; }
.lb-bar {
  width:100%;
  max-width:960px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:12px 16px;
  color:#fff;
}
.lb-title { font-size:15px; font-weight:600; }
.lb-counter { font-size:13px; color:rgba(255,255,255,.5); }
.lb-close {
  background:none; border:none; color:#fff;
  font-size:28px; line-height:1; cursor:pointer; padding:0 4px;
}
.lb-main {
  flex:1; display:flex; align-items:center; justify-content:center;
  width:100%; position:relative;
}
.lb-main img {
  max-width:calc(100vw - 100px);
  max-height:calc(100vh - 160px);
  object-fit:contain;
  border-radius:8px;
  box-shadow:0 4px 40px rgba(0,0,0,.6);
  transition:opacity .2s;
}
.lb-nav {
  position:absolute; top:50%; transform:translateY(-50%);
  background:rgba(255,255,255,.12); border:none; color:#fff;
  font-size:28px; width:48px; height:64px;
  display:flex; align-items:center; justify-content:center;
  cursor:pointer; border-radius:8px; transition:background .15s; z-index:2;
}
.lb-nav:hover { background:rgba(255,255,255,.25); }
.lb-nav.prev { left:8px; }
.lb-nav.next { right:8px; }
.lb-thumbs {
  display:flex; gap:8px; padding:12px 16px;
  overflow-x:auto; max-width:100vw; justify-content:center;
}
.lb-thumbs img {
  width:56px; height:42px; object-fit:cover; border-radius:5px;
  cursor:pointer; opacity:.55; border:2px solid transparent;
  transition:opacity .15s, border-color .15s; flex-shrink:0;
}
.lb-thumbs img.active { opacity:1; border-color:#cab06d; }
.lb-empty {
  color:rgba(255,255,255,.5);
  font-size:14px;
  padding:40px;
}
  </style>
</head>
<body>
<div class="shell">

  <?php if (!empty($otherProjects)): ?>
  <div class="proj-switcher" id="projSwitcher">
    <button class="ps-toggle" type="button" onclick="toggleSwitcher()">
      <svg viewBox="0 0 24 24"><polyline points="15 17 20 12 15 7"/><polyline points="4 12 20 12"/></svg>
      Changer de projet
      <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
    </button>
    <div class="ps-dropdown">
      <div class="ps-label">Basculer vers</div>
      <?php foreach($otherProjects as $i => $op): ?>
        <?php if($i > 0): ?><div class="ps-divider"></div><?php endif; ?>
        <?php $logo = $projectLogos[$op['code']] ?? null; ?>
        <a class="ps-item" href="<?=htmlspecialchars($op['href'] ?? ('switch_project.php?code=' . urlencode($op['code'])))?>">
          <?php if($logo && is_file(__DIR__.'/'.$logo)): ?>
            <img src="<?=htmlspecialchars($logo)?>" alt="<?=htmlspecialchars($op['code'])?>">
          <?php else: ?>
            <span style="width:32px;height:24px;background:#f0f0f0;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700"><?=htmlspecialchars($op['code'])?></span>
          <?php endif; ?>
          <div>
            <div><?=htmlspecialchars($op['nom'])?></div>
            <div class="ps-item-code"><?=htmlspecialchars($op['code'])?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <header class="header">
    <div class="logo-main">
      <?php $avaLogo = 'assets/images/logoava.avif'; ?>
      <?php if (is_file(__DIR__ . '/' . $avaLogo)): ?>
        <img src="<?= htmlspecialchars($avaLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="AVA">
      <?php else: ?>
        <span style="font-size:32px;font-weight:800;letter-spacing:.1em;">AVA</span>
      <?php endif; ?>
    </div>
    <h1 class="title">Espace projet</h1>
    <p class="subtitle">Choisissez une section</p>
  </header>

  <section class="projects-grid">

    <!-- Plan d'étage -->
    <a class="project-btn" href="dashboard_ava.php">
      <article class="project-card">
        <div class="card-icon">
          <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 9v12"/></svg>
        </div>
        <span class="project-badge">Vente</span>
        <h2 class="project-name">Plan d'étage</h2>
        <p class="project-meta">Disponibilité &amp; gestion des unités</p>
      </article>
    </a>

    <!-- Liste des Prix -->
    <a class="project-btn" href="liste_prix_ava.php">
      <article class="project-card">
        <div class="card-icon">
          <svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
        </div>
        <span class="project-badge">Vente</span>
        <h2 class="project-name">Liste des Prix</h2>
        <p class="project-meta">Tarifs actualisés par typologie</p>
      </article>
    </a>

    <!-- Brochure -->
    <div class="project-btn" style="cursor:default">
      <article class="project-card brochure-card">
        <div class="card-icon">
          <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
        </div>
        <span class="project-badge">Document</span>
        <h2 class="project-name">Brochure</h2>
        <p class="project-meta">Book commercial AVA</p>
        <div class="card-actions">
          <a class="ca-btn" href="uploads/documents/ava/BOOK AVA.pdf" target="_blank">
            <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            Voir
          </a>
          <button class="ca-btn ca-wa" type="button" onclick="shareWhatsApp()">
            <svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
            WhatsApp
          </button>
        </div>
      </article>
    </div>

    <!-- Rendue Intérieur -->
    <button class="project-btn" type="button" onclick="openLightbox('int')">
      <article class="project-card">
        <div class="card-icon">
          <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="14" rx="2"/><path d="M3 13l4-4 4 4 4-5 4 5"/><circle cx="8.5" cy="8.5" r="1.5"/></svg>
        </div>
        <span class="project-badge">Visuel</span>
        <h2 class="project-name">Rendue Intérieur</h2>
        <p class="project-meta">Visualisations des espaces intérieurs</p>
      </article>
    </button>

    <!-- Rendue Extérieur -->
    <button class="project-btn" type="button" onclick="openLightbox('ext')">
      <article class="project-card">
        <div class="card-icon">
          <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </div>
        <span class="project-badge">Visuel</span>
        <h2 class="project-name">Rendue Extérieur</h2>
        <p class="project-meta">Visualisations de la façade &amp; extérieurs</p>
      </article>
    </button>

    <?php if (($_SESSION['role'] ?? '') !== 'agence'): ?>
    <!-- Suivi Client -->
    <a class="project-btn" href="crm_dashboard.php">
      <article class="project-card">
        <div class="card-icon">
          <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <span class="project-badge">CRM</span>
        <h2 class="project-name">Suivi Client</h2>
        <p class="project-meta">Promesses, paiements &amp; dossiers</p>
      </article>
    </a>
    <?php endif; ?>

  </section>

  <div class="footer-row">
    <a class="back-link" href="select_project.php">&#8592; Projets</a>
    <a class="logout-link" href="logout.php">Se déconnecter</a>
  </div>

</div>

<!-- LIGHTBOX -->
<div class="lb-overlay" id="lb">
  <div class="lb-bar">
    <span class="lb-title" id="lbTitle"></span>
    <span class="lb-counter" id="lbCounter"></span>
    <button class="lb-close" onclick="closeLightbox()">&#x2715;</button>
  </div>
  <div class="lb-main">
    <button class="lb-nav prev" onclick="lbNav(-1)">&#x2039;</button>
    <img id="lbImg" src="" alt="" />
    <div id="lbEmpty" class="lb-empty" style="display:none">Aucune image disponible.</div>
    <button class="lb-nav next" onclick="lbNav(1)">&#x203A;</button>
  </div>
  <div class="lb-thumbs" id="lbThumbs"></div>
</div>

<script>
const GALLERIES = {
  int: {
    title: "Rendue Intérieur",
    images: [
      "uploads/documents/ava/Rendue interieur/cuisine.jpg",
      "uploads/documents/ava/Rendue interieur/couloir.jpg",
      "uploads/documents/ava/Rendue interieur/toilette.jpg",
      "uploads/documents/ava/Rendue interieur/piscine.jpg",
      "uploads/documents/ava/Rendue interieur/SuitePanoramique.jpg",
      "uploads/documents/ava/Rendue interieur/duplex.jpg",
      "uploads/documents/ava/Rendue interieur/salonetsalleMange.jpg",
      "uploads/documents/ava/Rendue interieur/salleManger.jpg",
    ]
  },
  ext: {
    title: "Rendue Extérieur",
    images: [
      "uploads/documents/ava/Rendue exterieur/Façade Prop 2.png",
      "uploads/documents/ava/Rendue exterieur/FacadePrin2.png",
    ]
  }
};

let lbImages = [], lbIdx = 0;

function openLightbox(key) {
  const g = GALLERIES[key];
  lbImages = g.images;
  lbIdx = 0;
  document.getElementById("lbTitle").textContent = g.title;
  document.getElementById("lb").classList.add("open");
  document.addEventListener("keydown", lbKey);
  if (lbImages.length === 0) {
    document.getElementById("lbImg").style.display = "none";
    document.getElementById("lbEmpty").style.display = "";
    document.getElementById("lbCounter").textContent = "";
    document.querySelectorAll(".lb-nav").forEach(b => b.style.display = "none");
    document.getElementById("lbThumbs").innerHTML = "";
    return;
  }
  document.getElementById("lbImg").style.display = "";
  document.getElementById("lbEmpty").style.display = "none";
  document.querySelectorAll(".lb-nav").forEach(b => b.style.display = "");
  buildThumbs();
  lbShow();
}

function closeLightbox() {
  document.getElementById("lb").classList.remove("open");
  document.removeEventListener("keydown", lbKey);
}

function buildThumbs() {
  const c = document.getElementById("lbThumbs");
  c.innerHTML = "";
  lbImages.forEach((src, i) => {
    const img = document.createElement("img");
    img.src = src; img.alt = "";
    img.onclick = () => { lbIdx = i; lbShow(); };
    c.appendChild(img);
  });
}

function lbShow() {
  const img = document.getElementById("lbImg");
  img.style.opacity = "0";
  img.src = lbImages[lbIdx];
  img.onload = () => { img.style.opacity = "1"; };
  document.getElementById("lbCounter").textContent = (lbIdx + 1) + " / " + lbImages.length;
  document.getElementById("lbThumbs").querySelectorAll("img").forEach((t, i) => {
    t.classList.toggle("active", i === lbIdx);
    if (i === lbIdx) t.scrollIntoView({ behavior:"smooth", block:"nearest", inline:"center" });
  });
}

function lbNav(dir) {
  if (!lbImages.length) return;
  lbIdx = (lbIdx + dir + lbImages.length) % lbImages.length;
  lbShow();
}

function lbKey(e) {
  if (e.key === "ArrowRight") lbNav(1);
  else if (e.key === "ArrowLeft") lbNav(-1);
  else if (e.key === "Escape") closeLightbox();
}

document.getElementById("lb").addEventListener("click", e => {
  if (e.target === document.getElementById("lb")) closeLightbox();
});

// WhatsApp share
const AVA_BROCHURE_URL = <?= json_encode($avaBrochureUrl) ?>;
function shareWhatsApp() {
  const msg = 'Bonjour,\n\nDécouvrez la brochure du projet AVA :\n' + AVA_BROCHURE_URL;
  window.open('https://wa.me/?text=' + encodeURIComponent(msg), '_blank');
}

// Project switcher
function toggleSwitcher() {
  document.getElementById("projSwitcher").classList.toggle("open");
}
document.addEventListener("click", e => {
  const sw = document.getElementById("projSwitcher");
  if (sw && !sw.contains(e.target)) sw.classList.remove("open");
});
</script>
</body>
</html>
