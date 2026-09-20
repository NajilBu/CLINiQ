<?php
require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/AppointmentWorkflow.php';
require_login();
ensure_appointment_schema();

render_header('Clinic Availability');
render_clinic_command_header(
    'Scheduling',
    'Clinic Availability',
    'Manage working hours, future schedules, and unavailable appointment periods.',
    '<a href="' . e(app_url('appointments/index.php')) . '" class="btn btn-outline text-decoration-none"><span class="material-symbols-outlined">arrow_back</span> Back to Appointments</a>'
);
require __DIR__ . '/_availability_section.php';
render_footer();
