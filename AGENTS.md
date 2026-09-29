# Workspace Rules

## Communication Style
- **Pre-change Explanations**: Before making any file modifications, creations, deletions, or executing commands that modify files, always explain exactly what is going to be changed (including file paths, line ranges, and target content) to the user first.

## Deployment Verification
- **Student/patient UI changes**: Rebuild the `app` image and recreate the deployed `app` service after changes under `patient-portal/`; verify the affected page or route after deployment.
- **Docker changes**: Rebuild and recreate every service affected by a Compose file, Dockerfile, or Docker configuration change; verify container health and the affected service behavior.
- **Database changes**: Before each database-affecting stage, create and verify a backup. Apply migrations only through the project migration runner, then verify migration state, database integrity, and the affected application flow after deployment.

## Ponytail
- Before adding code, trace the affected flow and use the first applicable option: do not build speculative work; reuse existing project code; use the standard library; use native platform features; use installed dependencies; then write the smallest correct implementation.
- For bug fixes, inspect every caller and fix the shared root cause rather than patching one symptom.
- Avoid new dependencies, unrequested abstractions, configuration for fixed values, and scaffolding for hypothetical future use.
- Never reduce validation at trust boundaries, authorization, privacy, security, accessibility, data-loss protection, error handling, deployment verification, or any explicitly requested behavior.
- Leave a focused runnable check for non-trivial logic. Match or extend the repository's existing test coverage when the affected workflow already has tests.
- Mark an intentional simplification with a known ceiling using a `ponytail:` comment that states the limitation and upgrade path.
