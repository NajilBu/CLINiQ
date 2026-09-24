-- Blocked records are required so missing or invalid patient recipients remain
-- visible and traceable in Email Center instead of failing during queue insert.
ALTER TABLE email_queue
  MODIFY status ENUM('pending','processing','sent','failed','blocked','cancelled') NOT NULL DEFAULT 'pending';
