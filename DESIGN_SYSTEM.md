# CLINiQ Design System & UI Consistency Guide

Last reviewed: 2026-09-08.

Use this guide when building a page or correcting an inconsistent element.
It documents the shared implementation and identifies conventions to follow
during cleanup. It does not mean every existing page already conforms.

## 1. Design direction

CLINiQ uses a light clinic interface: neutral or softly tinted backgrounds,
white cards, restrained borders and shadows, compact readable text, rounded
controls, and a configurable accent color. Green is the default theme.
Strong red is reserved for danger, urgent alerts, and destructive actions.

The goal is consistent components within the appropriate page family. A staff
registry and a public student form need different layouts, but should share
typography, visual hierarchy, action meanings, and theme behavior.

## 2. Source of truth

Repository-relative paths below identify the implementation to reuse.

| Concern | Source |
| --- | --- |
| Shared CSS tokens and staff components | `public/assets/css/app.css` |
| Theme presets, custom color generation, active theme | `app/services/SystemSettings.php` — `active_cliniq_theme()` |
| Staff shell, page headings, tables, badges, flash messages | `app/helpers/view.php` |
| Public branding/header | `app/helpers/brand.php` — `render_cliniq_entry_header()` |
| Patient layouts and theme injection | `patient-portal/includes/patient-layout.php` |
| Patient component styles | `patient-portal/assets/css/patient.css` |
| Shared dialogs, toasts, and interactions | `public/assets/js/app.js` |
| Grid behavior | `public/assets/js/ag-grid-tables.js` |
| Locally bundled fonts, icons, Tailwind and grid assets | `public/assets/vendor/` |

For a new component, first look for an existing class or helper in these files.
For an inconsistency, inspect the computed style and winning CSS selector:
inline styles, page-level `<style>` blocks, specificity, and `!important` can
override shared definitions. Do not assume the first matching CSS rule wins.

## 3. Theme and colors

Read the active theme through `active_cliniq_theme()`; do not hardcode the default
green into a new reusable component. Staff and patient layouts already inject
the appropriate variables. Public standalone pages must supply theme variables
for whichever components they use.

| Role | Shared CSS variable | Green preset value / source |
| --- | --- | --- |
| Primary action and active accent | `--cliniq-primary` | `#3F7D52` |
| Primary hover / stronger accent | `--cliniq-primary-hover` | `#23422C` (`primary_container`) |
| Soft primary background | `--cliniq-primary-fixed` | `#e8f6ec` |
| Page surface | `--cliniq-surface` | `#f4fbf6` |
| Subtle panel surface | `--cliniq-surface-low` | `#edf8f0` |
| Theme outline | `--cliniq-outline` | `#c7dccd` |
| Accent background | `--cliniq-accent` | `#e4f4e8` from theme; base CSS fallback differs |
| Accent foreground | `--cliniq-accent-foreground` | `#23422C` |
| Focus tint | `--cliniq-focus-rgb` | `63, 125, 82` |
| Shadow tint | `--cliniq-shadow-rgb` | `35, 66, 44` |
| Neutral text, border, card | `--cliniq-foreground`, `--cliniq-border`, `--cliniq-card` | Defined in shared CSS |

Patient pages use the existing `--student-*` equivalents, populated by their
layout. Keep that mapping rather than introducing a third global token family.

Recommendation for cleanup: theme all decorative accents, borders, focus rings,
and soft surfaces consistently. Preserve semantic warning/success/error colors;
an amber warning should not become purple when the brand theme changes.

## 4. Typography and icons

Inter is the effective shared body **and heading** family. Some Tailwind configs
still specify Manrope for `headline`, but `app.css` overrides headings and
`.font-headline` to Inter. Local fonts are already bundled.

| Element | Existing shared baseline |
| --- | --- |
| Body | Inter, weight 400, normal letter spacing |
| `.font-bold` | Weight 600 via shared override |
| `.font-extrabold`, `.font-black` | Weight 700 via shared override |
| Staff page `h1` in `.app-content` | `clamp(1.45rem, 2vw, 1.75rem)`, weight 700, line-height 1.2 |
| Command-header `h1` | `.dashboard-hero h1` has a larger `clamp(1.9rem, 3vw, 2.45rem)` rule; in `.app-content`, the more-specific heading selector wins |
| Standard form text | `--cliniq-control-font-size: 0.8125rem` (13px at a 16px root), line-height 1.35 |
| Standard button text | 0.875rem, weight 600 |
| Small button text | 0.75rem |
| `.clinic-label` | 0.625rem, weight 600, uppercase, letter spacing 0.08em |

Use one `h1` per page, followed by section headings and brief supporting copy.
Reserve uppercase tracking for short labels and eyebrow text. Do not style long
instructions, errors, or paragraph text like tiny uppercase labels.

Use bundled Material Symbols Outlined through `.material-symbols-outlined`.
Reuse the icon for the same action across modules. Decorative icons should have
`aria-hidden="true"`; icon-only controls need an accessible name. Keep icons
aligned with labels using the shared button flex layout.

