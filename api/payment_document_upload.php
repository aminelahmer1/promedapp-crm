<?php
ini_set('display_errors', '0');
error_reporting(0);
require __DIR__ . '/../config.php';
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}
header('Content-Type: application/json; charset=utf-8');

$payment_id     = (int)($_POST['payment_id']     ?? 0);
$promise_id     = (int)($_POST['promise_id']     ?? 0);
$installment_id = (int)($_POST['installment_id'] ?? 0);

if (!$promise_id) {
    http_response_code(400);
    echo json_encode(['error' => 'promise_id requis']);
    exit;
}

// Vérifier présence fichier
if (empty($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    $upload_errors = [
        UPLOAD_ERR_INI_SIZE   => 'Fichier trop volumineux (limite serveur)',
        UPLOAD_ERR_FORM_SIZE  => 'Fichier trop volumineux (limite formulaire)',
        UPLOAD_ERR_PARTIAL    => 'Upload partiel, réessayez',
        UPLOAD_ERR_NO_FILE    => 'Aucun fichier sélectionné',
        UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant',
        UPLOAD_ERR_CANT_WRITE => 'Impossible d\'écrire sur le disque',
        UPLOAD_ERR_EXTENSION  => 'Upload bloqué par extension PHP',
    ];
    $code = $_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE;
    http_response_code(400);
    echo json_encode(['error' => $upload_errors[$code] ?? 'Erreur upload inconnue']);
    exit;
}

// Taille max 10 Mo
$max_size = 10 * 1024 * 1024;
if ($_FILES['document']['size'] > $max_size) {
    http_response_code(400);
    echo json_encode(['error' => 'Fichier trop volumineux (max 10 Mo)']);
    exit;
}

// Vérification MIME réel (pas juste l'extension)
$allowed_mimes = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $_FILES['document']['tmp_name']);
finfo_close($finfo);

if (!isset($allowed_mimes[$mime])) {
    http_response_code(400);
    echo json_encode(['error' => 'Type de fichier non autorisé. Acceptés : PDF, JPG, PNG, WEBP']);
    exit;
}

// Extension depuis le nom original (double vérification)
$orig_name = basename($_FILES['document']['name']);
$orig_ext  = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
$dangerous = ['php', 'php3', 'php4', 'php5', 'phtml', 'js', 'html', 'htm', 'exe', 'sh', 'bat', 'zip', 'tar'];
if (in_array($orig_ext, $dangerous, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Extension de fichier refusée']);
    exit;
}

// Dossier de destination — créer automatiquement si absent
$dest_dir = __DIR__ . '/../uploads/payment_docs/';
if (!is_dir($dest_dir)) {
    if (!mkdir($dest_dir, 0755, true)) {
        error_log('[upload] Impossible de créer le dossier : ' . $dest_dir);
        http_response_code(500);
        echo json_encode(['error' => 'Impossible de créer le dossier d\'upload']);
        exit;
    }
}

// Nom unique — jamais d'écrasement
$ext          = $allowed_mimes[$mime];
$stored_name  = sprintf('pay_%d_%d_%s.%s', $promise_id, $payment_id ?: $installment_id, uniqid('', true), $ext);
$dest_path    = $dest_dir . $stored_name;
$relative_path = 'uploads/payment_docs/' . $stored_name;

if (!move_uploaded_file($_FILES['document']['tmp_name'], $dest_path)) {
    error_log('[upload] move_uploaded_file échoué vers : ' . $dest_path);
    http_response_code(500);
    echo json_encode(['error' => 'Impossible de sauvegarder le fichier sur le serveur']);
    exit;
}

try {
    // Ajouter colonne installment_id si absente (migration douce)
    try {
        $pdo->exec("ALTER TABLE payment_documents ADD COLUMN installment_id INT NULL DEFAULT NULL");
    } catch (Throwable $_) {}

    $pdo->prepare("
        INSERT INTO payment_documents
          (payment_id, promise_id, installment_id, original_name, stored_name,
           file_path, file_size, mime_type, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $payment_id     ?: 0,
        $promise_id,
        $installment_id ?: null,
        $orig_name,
        $stored_name,
        $relative_path,
        $_FILES['document']['size'],
        $mime,
        $_SESSION['user_id'],
    ]);

    $doc_id = (int)$pdo->lastInsertId();
    echo json_encode([
        'success'    => true,
        'id'         => $doc_id,
        'path'       => $relative_path,
        'name'       => $orig_name,
        'payment_id' => $payment_id,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    @unlink($dest_path);
    error_log('[upload] DB error : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur base de données lors de l\'enregistrement']);
}
