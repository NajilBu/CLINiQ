# Workspace Rules

## Communication Style
- **Pre-change Explanations**: Before making any file modifications, creations, deletions, or executing commands that modify files, always explain exactly what is going to be changed (including file paths, line ranges, and target content) to the user first.

## Deployment Verification
- **Student/patient UI changes**: Rebuild the `app` image and recreate the deployed `app` service after changes under `patient-portal/`; verify the affected page or route after deployment.
- **Docker changes**: Rebuild and recreate every service affected by a Compose file, Dockerfile, or Docker configuration change; verify container health and the affected service behavior.
- **Database changes**: Before each database-affecting stage, create and verify a backup. Apply migrations only through the project migration runner, then verify migration state, database integrity, and the affected application flow after deployment.
