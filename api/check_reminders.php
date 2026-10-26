<?php
/**
 * check_reminders.php
 * À appeler : via cron quotidien OU au login de chaque utilisateur.
 * Détecte les échéances à J-2, crée une notification + envoie email au client.
 * Idempotent : ne renotifie pas si reminder_sent = 1.
 */
ini_set('display_errors', '0');
error_reporting(0);
ob_start();
require __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

// Accepte appel cron (CLI) ou utilisateur connecté
if (php_sapi_name() !== 'cli' && empty($_SESSION['user_id'])) {
    ob_clean(); http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']); exit;
}

/* ── Vérification des prérequis (tables + colonnes) ─────────────────────
 * L'API s'auto-répare : elle ajoute les colonnes manquantes au lieu de planter.
 */
function table_exists_r(PDO $pdo, string $table): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->execute([$table]);
    return (bool)$s->fetchColumn();
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$table, $column]);
    if (!(bool)$s->fetchColumn()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

// Tables obligatoires
$required_tables = ['payment_installments', 'payment_schedules', 'promises', 'clients', 'notifications'];
$missing_tables  = [];
foreach ($required_tables as $t) {
    if (!table_exists_r($pdo, $t)) $missing_tables[] = $t;
}
if ($missing_tables) {
    ob_clean(); http_response_code(503);
    echo json_encode([
        'error'          => 'Tables manquantes — lancez la migration paiements',
        'missing_tables' => $missing_tables,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Colonnes auto-réparées si absentes
try {
    ensure_column($pdo, 'payment_installments', 'reminder_sent',    'TINYINT(1) NOT NULL DEFAULT 0');
    ensure_column($pdo, 'payment_installments', 'reminder_sent_at', 'DATETIME NULL');
} catch (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['error' => 'Impossible d\'ajouter les colonnes reminder : ' . $e->getMessage()]);
    exit;
}

try {
    // ── Chercher toutes les échéances dues dans exactement 2 jours, non encore notifiées
    $stmt = $pdo->prepare("
        SELECT
            pi.id          AS installment_id,
            pi.schedule_id,
            pi.label,
            pi.due_date,
            pi.expected_amount,
            pi.paid_amount,
            pi.remaining_amount,
            pi.status      AS inst_status,
            ps.promise_id,
            ps.client_id,
            ps.unit_code,
            p.promise_number,
            c.nom          AS client_nom,
            c.email        AS client_email,
            c.tel          AS client_tel
        FROM payment_installments pi
        JOIN payment_schedules ps ON ps.id = pi.schedule_id
        JOIN promises p           ON p.id  = ps.promise_id
        JOIN clients  c           ON c.id  = ps.client_id
        WHERE pi.due_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY)
          AND pi.status   IN ('pending', 'partially_paid')
          AND pi.reminder_sent = 0
    ");
    $stmt->execute();
    $upcoming = $stmt->fetchAll();

    $created = 0;
    $emailed = 0;
    $errors  = [];

    foreach ($upcoming as $row) {
        $amount    = (float)$row['remaining_amount'] > 0 ? (float)$row['remaining_amount'] : (float)$row['expected_amount'];
        $label     = $row['label'] ?: 'Échéance';
        $due_fmt   = date('d/m/Y', strtotime($row['due_date']));
        $unit      = $row['unit_code'];
        $pv_num    = $row['promise_number'];
        $nom       = $row['client_nom'];
        $email_to  = $row['client_email'];

        $msg = "Rappel : L'échéance « $label » de " . number_format($amount, 3, ',', ' ') . " TND pour l'unité $unit (PV $pv_num) est due le $due_fmt.";

        // ── Créer notification interne
        try {
            $pdo->prepare("
                INSERT INTO notifications
                  (type, installment_id, promise_id, client_id, unit_code, client_nom, client_email, amount, due_date, message)
                VALUES ('payment_reminder', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $row['installment_id'],
                $row['promise_id'],
                $row['client_id'],
                $unit,
                $nom,
                $email_to,
                $amount,
                $row['due_date'],
                $msg,
            ]);
            $created++;
        } catch (Throwable $e) {
            $errors[] = 'notif: ' . $e->getMessage();
        }

        // ── Envoyer email au client
        $email_sent = false;
        if ($email_to && filter_var($email_to, FILTER_VALIDATE_EMAIL)) {
            $subject = "Rappel paiement – Unité $unit – Échéance le $due_fmt";
            $body    = buildEmailBody($nom, $label, $amount, $due_fmt, $unit, $pv_num);
            $email_sent = sendMail($email_to, $nom, $subject, $body, $errors);
            if ($email_sent) $emailed++;
        }

        // ── Marquer comme notifié
        try {
            $pdo->prepare("UPDATE payment_installments SET reminder_sent=1, reminder_sent_at=NOW() WHERE id=?")
                ->execute([$row['installment_id']]);

            if ($email_sent) {
                $pdo->prepare("UPDATE notifications SET email_sent=1, email_sent_at=NOW() WHERE installment_id=? AND promise_id=? ORDER BY id DESC LIMIT 1")
                    ->execute([$row['installment_id'], $row['promise_id']]);
            }
        } catch (Throwable $e) {
            $errors[] = 'update: ' . $e->getMessage();
        }
    }

    ob_clean();
    echo json_encode([
        'success'  => true,
        'checked'  => count($upcoming),
        'created'  => $created,
        'emailed'  => $emailed,
        'errors'   => $errors,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ── Helpers ───────────────────────────────────────────────────────────────

function buildEmailBody(string $nom, string $label, float $amount, string $due_fmt, string $unit, string $pv): string {
    $amt_fmt = number_format($amount, 3, ',', ' ');
    return "
<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'></head><body style='font-family:Arial,sans-serif;background:#f4f6f8;padding:20px'>
<div style='max-width:560px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #dde3ea'>
  <div style='background:#145f7d;padding:20px 28px'>
    <h2 style='color:#fff;margin:0;font-size:18px'>Rappel de paiement</h2>
    <p style='color:#b0cfe0;margin:4px 0 0;font-size:13px'>Promed Immobilier</p>
  </div>
  <div style='padding:24px 28px'>
    <p style='margin:0 0 16px'>Bonjour <strong>" . htmlspecialchars($nom) . "</strong>,</p>
    <p>Nous vous rappelons qu'une échéance est due dans <strong>2 jours</strong> :</p>
    <table style='width:100%;border-collapse:collapse;margin:16px 0;font-size:14px'>
      <tr style='background:#f4f6f8'><td style='padding:10px 14px;font-weight:700'>Échéance</td><td style='padding:10px 14px'>" . htmlspecialchars($label) . "</td></tr>
      <tr><td style='padding:10px 14px;font-weight:700'>Montant</td><td style='padding:10px 14px;color:#145f7d;font-weight:700'>$amt_fmt TND</td></tr>
      <tr style='background:#f4f6f8'><td style='padding:10px 14px;font-weight:700'>Date limite</td><td style='padding:10px 14px;color:#c03030;font-weight:700'>$due_fmt</td></tr>
      <tr><td style='padding:10px 14px;font-weight:700'>Unité</td><td style='padding:10px 14px'>$unit</td></tr>
      <tr style='background:#f4f6f8'><td style='padding:10px 14px;font-weight:700'>N° Promesse</td><td style='padding:10px 14px'>$pv</td></tr>
    </table>
    <p style='color:#5e6878;font-size:12px'>Pour toute question, contactez votre conseiller Promed.</p>
  </div>
  <div style='background:#f4f6f8;padding:14px 28px;text-align:center;font-size:11px;color:#5e6878'>
    © " . date('Y') . " Promed Immobilier – Ce message est envoyé automatiquement.
  </div>
</div>
</body></html>";
}

function sendMail(string $to, string $name, string $subject, string $html, array &$errors): bool {
    // Utilise PHPMailer si disponible, sinon mail() natif
    $phpmailer_path = __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    if (file_exists($phpmailer_path)) {
        return sendWithPHPMailer($to, $name, $subject, $html, $errors);
    }
    return sendWithNativeMail($to, $name, $subject, $html, $errors);
}

function sendWithNativeMail(string $to, string $name, string $subject, string $html, array &$errors): bool {
    $from_email = 'noreply@promed.tn';
    $from_name  = 'Promed Immobilier';
    $headers    = implode("\r\n", [
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <$from_email>",
        "Reply-To: $from_email",
        "X-Mailer: PromedApp",
    ]);
    $encoded_subject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
    $result = @mail($to, $encoded_subject, $html, $headers);
    if (!$result) $errors[] = "mail() failed for $to";
    return $result;
}

function sendWithPHPMailer(string $to, string $name, string $subject, string $html, array &$errors): bool {
    try {
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : 'localhost';
        $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 25;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom('noreply@promed.tn', 'Promed Immobilier');
        $mail->addAddress($to, $name);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->send();
        return true;
    } catch (Throwable $e) {
        $errors[] = 'PHPMailer: ' . $e->getMessage();
        return false;
    }
}
