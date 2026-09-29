# Student portal development

Apply Ponytail's reuse-first approach to changes in `patient-portal/`:

1. Trace the affected patient flow before changing it.
2. Reuse the portal's existing includes, shared layouts, PHP helpers, CSS, and JavaScript before adding code.
3. Prefer PHP standard-library and browser-native features; do not add a dependency or a new abstraction unless the existing system cannot meet the requirement.
4. Make the smallest correct change and leave a focused runnable check for non-trivial logic.

These rules never reduce patient safety or project safeguards. Preserve input validation, CSRF protection, authorization, output escaping, privacy boundaries, accessibility, error handling, and the existing test coverage. Before editing any patient-portal file, explain the intended file paths, line ranges, and changes. After patient-portal edits, rebuild the `app` image, recreate the deployed `app` service, and verify the affected route.
