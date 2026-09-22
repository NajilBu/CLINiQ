<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/patient-layout.php';

render_student_header('Help & FAQs', 'help');
?>

<section class="student-page-header patient-help-header" aria-labelledby="patient-help-title">
    <div>
        <p class="student-eyebrow">Patient Health Portal</p>
        <h1 id="patient-help-title" class="student-title">Help &amp; FAQs</h1>
        <p class="student-subtitle">Find clear answers about using your clinic portal and completing your next steps.</p>
    </div>
    <span class="patient-help-header-icon material-symbols-outlined" aria-hidden="true">help</span>
</section>

<div class="patient-help-grid" aria-label="Frequently asked questions">
    <section class="student-card patient-help-category" aria-labelledby="help-access-title">
        <div class="student-card-header">
            <div>
                <p class="student-eyebrow">Access</p>
                <h2 id="help-access-title" class="student-card-title">Getting started and access</h2>
            </div>
            <span class="patient-help-category-icon material-symbols-outlined" aria-hidden="true">login</span>
        </div>
        <div class="patient-help-accordion-list">
            <details class="patient-help-accordion"><summary>How do I create my portal account?</summary><div class="patient-help-answer">Start from the student registration page, verify your identity and email, then complete the required profile details.<a href="patient-register.php">Open student registration <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a></div></details>
            <details class="patient-help-accordion"><summary>What is the difference between Applicant and Official access?</summary><div class="patient-help-answer">Applicant access lets you complete required APE steps. Appointment booking and the Health Passport become available after the clinic marks your account as Official.</div></details>
            <details class="patient-help-accordion"><summary>What if I cannot sign in or forgot my password?</summary><div class="patient-help-answer">Use the password recovery page to request a reset link for your portal account.<a href="patient-forgot-password.php">Recover portal access <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a></div></details>
        </div>
    </section>

    <section class="student-card patient-help-category" aria-labelledby="help-ape-title">
        <div class="student-card-header">
            <div>
                <p class="student-eyebrow">Annual Physical Examination</p>
                <h2 id="help-ape-title" class="student-card-title">APE requirements</h2>
            </div>
            <span class="patient-help-category-icon material-symbols-outlined" aria-hidden="true">fact_check</span>
        </div>
        <div class="patient-help-accordion-list">
            <details class="patient-help-accordion"><summary>How do I check my APE progress?</summary><div class="patient-help-answer">Your APE Status page shows your current stage, document requirements, clinic instructions, and the next action for your record.<a href="patient-ape-status.php">Open APE Status <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a></div></details>
            <details class="patient-help-accordion"><summary>How do I submit required APE documents?</summary><div class="patient-help-answer">Open APE Status and upload each required document using the instructions shown for your assigned batch. The clinic reviews submitted files before moving your record forward.<a href="patient-ape-status.php">Review required documents <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a></div></details>
            <details class="patient-help-accordion"><summary>What happens after the clinic reviews my APE?</summary><div class="patient-help-answer">The clinic may schedule your examination, request follow-up, or record your clearance. Check your APE Status and notification bell for updates.</div></details>
        </div>
    </section>

    <section class="student-card patient-help-category" aria-labelledby="help-appointments-title">
        <div class="student-card-header">
            <div>
                <p class="student-eyebrow">Clinic visits</p>
                <h2 id="help-appointments-title" class="student-card-title">Appointments</h2>
            </div>
            <span class="patient-help-category-icon material-symbols-outlined" aria-hidden="true">event_available</span>
        </div>
        <div class="patient-help-accordion-list">
            <details class="patient-help-accordion"><summary>When can I request an appointment?</summary><div class="patient-help-answer">Appointment booking is available to Official accounts. If you have Applicant access, complete your APE requirements or contact the clinic for assistance.</div></details>
            <details class="patient-help-accordion"><summary>How do I request or check a clinic visit?</summary><div class="patient-help-answer">Use the Appointments page to choose an available schedule and review your recent appointment requests and their status.<a href="patient-appointment.php">Open Appointments <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a></div></details>
            <details class="patient-help-accordion"><summary>Can I change or cancel an appointment?</summary><div class="patient-help-answer">Open the Appointments page and use the available actions on your request. If there is no action available, contact or visit the clinic.</div></details>
        </div>
    </section>

    <section class="student-card patient-help-category" aria-labelledby="help-passport-title">
        <div class="student-card-header">
            <div>
                <p class="student-eyebrow">Emergency information</p>
                <h2 id="help-passport-title" class="student-card-title">Health Passport</h2>
            </div>
            <span class="patient-help-category-icon material-symbols-outlined" aria-hidden="true">id_card</span>
        </div>
        <div class="patient-help-accordion-list">
            <details class="patient-help-accordion"><summary>Where can I update my emergency information?</summary><div class="patient-help-answer">Use the Health Passport to review and update your personal, medical, and emergency contact information.<a href="patient-passport.php">Open Health Passport <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a></div></details>
            <details class="patient-help-accordion"><summary>What are the QR and NFC options for?</summary><div class="patient-help-answer">The Health Passport provides QR and NFC access options for the emergency passport view. Review the sharing settings before using them.</div></details>
            <details class="patient-help-accordion"><summary>What information is visible in an emergency passport?</summary><div class="patient-help-answer">The emergency passport is designed to present the health and contact details you allow for emergency access. Use the preview and visibility settings in your Health Passport to review it.</div></details>
        </div>
    </section>

    <section class="student-card patient-help-category patient-help-category-wide" aria-labelledby="help-support-title">
        <div class="student-card-header">
            <div>
                <p class="student-eyebrow">Updates and assistance</p>
                <h2 id="help-support-title" class="student-card-title">Notifications and clinic support</h2>
            </div>
            <span class="patient-help-category-icon material-symbols-outlined" aria-hidden="true">notifications</span>
        </div>
        <div class="patient-help-accordion-list">
            <details class="patient-help-accordion"><summary>Where do I find clinic updates?</summary><div class="patient-help-answer">Use the notification bell in the page header to review updates about your APE and appointment requests. It is the portal’s notification center.</div></details>
            <details class="patient-help-accordion"><summary>What are Clinic Notes?</summary><div class="patient-help-answer">Clinic Notes on your dashboard are messages from the clinic based on your APE review. Read them together with the next action shown in your APE Status.</div></details>
            <details class="patient-help-accordion"><summary>When should I contact or visit the clinic?</summary><div class="patient-help-answer">Contact or visit the clinic if you need help with APE results, appointment changes, access status, or information that needs staff review.</div></details>
        </div>
    </section>
</div>

<aside class="student-note student-note-info patient-help-contact" aria-label="Clinic support">
    <span class="material-symbols-outlined" aria-hidden="true">support_agent</span>
    <div><strong>Need more help?</strong><br>Contact or visit the clinic for concerns that need staff review.</div>
</aside>

<?php render_student_footer(); ?>
