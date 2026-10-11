-- Keep the existing SMTP host, credentials, and transport; switch only the
-- visible sender and the clinic reply address for the university Brevo account.
UPDATE system_settings
SET setting_value = JSON_SET(
    setting_value,
    '$.from_email', 'plphealthservices@plpuhs.qd.je',
    '$.from_name', 'University Health Services',
    '$.reply_to_email', 'plpuhs2026@gmail.com'
)
WHERE setting_key = 'mail.smtp'
  AND JSON_VALID(setting_value);
