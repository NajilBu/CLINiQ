<?php

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../services/SystemSettings.php';
require_once __DIR__ . '/../lib/phpmailer/src/Exception.php';
require_once __DIR__ . '/../lib/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

const CLINIQ_EMAIL_LOGO_CID = 'cliniq-clinic-logo';
const CLINIQ_EMAIL_LOGO_PLACEHOLDER = '{{CLINIQ_EMAIL_LOGO}}';

function cliniq_email_logo_path(): ?string
{
    $profile = clinic_profile_settings();
    $relativePath = ltrim(str_replace('\\', '/', clinic_profile_logo_path($profile)), '/');
    $publicRoot = realpath(dirname(__DIR__, 2) . '/public');
    if ($publicRoot === false) {
        return null;
    }

    $logoPath = realpath($publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if ($logoPath === false || !is_file($logoPath)) {
        return null;
    }

    $normalizedRoot = rtrim(str_replace('\\', '/', $publicRoot), '/') . '/';
    $normalizedLogo = str_replace('\\', '/', $logoPath);
    if (!str_starts_with($normalizedLogo, $normalizedRoot)) {
        return null;
    }

    return $logoPath;
}

function cliniq_email_logo_mime_type(string $logoPath): ?string
{
    return match (strtolower((string) pathinfo($logoPath, PATHINFO_EXTENSION))) {
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        default => null,
    };
}

/**
 * Send an HTML email via SMTP.
 * Config is read from the DB (mail_settings()) first; falls back to .env values.
 */
function send_cliniq_email(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    // Prefer DB-stored settings; fall back to .env.
    $dbSettings  = mail_settings_configured() ? mail_settings() : [];
    $host        = $dbSettings['host']       ?? env_value('MAIL_HOST', 'smtp.gmail.com');
    $port        = (int) ($dbSettings['port']      ?? env_value('MAIL_PORT', '587'));
    $encryption  = strtolower((string) ($dbSettings['encryption'] ?? env_value('MAIL_ENCRYPTION', 'tls')));
    $user        = $dbSettings['username']   ?? env_value('MAIL_USER', '');
    $pass        = $dbSettings['password']   ?? env_value('MAIL_PASS', '');
    $fromEmail   = $dbSettings['from_email'] ?? env_value('MAIL_FROM', $user);
    $fromName    = $dbSettings['from_name']  ?? env_value('MAIL_FROM_NAME', 'CLINiQ Clinic');

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $port;

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName);

        $logoMarkup = '';
        $logoPath = cliniq_email_logo_path();
        $logoMimeType = $logoPath !== null ? cliniq_email_logo_mime_type($logoPath) : null;
        if ($logoPath !== null && $logoMimeType !== null) {
            $mail->addEmbeddedImage(
                $logoPath,
                CLINIQ_EMAIL_LOGO_CID,
                basename($logoPath),
                PHPMailer::ENCODING_BASE64,
                $logoMimeType
            );
            $logoMarkup = '<img src="cid:' . CLINIQ_EMAIL_LOGO_CID . '" alt="Clinic logo" width="56" height="56" style="display:block;width:56px;height:56px;object-fit:contain;border-radius:12px;background:#ffffff;">';
        }
        $htmlBody = str_replace(CLINIQ_EMAIL_LOGO_PLACEHOLDER, $logoMarkup, $htmlBody);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(
            str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $htmlBody)
        );

        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('[CLINiQ Mail] Failed to send to ' . $toEmail . ': ' . $mail->ErrorInfo);
        return false;
    }
}

