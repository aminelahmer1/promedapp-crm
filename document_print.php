<?php
require __DIR__ . '/config.php';
require __DIR__ . '/documents_catalog.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$slug = trim((string)($_GET['slug'] ?? ''));
$document = promed_document_find($slug);
if (!$document || !promed_document_exists($document)) {
    http_response_code(404);
    echo 'Document introuvable.';
    exit;
}

$openUrl = promed_document_stream_url($slug, false);
$downloadUrl = promed_document_stream_url($slug, true);
$editorUrl = promed_document_editor_url($document);
$editorLabel = promed_document_editor_label($document);
$projectLabel = promed_document_project_label((string)$document['project']);
$extension = strtolower((string)$document['extension']);
$browserPreview = in_array($extension, ['pdf', 'png', 'jpg', 'jpeg', 'webp'], true);
$officeDocument = promed_document_can_use_office_edit($document);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Impression - <?php echo htmlspecialchars((string)$document['title'], ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="assets/styles.css" />
</head>
<body class="docs-page">
  <header class="app-header">
    <div class="brand">
      <span class="logo">PROMED</span>
      <span class="subtitle">Impression du document</span>
    </div>
    <div class="user-bar">
      <a class="header-link" href="documents.php">Bibliotheque</a>
      <a class="logout-link" href="logout.php">Se deconnecter</a>
    </div>
  </header>

  <main class="doc-print-layout">
    <section class="doc-print-panel">
      <div class="doc-project"><?php echo htmlspecialchars($projectLabel, ENT_QUOTES, 'UTF-8'); ?></div>
      <h1><?php echo htmlspecialchars((string)$document['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
      <p class="doc-desc"><?php echo htmlspecialchars((string)$document['description'], ENT_QUOTES, 'UTF-8'); ?></p>

      <div class="doc-actions">
        <a class="doc-btn doc-btn-primary" href="<?php echo htmlspecialchars($editorUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($editorLabel, ENT_QUOTES, 'UTF-8'); ?></a>
        <a class="doc-btn" href="<?php echo htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8'); ?>" download="<?php echo htmlspecialchars((string)$document['filename'], ENT_QUOTES, 'UTF-8'); ?>">Telecharger</a>
        <?php if ($browserPreview): ?>
          <button class="doc-btn" type="button" id="printDocumentBtn">Lancer impression</button>
        <?php endif; ?>
      </div>

      <?php if ($officeDocument): ?>
        <div class="doc-print-helper">
          <h2>Impression des fichiers Word / Excel</h2>
          <p class="doc-note-inline">Le navigateur ne peut pas afficher correctement ces formats pour l impression. Ouvre le modele dans Word ou Excel, fais tes modifications, puis imprime depuis l application Office.</p>
          <ol class="doc-print-steps">
            <li>Clique sur <?php echo htmlspecialchars($editorLabel, ENT_QUOTES, 'UTF-8'); ?>.</li>
            <li>Modifie le document.</li>
            <li>Dans Word ou Excel, utilise l impression normale du poste.</li>
          </ol>
        </div>
      <?php elseif ($browserPreview): ?>
        <p class="doc-note-inline">Apercu navigateur disponible. Le bouton d impression imprime directement l apercu.</p>
      <?php else: ?>
        <p class="doc-note-inline">Ce format ne dispose pas d apercu navigateur. Ouvre le fichier dans son application puis imprime-le depuis cette application.</p>
      <?php endif; ?>
    </section>

    <section class="doc-viewer-card">
      <?php if ($browserPreview): ?>
        <iframe
          id="docFrame"
          class="doc-frame"
          src="<?php echo htmlspecialchars($openUrl, ENT_QUOTES, 'UTF-8'); ?>"
          title="<?php echo htmlspecialchars((string)$document['title'], ENT_QUOTES, 'UTF-8'); ?>"
        ></iframe>
      <?php else: ?>
        <div class="doc-viewer-placeholder">
          <div class="doc-viewer-placeholder-inner">
            <div class="doc-ext doc-ext-large"><?php echo htmlspecialchars(strtoupper((string)$document['extension']), ENT_QUOTES, 'UTF-8'); ?></div>
            <h2><?php echo htmlspecialchars((string)$document['filename'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p>Pas d apercu navigateur pour ce type de fichier.</p>
            <div class="doc-actions">
              <a class="doc-btn doc-btn-primary" href="<?php echo htmlspecialchars($editorUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($editorLabel, ENT_QUOTES, 'UTF-8'); ?></a>
              <a class="doc-btn" href="<?php echo htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8'); ?>" download="<?php echo htmlspecialchars((string)$document['filename'], ENT_QUOTES, 'UTF-8'); ?>">Telecharger</a>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <?php if ($browserPreview): ?>
    <script>
      (function () {
        const printBtn = document.getElementById('printDocumentBtn');
        const frame = document.getElementById('docFrame');
        if (!printBtn || !frame) return;

        printBtn.addEventListener('click', function () {
          try {
            const target = frame.contentWindow;
            if (target && typeof target.focus === 'function' && typeof target.print === 'function') {
              target.focus();
              target.print();
              return;
            }
          } catch (error) {
          }
          window.print();
        });
      })();
    </script>
  <?php endif; ?>
</body>
</html>
