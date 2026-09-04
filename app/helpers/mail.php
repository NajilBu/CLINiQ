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

    if (
        trim((string) $host) === ''
        || trim((string) $user) === ''
        || trim((string) $pass) === ''
        || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)
    ) {
        error_log('[CLINiQ Mail] Delivery skipped because the SMTP configuration is incomplete.');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $port;
        $mail->Timeout    = 10;
        $mail->Timelimit  = 10;

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

function cliniq_mail_template_interpolate(string $value, array $context): string
{
    $replacements = [];
    foreach ($context as $key => $replacement) {
        $replacements['{{' . $key . '}}'] = (string) $replacement;
    }
    return strtr($value, $replacements);
}

/** @return array{subject:string,html:string} */
function cliniq_notification_email(string $templateKey, array $context, string $actionUrl): array
{
    $template = cliniq_mail_template($templateKey);
    $rendered = [];
    foreach ($template as $field => $value) {
        $rendered[$field] = cliniq_mail_template_interpolate((string) $value, $context);
    }

    $clinic = htmlspecialchars((string) ($context['clinic_name'] ?? 'CLINiQ Clinic'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $heading = htmlspecialchars($rendered['heading'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $message = nl2br(htmlspecialchars($rendered['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    $button = htmlspecialchars($rendered['button_label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $footer = nl2br(htmlspecialchars($rendered['footer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    $url = htmlspecialchars($actionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f4f6f3;font-family:Arial,sans-serif;color:#17261d;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:28px 14px;"><tr><td align="center">
  <table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 22px rgba(0,0,0,.07);">
    <tr><td style="background:#1e6e4f;padding:22px 30px;color:#ffffff;">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr>
        <td style="padding-right:14px;vertical-align:middle;">{{CLINIQ_EMAIL_LOGO}}</td>
        <td style="vertical-align:middle;font-size:21px;font-weight:800;">{$clinic}</td>
      </tr></table>
    </td></tr>
    <tr><td style="padding:32px 30px;">
      <h1 style="margin:0 0 16px;font-size:23px;line-height:1.3;color:#17261d;">{$heading}</h1>
      <div style="font-size:15px;line-height:1.7;color:#374a3d;">{$message}</div>
      <table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px 0;"><tr><td style="background:#1e6e4f;border-radius:9px;">
        <a href="{$url}" target="_blank" style="display:inline-block;padding:13px 24px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;">{$button}</a>
      </td></tr></table>
      <div style="font-size:12px;line-height:1.6;color:#7a8c80;">{$footer}</div>
    </td></tr>
    <tr><td style="padding:17px 30px;background:#f4f6f3;color:#7a8c80;font-size:12px;text-align:center;">Automated message from {$clinic}</td></tr>
  </table>
</td></tr></table>
</body>
</html>
HTML;

    return ['subject' => $rendered['subject'], 'html' => $html];
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
    $key = $accountType === 'student' ? 'student_re_enrollment' : 'employee_re_employment';
    return cliniq_notification_email($key, [
        'patient_name' => $firstName,
        'clinic_name' => $clinicName,
    ], $loginUrl)['html'];
}