function cliniq_custom_email_body(string $message, string $clinicName): string
{
    $clinicSafe = htmlspecialchars($clinicName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $messageSafe = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f4f6f3;font-family:Arial,sans-serif;color:#17261d;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:28px 14px;"><tr><td align="center">
  <table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;">
    <tr><td style="background:#1e6e4f;padding:20px 30px;color:#ffffff;">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr>
        <td style="padding-right:14px;vertical-align:middle;">{{CLINIQ_EMAIL_LOGO}}</td>
        <td style="vertical-align:middle;font-size:21px;font-weight:800;">{$clinicSafe}</td>
      </tr></table>
    </td></tr>
    <tr><td style="padding:30px;font-size:15px;line-height:1.7;color:#374a3d;">{$messageSafe}</td></tr>
    <tr><td style="padding:18px 30px;background:#f4f6f3;color:#7a8c80;font-size:12px;text-align:center;">Sent by {$clinicSafe}</td></tr>
  </table>
</td></tr></table>
</body>
</html>
HTML;
}

/**
 * Build the re-enrollment / re-employment HTML email body.
 *
 * @param string $firstName   Patient first name
 * @param string $accountType 'student' | 'faculty' | 'school_personnel'
 * @param string $clinicName  Clinic display name for the email header
 * @param string $loginUrl    Full URL to the patient portal login page
 */
function cliniq_re_enrollment_email_body(
    string $firstName,
    string $accountType,
    string $clinicName,
    string $loginUrl
): string {
    $isStudent    = $accountType === 'student';
    $actionVerb   = $isStudent ? 'enrolled' : 'employed';
    $confirmLabel = $isStudent ? 'Confirm Enrollment' : 'Confirm Employment';
    $clinicSafe   = htmlspecialchars($clinicName, ENT_QUOTES);
    $firstSafe    = htmlspecialchars($firstName, ENT_QUOTES);
    $loginSafe    = htmlspecialchars($loginUrl, ENT_QUOTES);
    $message      = $isStudent
        ? "A new school year has started at {$clinicSafe}. To continue accessing your health records and clinic services, please log in and confirm that you are still enrolled."
        : "A new school year has started at {$clinicSafe}. To continue accessing your health records and clinic services, please log in and confirm that you are still employed.";

    $primaryColor = '#1e6e4f';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>{$confirmLabel} — {$clinicSafe}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f3;font-family:'Helvetica Neue',Arial,sans-serif;color:#1a2e22;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
      <tr><td style="background:{$primaryColor};padding:28px 36px;">
        <table role="presentation" cellpadding="0" cellspacing="0"><tr>
          <td style="padding-right:16px;vertical-align:middle;">{{CLINIQ_EMAIL_LOGO}}</td>
          <td style="vertical-align:middle;">
            <p style="margin:0;font-size:13px;font-weight:700;letter-spacing:.08em;color:rgba(255,255,255,.7);text-transform:uppercase;">Patient Health Portal</p>
            <h1 style="margin:6px 0 0;font-size:24px;font-weight:800;color:#fff;">{$clinicSafe}</h1>
          </td>
        </tr></table>
      </td></tr>
      <tr><td style="padding:36px;">
        <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#6b7c73;text-transform:uppercase;letter-spacing:.06em;">New School Year</p>
        <h2 style="margin:0 0 20px;font-size:22px;font-weight:800;color:#17261d;">{$confirmLabel}, {$firstSafe}</h2>
        <p style="margin:0 0 28px;font-size:15px;line-height:1.7;color:#374a3d;">{$message}</p>
        <table cellpadding="0" cellspacing="0" style="margin:0 0 28px;">
          <tr><td style="background:{$primaryColor};border-radius:10px;">
            <a href="{$loginSafe}" target="_blank"
               style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:700;color:#fff;text-decoration:none;">
              {$confirmLabel} &rarr;
            </a>
          </td></tr>
        </table>
        <p style="margin:0;font-size:13px;color:#8a9e90;line-height:1.6;">
          If you are no longer {$actionVerb}, simply ignore this email — your account will remain inactive.<br>
          If you have questions, contact the clinic directly.
        </p>
      </td></tr>
      <tr><td style="background:#f4f6f3;padding:20px 36px;border-top:1px solid #e2ebe5;">
        <p style="margin:0;font-size:12px;color:#8a9e90;text-align:center;">
          This is an automated message from {$clinicSafe}. Please do not reply to this email.
        </p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}