## 5. Page families and layout

| Family | Use | Scrolling and layout |
| --- | --- | --- |
| Staff/admin | `render_header()`, `render_clinic_command_header()`, `render_footer()` | Sidebar/topbar shell; `.app-content` owns vertical scrolling |
| Public entry | `public/index.php`, `public/visitor-registration.php`, shared brand helper | Centered task card, public branding and clear return link |
| Patient portal | `render_student_header()` / `render_student_footer()` | Patient navigation; whole-page scrolling |

The staff stylesheet sets `html, body` to `height: 100%; overflow: hidden`.
Patient CSS explicitly undoes this for `.student-body`. Do not include the staff
stylesheet in a long standalone public form without accounting for scrolling.

Existing patient widths are 72rem for `.student-main` and 52rem for
`.student-main-narrow`, with responsive side gutters. Staff `.app-content` has
1.25rem base padding; responsive and desktop-shell rules may override it.

Recommended spacing vocabulary for new work: 0.25rem, 0.5rem, 0.75rem, 1rem,
1.25rem, 1.5rem, and 2rem (the corresponding Tailwind spacing utilities).
These are a reuse convention, not a separate implemented spacing-token system.
Use consistent gaps between sibling controls and larger gaps between sections.

## 6. Reusable components

### Buttons and actions

| Purpose | Existing class combination |
| --- | --- |
| Main save, submit, or create action | `btn btn-primary` |
| Secondary action | `btn btn-outline` |
| Low-emphasis action | `btn btn-ghost` |
| Destructive action | `btn btn-danger` |
| Compact action | Add `btn-sm` |
| Icon-only action | `btn-icon` plus `btn-icon-primary`, `btn-icon-danger`, or `btn-icon-slate` |
| Existing circular cancel control | `btn-cancel-icon` with the appropriate button classes |

Base `.btn` uses 0.75rem × 1.25rem padding, a 0.625rem radius, and a 0.5rem
icon gap. `.btn-sm` uses 0.375rem × 0.75rem padding and a 0.5rem radius.
Command-header buttons have a deliberate larger minimum height/radius variant.

Use a link for navigation and a button for an action. Specify `type="button"`
for non-submit buttons inside forms. Prefer one primary action per action group.
Show meaningful busy text during submission, prevent double submission, and
make unavailable controls disabled with an explanation where needed.

### Forms

Reuse `.clinic-label`, `.clinic-input`, `.clinic-select`, `.clinic-textarea`,
and `.input-error` on staff forms. Standard controls have a 3rem minimum height,
0.75rem × 1rem padding, a 0.75rem radius, a subtle border, and a light background.
Textareas have a 7rem minimum height and resize vertically. Focus uses a 3px
theme-tinted ring; errors use a red border and ring.

Patient forms use their existing `.student-label` and `.student-input` family.
The visitor registration page's underlined inputs are a page-specific variant,
not the default for staff forms.

Always provide a visible label connected to its input, required indicators,
clear help text where necessary, and an understandable validation message.
Preserve valid answers after an error. Group radio buttons with `fieldset` and
`legend`; make the label clickable. Link error/help text with `aria-describedby`.
Do not rely on placeholders or border color alone to explain a field or error.

### Cards and sections

Reuse `.clinic-card`: white background, fine neutral border, 0.75rem radius,
and a low-contrast shadow. Padding is supplied by the surrounding template or
utilities; the base card class does not add it.

Dashboard cards deliberately use a 1rem radius and slightly different shadow.
Patient `.student-card` uses a 0.85rem radius; `.student-card-pad` adds 1.25rem
padding. These are existing page-family variants, not values to mix randomly.

Use borders and whitespace to separate sections. Avoid multiple nested card
shadows or prominent colored panels for ordinary content.

### Tables, lists, filters, and empty states

Use `render_ag_grid()` for staff registries that need the shared sortable grid
experience. It supplies the row-number column and standard theme wiring. Its
default row height is 70px; the minimum configured height is 40px. Default page
size is 25, but pagination must be enabled explicitly in the options.

Reuse `row_actions_button()` for the established row-action menu and
`.search-input-wrap` / `.search-input` for search. Reuse `.status-tab` for an
existing status-tab pattern. Use `.pagination` with its active/disabled classes
where custom pagination is needed. Never restyle vendor CSS directly.

A small aggregate report can use a semantic HTML table rather than a grid.
Keep headers readable, numeric formatting consistent, and wide tables inside
a horizontally scrollable wrapper. Do not let a wide table scroll the whole page.

Use `.empty-state`, `.empty-state-title`, and `.empty-state-text` for empty staff
lists, or the grid's `emptyTitle` / `emptyText` options. Distinguish no data,
no filter matches, loading, and load failure; offer the relevant next action.

### Status badges

Use `.badge` plus the appropriate semantic variant, retaining its text label.

