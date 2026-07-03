# PLP ClinicConnect Reusable Design System

This project already has a strong clinical-console style. The reusable system keeps that style intact while making it easier to build the full clinic information management system beyond the logbook.

## Core Style

- **Brand feel:** clean school clinic console, trustworthy, bright, soft, and operational.
- **Primary color:** PLP blue `#00478d`, with deep ink headings `#1c2a59`.
- **Surface:** almost-white clinical background `#f8f9fa`, white cards, faint blue-gray borders.
- **Typography:** Manrope for page titles and important numbers; Inter for body, labels, tables, and controls.
- **Shape:** large soft cards around `2rem`, smaller controls around `1rem`, icon tiles around `0.875rem` to `1rem`.
- **Interaction:** subtle lift on cards, blue focus rings, compact toasts, blurred modals.
- **Content rhythm:** uppercase micro-labels, big readable metric numbers, dense but calm tables.

## Shared Assets

Use these files on new screens:

```html
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<script src="assets/clinic-tailwind.config.js"></script>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link rel="stylesheet" href="assets/clinic-design-system.css">
```

Before `</body>`:

```html
<script src="assets/clinic-ui.js"></script>
```

## Reusable Page Skeleton

```html
<body class="cc-app">
    <header class="cc-topbar">
        <a class="cc-brand" href="screen5-staff-dashboard.html">
            <span class="cc-brand-mark"><span class="material-symbols-outlined text-[18px]">clinical_notes</span></span>
            <span>PLP Clinic<span class="cc-brand-accent">Connect</span></span>
        </a>
        <nav class="cc-nav">
            <a class="cc-nav-link is-active" href="#">Dashboard</a>
            <a class="cc-nav-link" href="#">Patients</a>
            <a class="cc-nav-link" href="#">Inventory</a>
        </nav>
    </header>
    <main class="cc-main custom-scrollbar">
        <section class="cc-page cc-page-stack">
            <div class="cc-page-header">
                <div>
                    <h1 class="cc-title">Page Title</h1>
                    <p class="cc-subtitle">Short operational context</p>
                </div>
                <button class="cc-button cc-button-primary">
                    <span class="material-symbols-outlined">add</span>
                    New Record
                </button>
            </div>
        </section>
    </main>
</body>
```

## Component Names

- `cc-topbar`, `cc-brand`, `cc-nav`, `cc-nav-link`
- `cc-main`, `cc-page`, `cc-page-stack`, `cc-page-header`
- `cc-card`, `cc-card-pad`, `cc-card-hover`
- `cc-stat-grid`, `cc-stat-card`, `cc-stat-value`
- `cc-button`, `cc-button-primary`, `cc-button-secondary`, `cc-button-ghost`
- `cc-tabs`, `cc-tab`, `cc-tab-panel`
- `cc-table-wrap`, `cc-table`
- `cc-field`, `cc-input`, `cc-select`, `cc-textarea`
- `cc-badge`, `cc-badge-success`, `cc-badge-warning`, `cc-badge-danger`, `cc-badge-neutral`
- `cc-modal-backdrop`, `cc-modal`
- `cc-toast-container`, `cc-toast`

## Suggested Full Clinic Modules

- **Clinic Dashboard:** daily queue, active visits, follow-ups, alerts, quick actions.
- **Patient Registry:** student, faculty, staff, and guest master profiles.
- **Visit Logbook:** arrivals, triage, assessment, disposition, visit history.
- **Clinical Records:** vitals, complaints, diagnosis notes, treatment, referrals, attachments.
- **Appointments & Follow-ups:** scheduled checks, clearances, return visits.
- **Inventory & Equipment:** medicines, supplies, assets, equipment lending, low-stock alerts.
- **Reports & Analytics:** visit summaries, common complaints, inventory movement, export history.
- **Personnel & Access:** nurses, physicians, role permissions, audit trails.
- **Maintenance:** school year, departments, courses, dynamic lookup lists, backup settings.

The new `clinic-information-management.html` file demonstrates those modules using the shared design system.
