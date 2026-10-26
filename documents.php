<?php
require __DIR__ . '/config.php';
require __DIR__ . '/documents_catalog.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentProject = strtoupper(trim((string)($_SESSION['current_project_code'] ?? '')));
$dashboardUrl = 'select_project.php';
if ($currentProject === 'AVA') {
    $dashboardUrl = 'dashboard_ava.php';
} elseif ($currentProject === 'FLAVA') {
    $dashboardUrl = 'dashboard_flava.php';
}

$documents = array_values(promed_document_catalog());
usort($documents, static function (array $left, array $right) use ($currentProject): int {
    $score = static function (array $document) use ($currentProject): int {
        $project = strtoupper((string)($document['project'] ?? ''));
        if ($project === $currentProject) return 0;
        if ($project === 'COMMUN') return 1;
        return 2;
    };

    $leftScore = $score($left);
    $rightScore = $score($right);
    if ($leftScore !== $rightScore) {
        return $leftScore <=> $rightScore;
    }

    return strcmp((string)$left['title'], (string)$right['title']);
});
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Documents - PROMEDAPP</title>
  <link rel="stylesheet" href="assets/styles.css" />
</head>
<body class="docs-page">
  <header class="app-header">
    <div class="brand">
      <span class="logo">PROMED</span>
      <span class="subtitle">Bibliotheque documentaire commerciale</span>
    </div>
    <div class="user-bar">
      <a class="header-link" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>">Retour au projet</a>
      <a class="logout-link" href="logout.php">Se deconnecter</a>
    </div>
  </header>

  <main class="docs-layout">
    <section class="docs-hero">
      <div>
        <h1>Documents du quotidien</h1>
        <p>Ces modeles sont disponibles dans l application pour modification et telechargement.</p>
      </div>
      <div class="docs-hero-meta">
        <span class="docs-chip">Projet actif : <?php echo htmlspecialchars($currentProject !== '' ? $currentProject : 'Aucun', ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="docs-chip">4 documents</span>
      </div>
    </section>

    <section class="doc-grid">
      <?php foreach ($documents as $document): ?>
        <?php
          $slug = (string)$document['slug'];
          $projectLabel = promed_document_project_label((string)$document['project']);
          $exists = promed_document_exists($document);
          $editorUrl = promed_document_editor_url($document);
          $downloadUrl = promed_document_stream_url($slug, true);
        ?>
        <article class="doc-card<?php echo $exists ? '' : ' is-missing'; ?>">
          <div class="doc-card-top">
            <div>
              <div class="doc-project"><?php echo htmlspecialchars($projectLabel, ENT_QUOTES, 'UTF-8'); ?></div>
              <h2><?php echo htmlspecialchars((string)$document['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
            </div>
            <span class="doc-ext"><?php echo htmlspecialchars(strtoupper((string)$document['extension']), ENT_QUOTES, 'UTF-8'); ?></span>
          </div>

          <p class="doc-desc"><?php echo htmlspecialchars((string)$document['description'], ENT_QUOTES, 'UTF-8'); ?></p>

          <dl class="doc-meta">
            <div>
              <dt>Fichier</dt>
              <dd><?php echo htmlspecialchars((string)$document['filename'], ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
            <div>
              <dt>Etat</dt>
              <dd><?php echo $exists ? 'Disponible' : 'Manquant'; ?></dd>
            </div>
          </dl>

          <div class="doc-actions">
            <a class="doc-btn doc-btn-primary" href="<?php echo htmlspecialchars($editorUrl, ENT_QUOTES, 'UTF-8'); ?>">
              Modifier
            </a>
            <a class="doc-btn" href="<?php echo htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8'); ?>" download="<?php echo htmlspecialchars((string)$document['filename'], ENT_QUOTES, 'UTF-8'); ?>">
              Telecharger
            </a>
          </div>
        </article>
      <?php endforeach; ?>
    </section>

  </main>
</body>
</html>