| Meaning | Shared variants |
| --- | --- |
| Waiting / pending | `badge-pending` (amber) |
| Active / in progress | `badge-active`, `badge-in-progress` (theme-neutral) |
| Completed / resolved / low risk | `badge-completed`, `badge-resolved`, `badge-low` (green) |
| Cancelled | `badge-cancelled` (slate) |
| Moderate risk | `badge-moderate` (amber) |
| High risk / critical | `badge-high`, `badge-critical` (rose/red) |

Prefer domain helpers such as `risk_badge_class()`, `status_badge_class()`, or
`visit_status_badge()` rather than duplicating mappings. Existing visit-specific
mapping assigns Cancelled to `badge-critical`, unlike the generic cancelled
badge. Treat that as a semantic review item, not permission to silently change
visit behavior during an unrelated visual fix.

### Dialogs and messages

Shared staff dialogs use `.modal-backdrop`, `.modal-content`, `showModal(id)`,
and `closeModal(id)`. The shared implementation supports backdrop/Escape closing
and scroll locking. Current final CSS uses a 240ms opacity fade and disables
that transition for reduced motion; earlier scale-animation rules are overridden.

Use `flash_message()` for server feedback and `showToast()` for short client
notifications when those helpers are loaded. Keep validation guidance beside
the affected field or form; a disappearing toast should not be its only location.

Accessibility requirements for new or repaired dialogs: a labelled dialog role,
keyboard focus entering the dialog, staying inside while modal, and returning to
the trigger on close. These are review requirements; the generic modal functions
alone do not implement the complete focus-management behavior.

## 7. Known variations and cleanup priorities

These are source-level observations, not a completed visual audit of all screens.

| Existing variation | How to handle it |
| --- | --- |
| Manrope in some Tailwind configs, Inter in shared overrides | Follow effective Inter styling; reconcile configs in a targeted cleanup |
| Older blue/slate utility names remapped by `app.css` | Use semantic theme variables for new reusable CSS; inspect computed colors before changing legacy classes |
| Staff, dashboard, patient, and public card radii differ | Compare within the same page family before calling a difference a defect |
| `visit-input` underlined public form controls | Preserve as a scoped variant until a public-form standardization is requested |
| Feedback public survey and admin report | Reuse shared buttons, cards, inputs, badges, empty states, and admin pagination. `feedback.css` retains scoped layout, rating controls, and the small aggregate table; long student-facing labels intentionally remain sentence case |
| Feedback theme mapping | Uses the shared `--cliniq-*` mapping for surfaces, borders, rating states, and focus. The public page uses the entry-header helper and a scoped whole-page scrolling override |
| Inline/page-specific CSS | Keep only layout or domain-specific behavior locally; consolidate reusable appearance into the appropriate shared stylesheet |
| Tiny uppercase labels and highly weighted badges | Existing styles; verify readability and contrast before propagating them to long text or mobile controls |
| Electron shell rules | Preserve `body.is-electron-runtime` layout overrides; browser-only checks do not prove desktop parity |

Do not solve drift by adding another broad `!important` override at the end of
the stylesheet. Locate the conflicting rule, choose the shared component or
intentional scoped variant, and remove redundant local styling when safe.

## 8. Implementation example

For an authenticated staff page, use the shared shell and controls:

```php
require_once __DIR__ . '/../../app/helpers/view.php';
require_login();
render_header('Page title');
render_clinic_command_header('Module', 'Page title', 'Short explanation.');
```

```html
<section class="clinic-card p-5">
    <label class="clinic-label" for="example-field">Field label</label>
    <input id="example-field" name="example_field" class="clinic-input"
           aria-describedby="example-help" required>
    <p id="example-help" class="text-sm text-secondary mt-2">Helpful guidance.</p>
    <div class="flex flex-wrap items-center gap-3 mt-5">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <button type="button" class="btn btn-outline">Cancel</button>
    </div>
</section>
```

Place the controls in the page's real form, wire Cancel to the intended behavior,
escape dynamic content with `e()`, and finish with `render_footer()`. The snippet
illustrates appearance; it does not supply validation, CSRF protection, or saving.

## 9. Consistency review checklist

1. Identify the page family and the equivalent existing component.
2. Reuse its helper/classes before writing new appearance rules.
3. Check font, weight, control height, radius, spacing, icon alignment, and borders.
4. Check the default and another configured theme, including hover and focus.
5. Exercise empty, loading, validation error, success, and disabled states.
6. Check keyboard access, visible focus, labels, and reduced-motion behavior.
7. Check a narrow phone viewport, tablet, desktop, and short-height window.
   Confirm content remains reachable and page/table/modal scrolling is correct.
8. For shared changes, inspect a representative staff list, staff form, public
   form, and patient page; include Electron when shell rules are affected.
9. Update this guide when a shared convention changes. Describe unresolved
   exceptions honestly rather than documenting planned behavior as implemented.

This document establishes a reference for future consistency work. Creating it
does not change the UI, theme configuration, or existing workflows.
