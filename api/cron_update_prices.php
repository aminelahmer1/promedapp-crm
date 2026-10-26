<?php
/**
 * Cron mensuel — mise à jour automatique des prix + notifications email
 * URL : /api/cron_update_prices.php?token=promedCron2025!
 * Hostinger cron : 0 8 1 * *  (1er du mois à 8h00)
 */
require __DIR__ . '/../config.php';

/* ── Sécurité : token obligatoire ───────────────────────── */
$expectedToken = null;
try {
    $r = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='cron_secret'");
    $expectedToken = $r ? $r->fetchColumn() : null;
} catch (Throwable $e) {}

$token = $_GET['token'] ?? '';
if (!$expectedToken || $token !== $expectedToken) {
    http_response_code(403);
    echo 'Accès refusé'; exit;
}

header('Content-Type: text/plain; charset=utf-8');
$log = [];

/* ── Helper settings ─────────────────────────────────────── */
function getSetting(PDO $pdo, string $key): ?string {
    try {
        $r = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $r->execute([$key]);
        $v = $r->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (Throwable $e) { return null; }
}

/* ── Traiter un projet ───────────────────────────────────── */
function processProject(PDO $pdo, string $project, array &$log): ?array {
    $key = strtolower($project);

    if (getSetting($pdo, $key . '_price_frozen') === '1') {
        $log[] = "[$project] Prix BLOQUÉS — aucune mise à jour.";
        return null;
    }

    $url = 'http://localhost/' . (str_contains(__DIR__, 'PromedApp') ? 'teest/PromedApp' : '') . '/api/' . $key . '_update_prices.php';

    /* Appel interne via include (plus fiable que HTTP en local) */
    ob_start();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    if ($key === 'flava') {
        include __DIR__ . '/flava_update_prices.php';
    } else {
        include __DIR__ . '/ava_update_prices.php';
    }
    $raw = ob_get_clean();

    $data = json_decode($raw, true);
    if (!$data || isset($data['error'])) {
        $log[] = "[$project] Erreur mise à jour : " . ($data['error'] ?? $raw);
        return null;
    }

    $log[] = "[$project] Prix mis à jour pour " . ($data['mois'] ?? '?');
    return $data;
}

/* ── Récupérer les destinataires ─────────────────────────── */
function getRecipients(PDO $pdo): array {
    $rows = $pdo->query("
        SELECT full_name,
               CASE WHEN email IS NOT NULL AND email LIKE '%@%' THEN email
                    WHEN username LIKE '%@%' THEN username
                    ELSE NULL END AS addr
        FROM users
        WHERE is_active = 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        if ($r['addr']) $out[] = $r;
    }
    return $out;
}

/* ── Envoyer email ───────────────────────────────────────── */
function sendNotification(array $recipients, string $project, array $data, array &$log): void {
    $now      = new DateTime();
    $moisStr  = $now->format('F Y');
    $s1       = $data['s1_m2_ht']   ?? '—';
    $lignes   = $data['lignes']      ?? [];
    $from     = 'no-reply@promed.tn';
    $fromName = 'PromedApp';

    $subject  = "[$project] Mise à jour des prix – $moisStr";

    $body = "Bonjour,\n\n"
          . "Les prix $project ont été mis à jour automatiquement pour $moisStr.\n\n";

    if ($project === 'FLAVA') {
        $body .= "Récapitulatif :\n"
               . "  • S+1  : " . number_format((float)($data['s1_m2_ht']  ?? 0), 3, ',', ' ') . " TND/m² HT\n"
               . "  • S+2  : " . number_format((float)($data['s2_m2_ht']  ?? 0), 3, ',', ' ') . " TND/m² HT\n"
               . "  • S+3  : " . number_format((float)($data['s3_m2_ht']  ?? 0), 3, ',', ' ') . " TND/m² HT\n"
               . "  • Parking : " . number_format((float)($data['park_ht'] ?? 0), 0, ',', ' ') . " TND\n";
    } else {
        $body .= "Récapitulatif :\n"
               . "  • Prix/m² HT : " . number_format((float)($data['apt_m2_ht'] ?? 0), 3, ',', ' ') . " TND/m²\n"
               . "  • Parking    : " . number_format((float)($data['park_ht']   ?? 0), 0, ',', ' ') . " TND\n";
    }

    $body .= "\nConnectez-vous à PromedApp pour consulter la liste des prix.\n\n"
           . "— Système PromedApp (notification automatique)";

    $headers  = "From: $fromName <$from>\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    $sent = 0;
    foreach ($recipients as $r) {
        $to = ($r['full_name'] && $r['full_name'] !== $r['addr'])
            ? mb_encode_mimeheader($r['full_name'], 'UTF-8') . ' <' . $r['addr'] . '>'
            : $r['addr'];
        if (mail($to, $subject, $body, $headers)) {
            $sent++;
        }
    }
    $log[] = "[$project] Notifications envoyées : $sent/" . count($recipients);
}

/* ── Main ────────────────────────────────────────────────── */
$now = new DateTime();
$log[] = "=== Cron prix — " . $now->format('Y-m-d H:i:s') . " ===";

$recipients = getRecipients($pdo);
$log[]      = count($recipients) . " destinataire(s) trouvé(s)";

foreach (['flava', 'ava'] as $proj) {
    $data = processProject($pdo, strtoupper($proj), $log);
    if ($data && !empty($recipients)) {
        sendNotification($recipients, strtoupper($proj), $data, $log);
    }
}

$log[] = "=== Terminé ===";
echo implode("\n", $log) . "\n";
