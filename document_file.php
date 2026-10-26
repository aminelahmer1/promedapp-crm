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

$download = (string)($_GET['download'] ?? '0') === '1';
$path = (string)$document['path'];
$filename = (string)$document['filename'];
$mime = (string)$document['mime'];
$size = filesize($path);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)$size);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');

readfile($path);
exit;
