# Enrollment surge runbook

## Before enrollment

1. Confirm `docker compose --profile public-portal ps` shows app and database healthy.
2. Run `docker compose exec app php /var/www/html/scripts/reliability/enrollment_readiness.php`.
3. Run the maintenance restore test: `docker compose --profile maintenance run --rm restore-test`.
4. Confirm the latest backup state is `success`; do not treat `warning` as disaster-recovery ready.
5. Run the k6 test using the disposable Docker profile against staging:
   `LOAD_TEST_BASE_URL=https://staging.example.edu docker compose --profile loadtest run --rm loadtest`.
   The default target is the local `app` container when `LOAD_TEST_BASE_URL` is omitted. The harness refuses the production domain unless `LOAD_TEST_ALLOW_PRODUCTION=1` is explicitly set.
6. Record baseline p95/p99 latency, error rate, database connections, CPU, memory, and free disk space.

## During enrollment

- Watch `docker stats`, gateway logs, Apache errors, and MariaDB connection/thread counts.
- Keep document uploads enabled only while the document volume has free space.
- If retries or abuse cause login pressure, use the existing authentication throttling and temporarily reduce the registration request rate at the gateway.
- Do not restart or recreate the database container during the event unless the operator has confirmed a recovery point.

## Rollback and incident response

1. Preserve logs and the current backup status before changing containers.
2. Stop only the public gateway/tunnel if the application must be taken private.
3. Redeploy the last known-good application image without removing Docker volumes.
4. Re-run the local health endpoint and the student login smoke test.
5. If data integrity is in doubt, stop writes and run the isolated restore test before resuming enrollment.

## After enrollment

- Run the document audit and backup verification.
- Confirm no backup warning remains.
- Review slow queries, failed requests, registration throttles, and disk growth.
- Archive the load-test metrics with the deployment record.
- The JSON summary is written to the `cliniq_load_test_results` Docker volume.
