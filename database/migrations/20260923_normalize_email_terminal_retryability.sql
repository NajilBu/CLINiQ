UPDATE email_queue
SET retryable = 0
WHERE status IN ('sent', 'blocked', 'cancelled');
