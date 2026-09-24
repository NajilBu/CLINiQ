<?php

require_once __DIR__ . '/SystemSettings.php';
require_once __DIR__ . '/AuditLog.php';

function ape_workflow_steps(): array
{
    return [
        'Digital Keeping',
        'Examination',
        'Final Decision or Follow-up',
        'Completed',
    ];
}

/**
 * Return read-only data-quality flags for staff review. These flags never
 * change workflow state or repair live rows automatically.
 */
function ape_data_quality_flags(array $record, ?array $requirements = null, ?array $documents = null): array
{
    $apeId = (int) ($record['ape_id'] ?? 0);
    if ($apeId < 1) return [];
    $requirements ??= ape_requirements_for_record($apeId);
    $documents ??= ape_documents_for_record($apeId);

    $latestByType = [];
    foreach ($documents as $document) {
        $type = trim((string) ($document['document_type'] ?? ''));
        if ($type !== '' && !isset($latestByType[$type])) $latestByType[$type] = $document;
    }

    $flags = [];
    $add = static function (string $code, string $severity, string $title, string $detail, array $context = []) use (&$flags): void {
        $flags[] = ['code' => $code, 'severity' => $severity, 'title' => $title, 'detail' => $detail] + $context;
    };

    foreach ($requirements as $requirement) {
        $name = trim((string) ($requirement['requirement_name'] ?? '')) ?: 'Unnamed requirement';
        $group = trim((string) ($requirement['upload_group'] ?? ''));
        $status = trim((string) ($requirement['status'] ?? ''));
        $latest = $latestByType[$name] ?? null;

        if ($group === '') {
            $add('missing_upload_group', 'warning', 'Requirement group is not assigned', "{$name} has no explicit Initial or Follow-up group; the current resolver treats it as Initial.", ['requirement_name' => $name, 'requirement_id' => (int) ($requirement['requirement_id'] ?? 0)]);
        }
        if ($status === 'Verified' && (!$latest || (string) ($latest['verification_status'] ?? '') !== 'Verified')) {
            $add('verified_without_archived_file', 'high', 'Verified requirement has no archived file', "{$name} is marked Verified, but its latest stored file is missing or not archived. Clinic review is required.", ['requirement_name' => $name, 'requirement_id' => (int) ($requirement['requirement_id'] ?? 0)]);
        }
        if ($status === 'Submitted' && $latest && (string) ($latest['verification_status'] ?? '') === 'Pending') {
            $add('submitted_pending_review', 'info', 'Submitted file awaiting clinic review', "{$name} has a current file waiting for archive review.", ['requirement_name' => $name, 'requirement_id' => (int) ($requirement['requirement_id'] ?? 0)]);
        }
        if (($record['workflow_status'] ?? '') === 'Cleared' && $group === 'follow_up' && $status !== 'Verified') {
            $add('follow_up_on_cleared_record', 'high', 'Active Follow-up requirement on a cleared record', "{$name} is still active even though this APE record is cleared.", ['requirement_name' => $name, 'requirement_id' => (int) ($requirement['requirement_id'] ?? 0)]);
        }
    }

    return $flags;
}

/** Normalize and validate clinic-recorded examination information. */
function ape_validate_clinical_information(array $input, array $current = []): array
{
    $number = static function ($value, string $label, float $min, float $max): ?float {
        if ($value === null || trim((string) $value) === '') return null;
        if (!is_numeric($value)) throw new InvalidArgumentException($label . ' must be a valid number.');
        $number = (float) $value;
        if ($number < $min || $number > $max) {
            throw new InvalidArgumentException(sprintf('%s must be between %s and %s.', $label, $min, $max));
        }
        return $number;
    };
    $height = $number($input['patient_height_cm'] ?? null, 'Height', 30, 250);
    $weight = $number($input['patient_weight_kg'] ?? null, 'Weight', 1, 400);
    $temperature = $number($input['patient_temperature'] ?? null, 'Temperature', 25, 45);
    $pulse = $number($input['patient_pulse_rate'] ?? null, 'Pulse rate', 20, 250);
    $bloodPressure = trim((string) ($input['patient_blood_pressure'] ?? ''));
    if ($bloodPressure !== '') {
        if (!preg_match('/^\d{2,3}\s*\/\s*\d{2,3}$/', $bloodPressure)) {
            throw new InvalidArgumentException('Blood pressure must use the format 120/80.');
        }
        $bloodPressure = preg_replace('/\s*\/\s*/', '/', $bloodPressure);
        [$systolic, $diastolic] = array_map('intval', explode('/', $bloodPressure));
        if ($systolic < 40 || $systolic > 300 || $diastolic < 20 || $diastolic > 200 || $systolic <= $diastolic) {
            throw new InvalidArgumentException('Enter a reasonable blood pressure reading, such as 120/80.');
        }
    } else {
        $bloodPressure = null;
    }
    $bloodType = trim((string) (array_key_exists('blood_type', $input) ? $input['blood_type'] : ($current['blood_type'] ?? '')));
    if ($bloodType !== '' && !in_array($bloodType, dropdown_options('blood_type'), true)) {
        throw new InvalidArgumentException('Select a valid blood type.');
    }
    $text = static function ($value, string $label): ?string {
        if (is_array($value)) {
            $value = array_map(static fn ($item): string => trim((string) $item), $value);
            $value = array_values(array_filter($value, static fn (string $item): bool => $item !== ''));
            $value = implode("\n", $value);
        }
        $value = trim((string) ($value ?? ''));
        if ($value !== '' && function_exists('mb_strlen') && mb_strlen($value) > 5000) {
            throw new InvalidArgumentException($label . ' must be 5000 characters or fewer.');
        }
        return $value === '' ? null : $value;
    };
    $bmi = ($height !== null && $weight !== null) ? round($weight / (($height / 100) ** 2), 2) : null;
    $values = [
        'patient_height_cm' => $height === null ? null : round($height, 2),
        'patient_weight_kg' => $weight === null ? null : round($weight, 2),
        'patient_bmi' => $bmi,
        'patient_temperature' => $temperature === null ? null : round($temperature, 1),
        'patient_blood_pressure' => $bloodPressure,
        'patient_pulse_rate' => $pulse === null ? null : (int) round($pulse),
        'blood_type' => $bloodType === '' ? null : $bloodType,
        'existing_conditions' => $text(array_key_exists('existing_conditions', $input) ? $input['existing_conditions'] : ($current['existing_conditions'] ?? null), 'Existing medical conditions'),
        'medications' => $text(array_key_exists('medications', $input) ? $input['medications'] : ($current['medications'] ?? null), 'Current medications'),
    ];
    $values['changed_fields'] = [];
    foreach ($values as $field => $value) {
        if ($field === 'changed_fields') continue;
        $old = $current[$field] ?? null;
        if ((string) ($old ?? '') !== (string) ($value ?? '')) $values['changed_fields'][] = $field;
    }
    return $values;
}

function ape_work_queues(): array
{
    return [
        'digital_submission' => [
            'title' => 'Digital Keeping',
            'short_title' => 'Digital Keeping',
            'description' => 'Patients may upload required documents before examination and complete regular uploads within seven days afterward.',
            'icon' => 'upload_file',
        ],
        'examination' => [
            'title' => 'Examination',
            'short_title' => 'Examination',
            'description' => 'After the assigned schedule starts, authorized clinic staff can examine the patient even when the schedule was missed or digital documents are incomplete.',
            'icon' => 'stethoscope',
        ],
        'final_decision' => [
            'title' => 'Final Decision',
            'short_title' => 'Final Decision',
            'description' => 'Clinic staff reviews the completed examination here while any remaining regular documents are submitted and archived.',
            'icon' => 'clinical_notes',
        ],
        'follow_up' => [
            'title' => 'Follow-up Patients',
            'short_title' => 'Follow-up',
            'description' => 'Patients with findings who must submit treatment proof, clearance, or other required follow-up documents.',
            'icon' => 'medical_information',
        ],
        'completed' => [
            'title' => 'Completed APE Records',
            'short_title' => 'Completed',
            'description' => 'Patients whose checked documents are archived and whose follow-up requirements are cleared.',
            'icon' => 'task_alt',
        ],
    ];
}

function ape_document_follow_up(array $record): bool
{
    return ($record['requirement_status'] ?? '') !== 'Checked'
        && (int) ($record['follow_up_required'] ?? 0) === 1
        && !empty($record['follow_up_due_date']);
}

function ape_clinical_follow_up_required(array $record): bool
{
    return !empty($record['clinical_follow_up_required'])
        || ((int) ($record['follow_up_required'] ?? 0) === 1
            && ((int) ($record['deferred_requirement_count'] ?? 0) === 0
                || ($record['result_status'] ?? '') === 'Referred'));
}

function ape_follow_up_document_request_active(array $record): bool
{
    if (empty($record['exam_date'])) return false;
    return ($record['verification_status'] ?? '') === 'Needs Correction'
        || ((int) ($record['deferred_requirement_count'] ?? 0) > 0
            && !ape_deferred_submission_complete($record));
}

/**
 * Resolve the single Phase 3 group for both rendering and mutations.
 * A group remains actionable until every requirement in it is archived.
 */
function ape_phase_three_review_group(array $record, array $requirements, ?string $requestedGroup = null): string
{
    $groups = ['initial' => [], 'follow_up' => []];
    foreach ($requirements as $requirement) {
        if (($requirement['requirement_name'] ?? '') === 'Follow-up clearance') continue;
        $group = ($requirement['upload_group'] ?? 'initial') === 'follow_up' ? 'follow_up' : 'initial';
        $latestVerification = $requirement['_latest_document']['verification_status'] ?? null;
        if (($requirement['status'] ?? '') !== 'Verified'
            || ($latestVerification !== null && $latestVerification !== 'Verified')) {
            $groups[$group][] = $requirement;
        }
    }

    $available = array_keys(array_filter($groups));
    if (!$available) {
        throw new RuntimeException('There is no active document group to review.');
    }
    if ($requestedGroup !== null && $requestedGroup !== '') {
        if (!in_array($requestedGroup, ['initial', 'follow_up'], true) || !in_array($requestedGroup, $available, true)) {
            throw new InvalidArgumentException('Select an active Initial or Follow-up document group.');
        }
        return $requestedGroup;
    }

    // Returned work needs attention first; otherwise preserve the oldest active requirement.
    foreach ($available as $group) {
        foreach ($groups[$group] as $requirement) {
            if (($requirement['status'] ?? '') === 'Needs Correction'
                || (($requirement['_latest_document']['verification_status'] ?? '') === 'Needs Correction')) {
                return $group;
            }
        }
    }
    usort($available, static function (string $left, string $right) use ($groups): int {
        $leftId = min(array_map(static fn(array $item): int => (int) $item['requirement_id'], $groups[$left]));
        $rightId = min(array_map(static fn(array $item): int => (int) $item['requirement_id'], $groups[$right]));
        return $leftId <=> $rightId;
    });
    return $available[0];
}

function ape_phase_three_groups(array $requirements): array
{
    $groups = [];
    foreach (['initial', 'follow_up'] as $group) {
        $items = array_values(array_filter($requirements, static function (array $requirement) use ($group): bool {
            $latestVerification = $requirement['_latest_document']['verification_status'] ?? null;
            return ($requirement['requirement_name'] ?? '') !== 'Follow-up clearance'
                && (($requirement['upload_group'] ?? 'initial') === $group)
                && (($requirement['status'] ?? '') !== 'Verified'
                    || ($latestVerification !== null && $latestVerification !== 'Verified'));
        }));
        if ($items) $groups[$group] = $items;
    }
    return $groups;
}

/**
 * Split active Phase 3 requirements by what clinic staff can do now.
 * A replacement upload is ready for review even though the requirement was
 * previously returned; missing and still-returned items remain with the
 * student until a current Pending file exists.
 */
function ape_phase_three_requirement_states(array $requirements, string $group): array
{
    $states = ['ready' => [], 'waiting' => []];
    foreach ($requirements as $requirement) {
        $latestDocument = $requirement['_latest_document'] ?? null;
        if (($requirement['requirement_name'] ?? '') === 'Follow-up clearance'
            || ($requirement['upload_group'] ?? 'initial') !== $group
            || (($requirement['status'] ?? '') === 'Verified'
                && (($latestDocument['verification_status'] ?? null) === null
                    || ($latestDocument['verification_status'] ?? null) === 'Verified'))) {
            continue;
        }

        if (($latestDocument['verification_status'] ?? null) === 'Pending') {
            $states['ready'][] = $requirement;
        } else {
            $states['waiting'][] = $requirement;
        }
    }

    return $states;
}

function ape_follow_up_stage_active(array $record): bool
{
    return ape_clinical_follow_up_required($record)
        || ape_follow_up_document_request_active($record)
        || ($record['workflow_status'] ?? '') === 'Follow-up Required';
}

function ape_explicit_clearance_required(array $record): bool
{
    return (int) ($record['clearance_requirement_count'] ?? 0) > 0
        || ($record['clearance_status'] ?? '') === 'Submitted';
}

function ape_return_schedule(string $date, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable();
    $value = trim($date);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $now->getTimezone());
    if (!$parsed || $parsed->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Choose a valid return date for the selected documents.');
    }
    if ($parsed < $now->setTime(0, 0)) {
        throw new InvalidArgumentException('The return date must be today or a future date.');
    }
    return ['date' => $parsed->format('Y-m-d')];
}

function ape_initial_uploads_present(array $record): bool
{
    return (int) ($record['requirement_count'] ?? 0) > 0
        && (int) ($record['unassigned_upload_count'] ?? 1) === 0
        && (int) ($record['required_document_count'] ?? 0) >= (int) ($record['initial_requirement_count'] ?? 0);
}

function ape_upload_requirement_names(array $requirements, string $group = 'initial'): array
{
    return array_values(array_map(static fn(array $r): string => $r['requirement_name'],
        array_filter($requirements, static fn(array $r): bool => ($r['upload_group'] ?? null) === $group && $r['requirement_name'] !== 'Follow-up clearance')));
}

function ape_deferred_submission_complete(array $record): bool
{
    return (int) ($record['deferred_document_count'] ?? 0) >= (int) ($record['deferred_requirement_count'] ?? 0)
        && (int) ($record['deferred_unverified_count'] ?? 0) === 0;
}

function ape_digital_submission_complete(array $record): bool
{
    // File presence alone is not proof of a complete, reviewed submission.
    return ape_initial_uploads_present($record)
        && array_key_exists('required_unverified_count', $record)
        && (int) $record['required_unverified_count'] === 0;
}

function ape_record_queue(array $record): string
{
    if (($record['workflow_status'] ?? '') === 'Cleared' || ($record['clearance_status'] ?? '') === 'Cleared') {
        return 'completed';
    }

    if (!empty($record['exam_date'])) {
        // An examination is an irreversible workflow milestone. Outstanding
        // regular uploads remain actionable in Final Decision; they must not
        // send the patient back to the pre-examination Digital Keeping queue.
        if (ape_follow_up_stage_active($record)) {
            return 'follow_up';
        }

        // A saved clinical examination stays in the decision phase while regular
        // files are completed and archived. It must never move backward to step one.
        return 'final_decision';
    }

    // Once the assigned schedule starts, the patient remains examinable even if
    // the original window was missed and digital documents are still incomplete.
    if (ape_examination_is_available($record)) {
        return 'examination';
    }

    return ape_digital_submission_complete($record) ? 'examination' : 'digital_submission';
}

/**
 * A record may be completed only after the clinic has archived every active
 * initial and follow-up requirement and no clinical follow-up remains open.
 */
function ape_can_complete_record(array $record): bool
{
    if (empty($record['exam_date'])) {
        return false;
    }

    return ape_digital_submission_complete($record)
        && ape_deferred_submission_complete($record)
        && !ape_clinical_follow_up_required($record)
        && !ape_explicit_clearance_required($record);
}

/**
 * Resolve the clinic-facing operational queue without reusing the
 * patient-facing checklist stage.
 */
function ape_staff_queue_stage(array $record): string
{
    return ape_record_queue($record);
}

/** Count patients, not documents; requirement alerts can overlap work queues. */
function ape_batch_progress(array $records): array
{
    $summaries = [];
    foreach ($records as $record) {
        $batchId = (int) ($record['schedule_batch_id'] ?? 0);
        if ($batchId < 1) continue;
        if (!isset($summaries[$batchId])) {
            $summaries[$batchId] = array_fill_keys([
                'total', 'completed', 'examination', 'digital_submission',
                'final_decision', 'follow_up', 'incomplete', 'correction',
            ], 0);
        }
        $summary = &$summaries[$batchId];
        $summary['total']++;
        $queue = ape_staff_queue_stage($record);
        $summary[$queue]++;
        if (!in_array($queue, ['examination', 'completed'], true)) {
            if (trim((string) ($record['missing_items'] ?? '')) !== '') $summary['incomplete']++;
            if (($record['requirement_status'] ?? '') === 'Needs Correction'
                || ($record['verification_status'] ?? '') === 'Needs Correction') {
                $summary['correction']++;
            }
        }
        unset($summary);
    }
    return $summaries;
}

function ape_next_action(array $record): array
{
    return match (ape_record_queue($record)) {
        'examination' => ape_examination_is_available($record)
            ? ['label' => 'Record Examination', 'icon' => 'stethoscope']
            : (!empty($record['schedule_batch_id'])
                ? ['label' => 'Wait for Assigned Schedule', 'icon' => 'schedule']
                : ['label' => 'Assign APE Schedule', 'icon' => 'calendar_add_on']),
        'digital_submission' => !ape_initial_uploads_present($record)
            ? ['label' => 'Wait for Patient Upload', 'icon' => 'upload_file']
            : (empty($record['exam_date'])
                ? ['label' => 'Review at Examination', 'icon' => 'event_available']
                : ['label' => 'Archive Submission', 'icon' => 'inventory_2']),
        'final_decision' => ['label' => 'Record Final Decision', 'icon' => 'clinical_notes'],
        'follow_up' => ['label' => 'Update Follow-up', 'icon' => 'medical_information'],
        'completed' => ['label' => 'Completed', 'icon' => 'check_circle'],
        default => ['label' => 'Review Record', 'icon' => 'visibility'],
    };
}

/**
 * Resolve the visible Work Queue Map phase from the same progress calculation
 * shown to the student. Operational actions still use ape_record_queue(),
 * which can keep a saved examination actionable while the student finishes an
 * earlier checklist step.
 */
function ape_work_queue_stage(array $record): string
{
    $progress = ape_patient_progress($record);

    return match ((int) ($progress['active_step'] ?? 1)) {
        1 => 'digital_submission',
        2 => 'examination',
        3 => 'final_decision',
        4 => !empty($progress['steps'][4]['done']) ? 'completed' : 'follow_up',
        default => 'digital_submission',
    };
}

/**
 * Return the concrete APE action(s) currently required for a record.
 * Queue classification remains intentionally separate from this display/action model.
 *
 * @return array<int, array<string, mixed>>
 */
function ape_normalized_action_items(array $record, ?array $requirements = null, ?array $documents = null): array
{
    $apeId = (int) ($record['id'] ?? $record['ape_id'] ?? 0);
    $patientId = (int) ($record['patient_id'] ?? 0);
    $sourceUrl = '../ape/view.php?id=' . $apeId;
    $items = [];
    $add = static function (array &$items, array $item) use ($apeId, $patientId, $sourceUrl): void {
        $actionType = (string) ($item['action_type'] ?? 'open_source');
        $sourceType = (string) ($item['source_type'] ?? 'ape');
        $sourceId = (int) ($item['source_id'] ?? $apeId);
        $item['action_type'] = $actionType;
        $item['owner'] = in_array(($item['owner'] ?? 'clinic'), ['clinic', 'patient', 'shared'], true) ? $item['owner'] : 'clinic';
        $item['source_type'] = $sourceType;
        $item['source_id'] = $sourceId;
        $item['patient_person_id'] = (int) ($item['patient_person_id'] ?? $patientId);
        $item['source_url'] = (string) ($item['source_url'] ?? $sourceUrl);
        $item['email_relevant'] = !empty($item['email_relevant']);
        $item['email_event_type'] = trim((string) ($item['email_event_type'] ?? ''));
        $item['email_source_type'] = (string) ($item['email_source_type'] ?? $sourceType);
        $item['email_source_id'] = (int) ($item['email_source_id'] ?? $sourceId);
        $item['deduplication_key'] = (string) ($item['deduplication_key'] ?? ($sourceType . ':' . $sourceId . ':' . $actionType));
        $item['type'] = (string) ($item['type'] ?? 'ape_' . $actionType);
        $item['label'] = (string) ($item['label'] ?? 'APE workflow');
        $item['title'] = (string) ($item['title'] ?? ($item['action_label'] ?? 'Review APE action'));
        $item['action_label'] = (string) ($item['action_label'] ?? 'Open source');
        $item['description'] = (string) ($item['description'] ?? 'This APE action needs review.');
        $item['priority'] = in_array(($item['priority'] ?? 'clinic_action'), ['urgent', 'overdue', 'clinic_action', 'waiting_on_patient', 'scheduled'], true)
            ? $item['priority'] : 'clinic_action';
        $item['status'] = (string) ($item['status'] ?? 'Pending');
        $item['area'] = 'APE';
        $item['created_at'] = (string) ($item['created_at'] ?? ($record['created_at'] ?? date('Y-m-d H:i:s')));
        $item['due_at'] = (string) ($item['due_at'] ?? '');
        $items[$item['deduplication_key']] = $item;
    };

    if (($record['workflow_status'] ?? '') === 'Cleared' || ($record['clearance_status'] ?? '') === 'Cleared') {
        return [];
    }

    $requirements ??= ape_requirements_for_record($apeId);
    $documents ??= ape_documents_for_record($apeId);
    $latestDocuments = [];
    foreach ($documents as $document) {
        $type = strtolower(trim((string) ($document['document_type'] ?? '')));
        if ($type !== '' && !isset($latestDocuments[$type])) $latestDocuments[$type] = $document;
    }

    foreach ($latestDocuments as $document) {
        if (($document['verification_status'] ?? '') !== 'Needs Correction') continue;
        $documentId = (int) ($document['document_id'] ?? 0);
        $add($items, [
            'action_type' => 'online_document_correction',
            'label' => 'APE document correction',
            'title' => 'Correct online document',
            'action_label' => 'Review correction',
            'description' => (string) ($document['verification_remarks'] ?? $document['remarks'] ?? 'This uploaded APE document needs correction.'),
            'owner' => 'patient',
            'priority' => 'urgent',
            'status' => 'Needs Correction',
            'source_type' => 'ape_document',
            'source_id' => $documentId,
            'source_url' => '../ape/document.php?id=' . $documentId,
            'email_relevant' => true,
            'email_event_type' => 'ape_document_correction_required',
            'email_source_type' => 'ape',
            'email_source_id' => $apeId,
            'deduplication_key' => 'ape_document:' . $documentId . ':online_document_correction',
        ]);
    }

    foreach ($requirements as $requirement) {
        if (($requirement['status'] ?? '') !== 'Needs Correction') continue;
        $requirementId = (int) ($requirement['requirement_id'] ?? 0);
        $add($items, [
            'action_type' => 'document_correction',
            'label' => 'APE document correction',
            'title' => 'Review document correction',
            'action_label' => 'Review correction',
            'description' => (string) ($requirement['remarks'] ?? 'This APE document needs correction.'),
            'owner' => 'patient',
            'priority' => 'urgent',
            'status' => 'Needs Correction',
            'source_type' => 'ape_requirement',
            'source_id' => $requirementId,
            'source_url' => $sourceUrl,
            'email_relevant' => true,
            'email_event_type' => 'ape_document_correction_required',
            'email_source_type' => 'ape',
            'email_source_id' => $apeId,
            'due_at' => (string) ($requirement['upload_due_date'] ?? $record['follow_up_due_date'] ?? ''),
            'deduplication_key' => 'ape_requirement:' . $requirementId . ':document_correction',
        ]);
    }

    $resultStatus = (string) ($record['result_status'] ?? '');
    if ($resultStatus === 'Referred') {
        $add($items, [
            'action_type' => 'review_referral',
            'label' => 'APE referral',
            'title' => 'Review referral',
            'action_label' => 'Review referral',
            'description' => 'The examination created a referral that needs clinic review.',
            'owner' => 'clinic',
            'priority' => 'clinic_action',
            'status' => 'Referred',
            'source_type' => 'ape',
            'source_id' => $apeId,
        ]);
    }

    $followUpRequired = ape_follow_up_stage_active($record);
    if ($followUpRequired) {
        $hasSubmittedFollowUp = (int) ($record['deferred_document_count'] ?? 0) > 0
            || ($record['clearance_status'] ?? '') === 'Submitted';
        $add($items, [
            'action_type' => $hasSubmittedFollowUp
                ? 'review_follow_up_documents'
                : (($record['clearance_status'] ?? '') === 'Submitted' ? 'review_follow_up' : 'require_follow_up'),
            'label' => 'APE follow-up',
            'title' => $hasSubmittedFollowUp ? 'Review follow-up documents' : 'Review follow-up',
            'action_label' => $hasSubmittedFollowUp
                ? 'Review follow-up documents'
                : (($record['clearance_status'] ?? '') === 'Submitted' ? 'Review follow-up' : 'Require follow-up'),
            'description' => $hasSubmittedFollowUp
                ? 'Follow-up documents were submitted and need clinic review.'
                : 'The patient has an outstanding treatment, clearance, or referral follow-up requirement.',
            'owner' => $hasSubmittedFollowUp || ($record['clearance_status'] ?? '') === 'Submitted' ? 'clinic' : 'patient',
            'priority' => $hasSubmittedFollowUp || ($record['clearance_status'] ?? '') === 'Submitted' ? 'clinic_action' : 'urgent',
            'status' => (string) ($record['clearance_status'] ?? 'For Follow-up'),
            'due_at' => (string) ($record['follow_up_due_date'] ?? ''),
            'email_relevant' => !$hasSubmittedFollowUp && ($record['clearance_status'] ?? '') !== 'Submitted',
            'email_event_type' => $hasSubmittedFollowUp || ($record['clearance_status'] ?? '') === 'Submitted' ? '' : 'ape_follow_up_required',
        ]);
    }

    if (!empty($record['exam_date'])) {
        if (!$followUpRequired && ape_patient_progress($record)['active_step'] === 3 && ape_digital_submission_complete($record)) {
            $add($items, [
                'action_type' => 'record_final_decision',
                'label' => 'APE final decision',
                'title' => 'Record final decision',
                'action_label' => 'Record final decision',
                'description' => 'The examination and required documents are complete. Record the final clinical decision.',
                'owner' => 'clinic',
                'priority' => 'clinic_action',
                'status' => 'Pending',
            ]);
        } elseif (!$followUpRequired && !ape_digital_submission_complete($record)) {
            if ((int) ($record['required_unverified_count'] ?? 0) > 0) {
                $add($items, [
                    'action_type' => 'review_patient_upload',
                    'label' => 'APE document review',
                    'title' => 'Review patient uploads',
                    'action_label' => 'Review uploaded documents',
                    'description' => 'Submitted documents need clinic verification before they can be archived.',
                    'owner' => 'clinic',
                    'priority' => 'clinic_action',
                    'status' => 'Pending',
                ]);
            } else {
                $add($items, [
                    'action_type' => 'wait_for_patient_upload',
                    'label' => 'APE document upload',
                    'title' => 'Wait for patient upload',
                    'action_label' => 'Review patient uploads',
                    'description' => 'The student still needs to complete the required regular APE documents.',
                    'owner' => 'patient',
                    'priority' => 'waiting_on_patient',
                    'status' => 'Pending',
                ]);
            }
        }
        return array_values($items);
    }

    if (ape_examination_is_available($record)) {
        $add($items, [
            'action_type' => 'record_examination',
            'label' => 'APE examination',
            'title' => 'Record examination',
            'action_label' => 'Record examination',
            'description' => 'The assigned examination window is available for authorized clinic staff.',
            'owner' => 'clinic',
            'priority' => 'clinic_action',
            'status' => 'Ready',
        ]);
    } elseif (empty($record['schedule_batch_id'])) {
        $add($items, [
            'action_type' => 'assign_schedule',
            'label' => 'APE scheduling',
            'title' => 'Assign APE schedule',
            'action_label' => 'Assign APE schedule',
            'description' => 'This patient does not have an assigned APE schedule.',
            'owner' => 'clinic',
            'priority' => 'clinic_action',
            'status' => 'Unscheduled',
        ]);
    } elseif (!ape_initial_uploads_present($record)) {
        $add($items, [
            'action_type' => 'wait_for_patient_upload',
            'label' => 'APE document upload',
            'title' => 'Wait for patient upload',
            'action_label' => 'Review patient uploads',
            'description' => 'The student has not completed the required initial APE documents.',
            'owner' => 'patient',
            'priority' => 'waiting_on_patient',
            'status' => 'Pending',
        ]);
    } elseif ((int) ($record['required_unverified_count'] ?? 0) > 0 || (int) ($record['initial_unverified_count'] ?? 0) > 0) {
        $add($items, [
            'action_type' => 'review_patient_upload',
            'label' => 'APE document review',
            'title' => 'Review patient uploads',
            'action_label' => 'Review uploaded documents',
            'description' => 'Patient uploads are ready for clinic review.',
            'owner' => 'clinic',
            'priority' => 'clinic_action',
            'status' => 'Pending',
        ]);
    } else {
        $add($items, [
            'action_type' => 'wait_for_schedule',
            'label' => 'APE scheduling',
            'title' => 'Wait for assigned schedule',
            'action_label' => 'Open APE record',
            'description' => 'The patient has a scheduled APE batch that has not started yet.',
            'owner' => 'shared',
            'priority' => 'scheduled',
            'status' => 'Scheduled',
        ]);
    }

    return array_values($items);
}

/**
 * Summarize only the actions that a patient can still complete themselves.
 * A submitted Pending file belongs to clinic review, even when its requirement
 * is part of an active follow-up group, so it must never become an overdue
 * reminder candidate.
 *
 * @return array<int, array<string, mixed>>
 */
function ape_patient_document_action_summaries(array $record, ?array $requirements = null, ?array $documents = null, ?DateTimeImmutable $today = null): array
{
    if (in_array((string) ($record['workflow_status'] ?? ''), ['Cleared', 'Completed'], true)
        || (string) ($record['clearance_status'] ?? '') === 'Cleared') {
        return [];
    }

    $apeId = (int) ($record['ape_id'] ?? $record['id'] ?? 0);
    $patientId = (int) ($record['patient_id'] ?? 0);
    $today ??= new DateTimeImmutable('today');
    $requirements ??= ape_requirements_for_record($apeId);
    $documents ??= ape_documents_for_record($apeId);
    $latestByName = [];
    foreach ($documents as $document) {
        $name = strtolower(trim((string) ($document['document_type'] ?? '')));
        if ($name === '') continue;
        if (!isset($latestByName[$name]) || (int) ($document['document_id'] ?? 0) > (int) ($latestByName[$name]['document_id'] ?? 0)) {
            $latestByName[$name] = $document;
        }
    }

    $groups = [];
    foreach ($requirements as $requirement) {
        $status = (string) ($requirement['status'] ?? 'Missing');
        if ($status === 'Verified') continue;
        $name = strtolower(trim((string) ($requirement['requirement_name'] ?? '')));
        $latest = $name === '' ? null : ($latestByName[$name] ?? null);
        $latestStatus = (string) ($latest['verification_status'] ?? '');
        if ($latestStatus === 'Verified' || $latestStatus === 'Pending') continue;

        $needsCorrection = $status === 'Needs Correction' || $latestStatus === 'Needs Correction';
        $isMissing = $status === 'Missing' && $latest === null;
        if (!$needsCorrection && !$isMissing) continue;

        $group = (string) ($requirement['upload_group'] ?? 'initial');
        if (!in_array($group, ['initial', 'follow_up'], true)) $group = 'initial';
        $due = trim((string) ($requirement['upload_due_date'] ?? ''));
        if ($due === '') {
            $due = $group === 'follow_up'
                ? (string) (ape_follow_up_due_date($record) ?? '')
                : (!empty($record['exam_date']) ? date('Y-m-d', strtotime((string) $record['exam_date'] . ' +7 days')) : '');
        }
        $groups[$group] ??= ['requirements' => [], 'due_at' => null, 'has_correction' => false];
        $groups[$group]['requirements'][] = $requirement;
        $groups[$group]['has_correction'] = $groups[$group]['has_correction'] || $needsCorrection;
        if ($due !== '' && ($groups[$group]['due_at'] === null || $due < $groups[$group]['due_at'])) $groups[$group]['due_at'] = $due;
    }

    $summaries = [];
    foreach ($groups as $group => $data) {
        $dueAt = (string) ($data['due_at'] ?? '');
        $isOverdue = $dueAt !== '' && $dueAt < $today->format('Y-m-d');
        $names = array_map(static fn(array $requirement): string => (string) ($requirement['requirement_name'] ?? 'Document'), $data['requirements']);
        $isCorrection = !empty($data['has_correction']);
        $event = $isCorrection
            ? 'ape_document_correction_required'
            : ($group === 'follow_up' ? 'ape_follow_up_overdue' : 'ape_documents_overdue');
        $summaries[] = [
            'type' => 'ape_' . $group . '_patient_documents',
            'label' => $group === 'follow_up' ? 'APE follow-up documents' : 'APE documents',
            'title' => $isCorrection ? 'Correct required APE document' : ($isOverdue ? 'Required APE document is overdue' : 'Waiting for patient document'),
            'description' => $isCorrection
                ? 'The student must correct the requested document before clinic review can continue.'
                : 'The student still needs to submit: ' . implode(', ', $names) . '.',
            'owner' => 'patient',
            'priority' => $isOverdue ? 'overdue' : ($isCorrection ? 'urgent' : 'waiting_on_patient'),
            'status' => $isCorrection ? 'Needs Correction' : ($isOverdue ? 'Overdue' : 'Waiting on patient'),
            'due_at' => $dueAt,
            'source_type' => 'ape',
            'source_id' => $apeId,
            'source_url' => '../ape/view.php?id=' . $apeId,
            'patient_person_id' => $patientId,
            'email_relevant' => $isCorrection || $isOverdue,
            'email_event_type' => $event,
            'email_source_type' => 'ape',
            'email_source_id' => $apeId,
            'requirement_group' => $group,
            'requirement_ids' => array_map(static fn(array $requirement): int => (int) ($requirement['requirement_id'] ?? 0), $data['requirements']),
            'deduplication_key' => 'ape:' . $apeId . ':patient-documents:' . $group,
        ];
    }

    if ($summaries === [] && ape_clinical_follow_up_required($record)) {
        $dueAt = (string) (ape_follow_up_due_date($record) ?? '');
        $isOverdue = $dueAt !== '' && $dueAt < $today->format('Y-m-d');
        $summaries[] = [
            'type' => 'ape_clinical_follow_up', 'label' => 'APE follow-up',
            'title' => $isOverdue ? 'APE follow-up is overdue' : 'Waiting for patient follow-up',
            'description' => 'The patient still has a clinic-directed follow-up action to complete.',
            'owner' => 'patient', 'priority' => $isOverdue ? 'overdue' : 'waiting_on_patient',
            'status' => $isOverdue ? 'Overdue' : 'Waiting on patient', 'due_at' => $dueAt,
            'source_type' => 'ape', 'source_id' => $apeId, 'source_url' => '../ape/view.php?id=' . $apeId,
            'patient_person_id' => $patientId, 'email_relevant' => $isOverdue,
            'email_event_type' => 'ape_follow_up_overdue', 'email_source_type' => 'ape', 'email_source_id' => $apeId,
            'requirement_group' => 'clinical', 'requirement_ids' => [],
            'deduplication_key' => 'ape:' . $apeId . ':clinical-follow-up',
        ];
    }

    return $summaries;
}

/**
 * Determine whether an APE record belongs in the immediate-attention badge.
 * Detailed corrections remain visible in the APE queue but are not part of
 * the red Missed/Overdue attention panel.
 */
function ape_has_urgent_action(array $record): bool
{
    $priority = ape_priority_badge($record);
    if (($priority['label'] ?? '') === 'Missed' && ape_record_queue($record) === 'examination') return true;
    foreach (ape_patient_document_action_summaries($record) as $action) {
        if (($action['priority'] ?? '') === 'overdue') return true;
    }
    return false;
}

function ape_record_stage_label(array $record): string
{
    $queueKey = ape_record_queue($record);
    $queues = ape_work_queues();

    return $queues[$queueKey]['short_title'] ?? $queues[$queueKey]['title'] ?? 'APE Review';
}

function ape_record_step_index(array $record): int
{
    return match (ape_record_queue($record)) {
        'digital_submission' => 0,
        'examination' => 1,
        'final_decision' => 2,
        'follow_up' => 2,
        'completed' => 3,
        default => 0,
    };
}

/**
 * Resolve the patient-facing APE checklist independently from the staff queue.
 * Staff may work in Final Decision after an examination while the student's
 * earliest incomplete requirement remains the only active checklist step.
 *
 * @return array{steps: array<int, array{number: int, key: string, done: bool, active: bool, locked: bool}>, completed_count: int, percent: int, active_step: int, stage_label: string}
 */
function ape_patient_progress(array $record): array
{
    $isCleared = in_array('Cleared', [
        $record['workflow_status'] ?? '',
        $record['clearance_status'] ?? '',
    ], true);
    $decisionRecorded = $isCleared || in_array(
        $record['clearance_status'] ?? '',
        ['For Follow-up', 'Submitted'],
        true
    );

    $steps = [
        1 => ['number' => 1, 'key' => 'digital_keeping', 'done' => $isCleared || ape_digital_submission_complete($record)],
        2 => ['number' => 2, 'key' => 'examination', 'done' => $isCleared || !empty($record['exam_date'])],
        3 => ['number' => 3, 'key' => 'final_decision', 'done' => $decisionRecorded],
        4 => ['number' => 4, 'key' => 'completed', 'done' => $isCleared],
    ];

    $activeStep = 4;
    foreach ($steps as $number => $step) {
        if (!$step['done']) {
            $activeStep = $number;
            break;
        }
    }

    $completedCount = count(array_filter($steps, static fn (array $step): bool => $step['done']));
    foreach ($steps as $number => &$step) {
        $step['active'] = !$step['done'] && $number === $activeStep;
        $step['locked'] = !$step['done'] && $number > $activeStep;
    }
    unset($step);

    $stageLabels = [
        1 => 'Digital Keeping',
        2 => 'Examination',
        3 => 'Final Decision',
        4 => $isCleared ? 'Completed' : 'Follow-up Clearance',
    ];

    return [
        'steps' => $steps,
        'completed_count' => $completedCount,
        'percent' => $completedCount * 25,
        'active_step' => $activeStep,
        'stage_label' => $stageLabels[$activeStep],
    ];
}

/**
 * Resolve the staff-facing APE workflow strip independently from the patient
 * checklist. The assigned examination schedule takes precedence once it starts;
 * regular document uploads remain available in parallel until their deadline.
 *
 * @return array{steps: array<int, array{number: int, key: string, done: bool, submitted: bool, active: bool}>, active_step: int}
 */
function ape_staff_progress(array $record): array
{
    $isCleared = in_array('Cleared', [
        $record['workflow_status'] ?? '',
        $record['clearance_status'] ?? '',
    ], true);
    $initialUploadsSubmitted = ape_initial_uploads_present($record);
    $examRecorded = !empty($record['exam_date']);
    $examinationAvailable = !$examRecorded && ape_examination_is_available($record);

    $activeStep = $isCleared
        ? 4
        : ($examRecorded
            ? 3
            : ($examinationAvailable || $initialUploadsSubmitted ? 2 : 1));

    $steps = [
        1 => [
            'number' => 1,
            'key' => 'digital_keeping',
            'done' => $isCleared,
            'submitted' => !$isCleared && $initialUploadsSubmitted,
            'active' => !$isCleared && $activeStep === 1,
        ],
        2 => [
            'number' => 2,
            'key' => 'examination',
            'done' => $isCleared || $examRecorded,
            'submitted' => false,
            'active' => !$isCleared && $activeStep === 2,
        ],
        3 => [
            'number' => 3,
            'key' => 'final_decision',
            'done' => $isCleared,
            'submitted' => false,
            'active' => !$isCleared && $activeStep === 3,
        ],
        4 => [
            'number' => 4,
            'key' => 'completed',
            'done' => $isCleared,
            'submitted' => false,
            'active' => $isCleared,
        ],
    ];

    return ['steps' => $steps, 'active_step' => $activeStep];
}

/**
 * Resolve the student-facing APE journey. A complete upload group is submitted
 * student work; clinic archive review remains the current final-decision stage.
 * When an assigned examination schedule starts, Examination is shown as the
 * active stage even if the student may still upload regular documents.
 *
 * @return array{steps: array<int, array{number: int, key: string, done: bool, submitted: bool, active: bool}>, completed_count: int, percent: int, active_step: int, stage_label: string}
 */
function ape_student_progress(array $record): array
{
    $isCleared = in_array('Cleared', [
        $record['workflow_status'] ?? '',
        $record['clearance_status'] ?? '',
    ], true);
    // After examination, a returned file is a Follow-up correction and must not
    // move the student backward to Digital Keeping/Step 1.
    $initialUploadsSubmitted = ape_initial_uploads_present($record);
    $examRecorded = !empty($record['exam_date']);
    $examinationAvailable = !$examRecorded && ape_examination_is_available($record);
    $activeStep = $isCleared
        ? 4
        : (!$examRecorded
            ? ($examinationAvailable || $initialUploadsSubmitted ? 2 : 1)
            : 3);

    $steps = [
        1 => [
            'number' => 1,
            'key' => 'digital_keeping',
            'done' => $isCleared || $initialUploadsSubmitted,
            'submitted' => !$isCleared && $initialUploadsSubmitted,
            'active' => !$isCleared && $activeStep === 1,
        ],
        2 => [
            'number' => 2,
            'key' => 'examination',
            'done' => $isCleared || $examRecorded,
            'submitted' => false,
            'active' => !$isCleared && $activeStep === 2,
        ],
        3 => [
            'number' => 3,
            'key' => 'final_decision',
            'done' => $isCleared,
            'submitted' => false,
            'active' => !$isCleared && $activeStep === 3,
        ],
        4 => [
            'number' => 4,
            'key' => 'completed',
            'done' => $isCleared,
            'submitted' => false,
            'active' => $activeStep === 4,
        ],
    ];
    $completedCount = count(array_filter($steps, static fn(array $step): bool => $step['done']));

    return [
        'steps' => $steps,
        'completed_count' => $completedCount,
        'percent' => $completedCount * 25,
        'active_step' => $activeStep,
        'stage_label' => match ($activeStep) {
            1 => 'Digital Keeping',
            2 => 'Examination',
            3 => 'Final Decision or Follow-up',
            4 => 'Completed',
        },
    ];
}

function ape_schedule_is_current(array $record, ?DateTimeImmutable $now = null): bool
{
    if (empty($record['schedule_batch_id']) || ($record['batch_status'] ?? '') !== 'Scheduled') {
        return false;
    }

    $start = $record['batch_start_at'] ?? null;
    $end = $record['batch_end_at'] ?? null;
    if (!$start || !$end) {
        return false;
    }

    try {
        $current = $now ?? new DateTimeImmutable('now');
        $startsAt = new DateTimeImmutable((string) $start);
        $endsAt = new DateTimeImmutable((string) $end);
    } catch (Exception $exception) {
        return false;
    }

    return $current >= $startsAt && $current <= $endsAt;
}

function ape_examination_is_available(array $record, ?DateTimeImmutable $now = null): bool
{
    if (($record['entry_mode'] ?? '') === 'Clinic Manual') {
        return true;
    }
    if (empty($record['schedule_batch_id']) || ($record['batch_status'] ?? '') === 'Cancelled') {
        return false;
    }

    $start = $record['batch_start_at'] ?? null;
    if (!$start) {
        return false;
    }

    try {
        $current = $now ?? new DateTimeImmutable('now');
        $startsAt = new DateTimeImmutable((string) $start);
    } catch (Exception $exception) {
        return false;
    }

    return $current >= $startsAt;
}

function ape_earliest_upcoming_batch(array $batches, ?DateTimeImmutable $now = null): ?array
{
    $current = $now ?? new DateTimeImmutable('now');
    $scheduled = array_values(array_filter($batches, static function (array $batch) use ($current): bool {
        if (($batch['status'] ?? '') !== 'Scheduled') {
            return false;
        }
        $endValue = trim((string) ($batch['schedule_date'] ?? '') . ' ' . (string) ($batch['end_time'] ?? ''));
        try {
            return new DateTimeImmutable($endValue, $current->getTimezone()) >= $current;
        } catch (Exception $exception) {
            return false;
        }
    }));
    usort($scheduled, static function (array $left, array $right): int {
        $leftSchedule = (string) ($left['schedule_date'] ?? '') . ' ' . (string) ($left['start_time'] ?? '');
        $rightSchedule = (string) ($right['schedule_date'] ?? '') . ' ' . (string) ($right['start_time'] ?? '');
        return strcmp($leftSchedule, $rightSchedule) ?: ((int) ($left['batch_id'] ?? 0) <=> (int) ($right['batch_id'] ?? 0));
    });

    return $scheduled[0] ?? null;
}

function ape_waiting_days(array $record): int
{
    $rawDate = $record['updated_at'] ?? $record['created_at'] ?? null;
    if (!$rawDate) {
        return 0;
    }

    $timestamp = strtotime($rawDate);
    if (!$timestamp) {
        return 0;
    }

    return max(0, (int)floor((time() - $timestamp) / 86400));
}

function ape_follow_up_due_date(array $record): ?string
{
    $rawDate = trim((string) ($record['follow_up_due_date'] ?? ''));
    if ($rawDate !== '') {
        $timestamp = strtotime($rawDate);
        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }

    return null;
}

function ape_deadline_status(array $record, ?DateTimeImmutable $today = null, ?array $requirements = null, ?array $documents = null): ?array
{
    $today ??= new DateTimeImmutable('today');
    // No patient deadline exists when the record's aggregate state confirms
    // that every requirement has a current file and the latest work is pending
    // clinic review. Run this before any detail lookup so fixture records do
    // not accidentally resolve a live APE record with the same ID.
    if ((int) ($record['requirement_count'] ?? 0) > 0
        && (int) ($record['document_count'] ?? 0) >= (int) ($record['requirement_count'] ?? 0)
        && (string) ($record['verification_status'] ?? '') === 'Pending') {
        return null;
    }
    $patientActions = array_values(array_filter(
        ape_patient_document_action_summaries($record, $requirements, $documents, $today),
        static fn(array $action): bool => trim((string) ($action['due_at'] ?? '')) !== ''
    ));
    if ($patientActions !== []) {
        usort($patientActions, static fn(array $left, array $right): int => strcmp((string) $left['due_at'], (string) $right['due_at']));
        $action = $patientActions[0];
        $dueDate = (string) $action['due_at'];
        $due = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate, $today->getTimezone());
        if ($due) {
            $diff = (int) $today->diff($due)->format('%r%a');
            if (($action['priority'] ?? '') === 'overdue') {
                return ['label' => 'Overdue', 'class' => 'badge-critical', 'due_date' => $dueDate, 'days' => abs($diff)];
            }
            return ['label' => 'On Track', 'class' => 'badge-in-progress', 'due_date' => $dueDate, 'days' => max(0, $diff)];
        }
    }

    $queueKey = ape_record_queue($record);
    $dueDate = null;

    // This deadline is for the student's initial upload. A complete upload
    // awaiting clinic archive review is a clinic action, not an overdue
    // student action eligible for a reminder.
    if (!empty($record['exam_date']) && !ape_initial_uploads_present($record)) {
        $examDate = $record['exam_date'] ?? null;
        $examTimestamp = $examDate ? strtotime((string) $examDate) : false;
        if (!$examTimestamp) {
            return null;
        }
        $dueDate = $record['initial_upload_due_date'] ?? date('Y-m-d', strtotime('+7 days', $examTimestamp));
    } elseif ($queueKey === 'follow_up') {
        $dueDate = !ape_deferred_submission_complete($record)
            ? ($record['deferred_upload_due_date'] ?? ape_follow_up_due_date($record))
            : ape_follow_up_due_date($record);
    }

    if (!$dueDate) {
        return null;
    }

    $today = $today->setTime(0, 0);
    $due = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate, $today->getTimezone());
    if (!$due) {
        return null;
    }

    // Regular uploads receive seven days from examination. Follow-up documents
    // use the clinic-assigned return date exactly.
    $warningDate = $due;
    $diff = (int) $today->diff($due)->format('%r%a');
    if ($today > $warningDate) {
        return [
            'label' => 'Overdue',
            'class' => 'badge-critical',
            'due_date' => $dueDate,
            'days' => abs($diff),
        ];
    }

    return [
        'label' => 'On Track',
        'class' => 'badge-in-progress',
        'due_date' => $dueDate,
        'days' => max(0, $diff),
    ];
}

function ape_priority_badge(array $record): array
{
    $queueKey = ape_record_queue($record);
    if ($queueKey === 'completed') {
        return ['label' => 'Done', 'class' => 'badge-completed'];
    }

    $deadline = ape_deadline_status($record);
    if ($deadline && $deadline['label'] === 'Overdue') {
        return ['label' => $deadline['label'], 'class' => $deadline['class']];
    }

    if ($queueKey === 'follow_up') {
        return ['label' => 'Clinical', 'class' => 'badge-high'];
    }

    // A patient is missed only after their own assigned batch has ended.
    $batchEnd = $record['batch_end_at'] ?? null;
    if (
        $batchEnd
        && ($record['batch_status'] ?? '') === 'Scheduled'
        && empty($record['exam_date'])
        && time() > strtotime($batchEnd)
    ) {
        return ['label' => 'Missed', 'class' => 'badge-critical'];
    }

    if ($queueKey === 'digital_submission') {
        return ['label' => 'Waiting', 'class' => 'badge-pending'];
    }

    return ['label' => 'Ready', 'class' => 'badge-in-progress'];
}

function ape_waiting_label(array $record): string
{
    $days = ape_waiting_days($record);
    if ($days === 0) {
        return 'Updated today';
    }

    return $days . ' day' . ($days === 1 ? '' : 's') . ' waiting';
}


function ape_next_action_card(array $record): array
{
    return match (ape_record_queue($record)) {
        'examination' => [
            'title' => ape_examination_is_available($record) ? 'Record the examination' : 'Assign this patient to an APE batch',
            'body' => ape_examination_is_available($record) ? 'Enter the examination result even if digital documents are incomplete, and check any hard copies the patient brings.' : 'Digital uploads may continue while the patient waits for an assigned examination schedule.',
        ],
        'digital_submission' => !ape_initial_uploads_present($record)
            ? [
                'title' => empty($record['exam_date']) ? 'Wait for early digital uploads' : 'Wait for the remaining regular uploads',
                'body' => empty($record['exam_date']) ? 'The patient may upload required documents now. Missing files will not prevent attendance during the assigned examination schedule.' : 'The patient has seven days from the examination date to complete regular uploads. Follow-up documents keep their separately assigned return date.',
            ]
            : [
                'title' => empty($record['exam_date']) ? 'Review uploads during the examination' : 'Archive the digital submission',
                'body' => empty($record['exam_date']) ? 'The required files are present. Their final hard-copy comparison and archive review can be completed with the examination.' : 'Confirm that the online files match the checked hard copies, then archive them so the record can proceed to final decision.',
            ],
        'final_decision' => [
            'title' => 'Record the final clinical decision',
            'body' => ape_digital_submission_complete($record)
                ? 'Review the examination and archived documents, then explicitly clear the patient or require follow-up.'
                : 'Review the completed examination here while the patient finishes the remaining regular documents. The record stays in Final Decision.',
        ],
        'follow_up' => [
            'title' => 'Track the required follow-up',
            'body' => 'Keep the record open until the patient submits the required treatment proof, medical clearance, or other follow-up document.',
        ],
        'completed' => [
            'title' => 'APE process completed',
            'body' => 'This record is cleared and stored as part of the patient clinic file.',
        ],
        default => [
            'title' => 'Review this APE record',
            'body' => 'Open the record details and complete the next clinic action.',
        ],
    };
}

function ape_missing_item(array $record): string
{
    $queue = ape_record_queue($record);
    if ($queue === 'digital_submission') {
        if (!ape_initial_uploads_present($record)) {
            return empty($record['exam_date']) ? 'Early digital uploads incomplete' : 'Regular uploads due within seven days of examination';
        }
        return ($record['verification_status'] ?? '') === 'Needs Correction'
            ? 'Online submission correction needed'
            : (empty($record['exam_date']) ? 'Uploads ready for examination review' : 'Online documents waiting for archive review');
    }
    if ($queue === 'examination') {
        return ape_examination_is_available($record) ? 'Ready for examination' : 'Waiting for assigned APE schedule';
    }
    if ($queue === 'final_decision' && !ape_digital_submission_complete($record)) {
        return 'Regular digital documents still pending';
    }
    if (($record['requirement_status'] ?? '') === 'Not Checked') {
        return 'Waiting for follow-up hard-copy documents';
    }
    if (($record['requirement_status'] ?? '') === 'Needs Correction') {
        return 'Hard-copy requirements need attention';
    }
    if (!empty($record['missing_items'])) {
        return $record['missing_items'];
    }
    if ((int)($record['follow_up_required'] ?? 0) === 1 || ($record['clearance_status'] ?? '') === 'For Follow-up') {
        return 'Follow-up action required';
    }
    if (!ape_initial_uploads_present($record)) {
        return 'Waiting for patient online submission';
    }
    if ((int) ($record['required_unverified_count'] ?? 0) > 0 && ($record['verification_status'] ?? '') === 'Pending') {
        return 'Online documents waiting for archive review';
    }
    if (($record['verification_status'] ?? '') === 'Needs Correction') {
        return 'Online submission correction needed';
    }
    if (ape_record_queue($record) === 'final_decision') {
        return 'Final clearance decision required';
    }
    if ((int)($record['follow_up_required'] ?? 0) === 1 && ($record['clearance_status'] ?? '') !== 'Submitted') {
        return 'Waiting for follow-up requirement';
    }
    if (($record['clearance_status'] ?? '') === 'Submitted') {
        return 'Follow-up document waiting for approval';
    }
    return 'None';
}

function ape_status_badge_class(?string $status): string
{
    return match (strtolower((string)$status)) {
        'cleared', 'verified', 'Checked', 'exam done', 'completed', 'fit to proceed' => 'badge-completed',
        'scheduled', 'reviewed', 'submitted', 'batch assigned', 'requirements checked', 'with finding' => 'badge-in-progress',
        'follow-up required', 'needs correction', 'for follow-up' => 'badge-high',
        'critical' => 'badge-critical',
        'low' => 'badge-low',
        'moderate', 'pending', 'not checked' => 'badge-pending',
        default => 'badge-pending',
    };
}

function ensure_ape_workflow_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $db = auth_db();
    foreach (['ape_cycles', 'ape_schedule_batches', 'ape_records', 'ape_requirements', 'ape_documents', 'ape_findings', 'ape_activity_logs'] as $table) {
        $stmt = $db->prepare('
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ');
        $stmt->execute([$table]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException("Required Cliniq_db table {$table} is missing. Run the APE migration first.");
        }
    }

    $measurementColumns = [
        'patient_height_cm' => "DECIMAL(5,2) NULL AFTER patient_visible_note",
        'patient_weight_kg' => "DECIMAL(5,2) NULL AFTER patient_height_cm",
        'patient_bmi' => "DECIMAL(5,2) NULL AFTER patient_weight_kg",
        'patient_temperature' => "DECIMAL(4,1) NULL AFTER patient_bmi",
        'patient_blood_pressure' => "VARCHAR(20) NULL AFTER patient_temperature",
        'patient_pulse_rate' => "SMALLINT UNSIGNED NULL AFTER patient_blood_pressure",
    ];
    $workflowColumns = [
        'follow_up_due_date' => "DATE NULL AFTER follow_up_required",
        'follow_up_due_time' => "TIME NULL AFTER follow_up_due_date",
        'requirements_saved_at' => "DATETIME NULL AFTER requirement_status",
    ];
    foreach (array_merge($measurementColumns, $workflowColumns) as $column => $definition) {
        $columnCheck = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'ape_records\' AND COLUMN_NAME = ?');
        $columnCheck->execute([$column]);
        if ((int) $columnCheck->fetchColumn() === 0) {
            $db->exec("ALTER TABLE ape_records ADD COLUMN {$column} {$definition}");
        }
    }

    foreach (['upload_group', 'upload_due_date'] as $column) {
        $columnCheck = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ape_requirements' AND COLUMN_NAME = ?");
        $columnCheck->execute([$column]);
        if ((int) $columnCheck->fetchColumn() !== 1) {
            throw new RuntimeException('Run the approved 20260903_ape_document_upload_groups.sql migration before using APE.');
        }
    }
    $ready = true;
}

function ape_school_year_history_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    try {
        $check = auth_db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_school_year_enrollments'");
        $available = (int) $check->fetchColumn() === 1;
    } catch (Throwable) {
        $available = false;
    }

    return $available;
}

function ape_record_select_sql(): string
{
    // The production database contains older case-sensitive program columns alongside
    // newer enrollment columns. Give the generated label one explicit collation before
    // comparing it to an empty string, so mixed legacy collations cannot crash the
    // patient dashboard query.
    $courseSectionExpression = static function (string $programCode, string $yearLevel, string $section): string {
        return "NULLIF(
            TRIM(CONCAT({$programCode}, '-', {$yearLevel}, UPPER({$section}))) COLLATE utf8mb4_unicode_ci,
            _utf8mb4'' COLLATE utf8mb4_unicode_ci
        )";
    };

    $currentCourseSection = $courseSectionExpression('pr.program_code', 's.year_level', 's.section');
    $historyCourseSection = '';
    $historyJoins = '';
    if (ape_school_year_history_available()) {
        $historyCourseSection = $courseSectionExpression('history_program.program_code', 'school_year_history.year_level', 'school_year_history.section') . ',';
        $historyJoins = "
        LEFT JOIN student_school_year_enrollments school_year_history
            ON school_year_history.student_person_id = p.id
            AND school_year_history.academic_year COLLATE utf8mb4_unicode_ci = ar.academic_year COLLATE utf8mb4_unicode_ci
        LEFT JOIN programs history_program ON history_program.id = school_year_history.program_id";
    }

    return "
        SELECT
            ar.*,
            ar.ape_id AS id,
            batch.batch_name,
            batch.patient_category AS batch_patient_category,
            batch.schedule_date AS batch_schedule_date,
            batch.start_time AS batch_start_time,
            batch.end_time AS batch_end_time,
            batch.status AS batch_status,
            CASE
                WHEN batch.batch_id IS NULL THEN NULL
                ELSE CONCAT(batch.schedule_date, ' ', batch.end_time)
            END AS batch_end_at,
            CASE
                WHEN batch.batch_id IS NULL THEN NULL
                ELSE CONCAT(batch.schedule_date, ' ', batch.start_time)
            END AS batch_start_at,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.id_number,
            p.sex,
            p.birthdate,
            patient_profile.blood_type AS patient_blood_type,
            patient_profile.allergies AS patient_allergies,
            patient_profile.existing_conditions AS patient_existing_conditions,
            patient_profile.medications AS patient_medications,
            COALESCE(
                {$historyCourseSection}
                {$currentCourseSection},
                ed.department_code,
                'Patient'
            ) AS course_section,
            COALESCE((
                SELECT d.document_type
                FROM ape_documents d
                WHERE d.ape_id = ar.ape_id
                ORDER BY d.uploaded_at DESC, d.document_id DESC
                LIMIT 1
            ), 'APE Form') AS document_type,
            (
                SELECT d.file_path
                FROM ape_documents d
                WHERE d.ape_id = ar.ape_id
                ORDER BY d.uploaded_at DESC, d.document_id DESC
                LIMIT 1
            ) AS document_path,
            COALESCE((
                SELECT d.verification_status
                FROM ape_documents d
                WHERE d.ape_id = ar.ape_id
                  AND d.document_id = (
                      SELECT MAX(latest_document.document_id)
                      FROM ape_documents latest_document
                      WHERE latest_document.ape_id = d.ape_id
                        AND latest_document.document_type = d.document_type
                  )
                ORDER BY FIELD(d.verification_status, 'Needs Correction', 'Pending', 'Verified'), d.uploaded_at DESC
                LIMIT 1
            ), 'Pending') AS verification_status,
            COALESCE(
                TRIM(CONCAT_WS(' ', reviewer.first_name, reviewer.middle_name, reviewer.last_name)),
                (
                    SELECT TRIM(CONCAT_WS(' ', verifier.first_name, verifier.middle_name, verifier.last_name))
                    FROM ape_documents verified_document
                    JOIN people verifier ON verifier.id = verified_document.verified_by_person_id
                    WHERE verified_document.ape_id = ar.ape_id
                    ORDER BY verified_document.verified_at DESC, verified_document.document_id DESC
                    LIMIT 1
                )
            ) AS verified_by_name,
            ap.appointment_datetime,
            CASE WHEN ap.appointment_id IS NULL THEN NULL ELSE 'PLP Clinic' END AS appointment_location,
            (
                SELECT GROUP_CONCAT(CONCAT(r.requirement_name, ' (', r.status, ')') ORDER BY r.requirement_id SEPARATOR ', ')
                FROM ape_requirements r
                WHERE r.ape_id = ar.ape_id AND r.status <> 'Verified'
            ) AS missing_items,
            CASE
                WHEN EXISTS (SELECT 1 FROM ape_findings f WHERE f.ape_id = ar.ape_id AND f.result_status = 'Referred') THEN 'Referred'
                WHEN EXISTS (SELECT 1 FROM ape_findings f WHERE f.ape_id = ar.ape_id AND f.result_status = 'With Finding') THEN 'With Finding'
                WHEN EXISTS (SELECT 1 FROM ape_findings f WHERE f.ape_id = ar.ape_id) THEN 'Normal'
                ELSE 'Pending'
            END AS result_status,
            (
                SELECT GROUP_CONCAT(f.description ORDER BY f.recorded_at DESC SEPARATOR ' | ')
                FROM ape_findings f
                WHERE f.ape_id = ar.ape_id
            ) AS result_notes,
            EXISTS(
                SELECT 1
                FROM ape_findings f
                WHERE f.ape_id = ar.ape_id
                  AND f.follow_up_required = 1
            ) AS clinical_follow_up_required,
            (
                SELECT d.file_path
                FROM ape_documents d
                WHERE d.ape_id = ar.ape_id AND d.document_type = 'Clearance'
                ORDER BY d.uploaded_at DESC, d.document_id DESC
                LIMIT 1
            ) AS clearance_document_path,
            (SELECT COUNT(*) FROM ape_documents d WHERE d.ape_id = ar.ape_id) AS document_count,
            COALESCE(uploads.requirement_count, 0) AS requirement_count,
            COALESCE(uploads.unassigned_upload_count, 0) AS unassigned_upload_count,
            COALESCE(uploads.initial_requirement_count, 0) AS initial_requirement_count,
            COALESCE(uploads.required_document_count, 0) AS required_document_count,
            COALESCE(uploads.required_unverified_count, 0) AS required_unverified_count,
            COALESCE(uploads.deferred_requirement_count, 0) AS deferred_requirement_count,
            COALESCE(uploads.deferred_document_count, 0) AS deferred_document_count,
            COALESCE(uploads.deferred_unverified_count, 0) AS deferred_unverified_count,
            uploads.initial_upload_due_date,
            uploads.deferred_upload_due_date,
            COALESCE((SELECT COUNT(*) FROM ape_requirements cr WHERE cr.ape_id = ar.ape_id AND cr.requirement_name = 'Follow-up clearance'), 0) AS clearance_requirement_count
        FROM ape_records ar
        LEFT JOIN (
            SELECT r.ape_id, COUNT(*) AS requirement_count,
                0 AS unassigned_upload_count,
                SUM(COALESCE(r.upload_group, 'initial') = 'initial') AS initial_requirement_count,
                SUM(COALESCE(r.upload_group, 'initial') = 'initial' AND d.document_id IS NOT NULL) AS required_document_count,
                SUM(COALESCE(r.upload_group, 'initial') = 'initial' AND d.verification_status <> 'Verified') AS required_unverified_count,
                SUM(r.upload_group = 'follow_up') AS deferred_requirement_count,
                SUM(r.upload_group = 'follow_up' AND d.document_id IS NOT NULL) AS deferred_document_count,
                SUM(r.upload_group = 'follow_up' AND d.verification_status <> 'Verified') AS deferred_unverified_count,
                MIN(CASE WHEN COALESCE(r.upload_group, 'initial') = 'initial' THEN r.upload_due_date END) AS initial_upload_due_date,
                MIN(CASE WHEN r.upload_group = 'follow_up' THEN r.upload_due_date END) AS deferred_upload_due_date
            FROM ape_requirements r
            LEFT JOIN ape_documents d ON d.document_id = (
                SELECT MAX(v.document_id) FROM ape_documents v
                WHERE v.ape_id = r.ape_id AND v.document_type = r.requirement_name
            )
            WHERE r.requirement_name <> 'Follow-up clearance'
            GROUP BY r.ape_id
        ) uploads ON uploads.ape_id = ar.ape_id
        JOIN patients patient_profile ON patient_profile.person_id = ar.patient_id
        JOIN people p ON p.id = patient_profile.person_id
        LEFT JOIN students s ON s.person_id = p.id
        LEFT JOIN programs pr ON pr.id = s.program_id
        {$historyJoins}
        LEFT JOIN school_employees se ON se.person_id = p.id
        LEFT JOIN departments ed ON ed.id = se.department_id
        LEFT JOIN clinic_staff reviewer_staff ON reviewer_staff.person_id = ar.reviewed_by_person_id
        LEFT JOIN people reviewer ON reviewer.id = reviewer_staff.person_id
        LEFT JOIN appointments ap ON ap.appointment_id = ar.appointment_id
        LEFT JOIN ape_cycles ac ON ac.ape_cycle_id = ar.ape_cycle_id
        LEFT JOIN ape_schedule_batches batch ON batch.batch_id = ar.schedule_batch_id
    ";
}

function ape_fetch_records(string $search = '', ?int $limit = null, ?string $scheduleDate = null, ?int $scheduleBatchId = null): array
{
    $sql = ape_record_select_sql();
    $params = [];
    $conditions = [];
    if ($scheduleBatchId !== null) {
        $conditions[] = "batch.batch_id = ? AND batch.status = 'Scheduled'";
        $params[] = $scheduleBatchId;
    } elseif ($scheduleDate !== null) {
        $conditions[] = "((batch.schedule_date = ? AND batch.status = 'Scheduled') OR (ar.follow_up_due_date = ? AND ar.follow_up_required = 1 AND ar.workflow_status <> 'Cleared' AND ar.clearance_status <> 'Cleared'))";
        $params[] = $scheduleDate;
        $params[] = $scheduleDate;
    }

    if ($search !== '') {
        $historyProgramSearch = ape_school_year_history_available()
            ? 'OR history_program.program_code LIKE ?'
            : '';
        $conditions[] = "(
               p.first_name LIKE ?
               OR p.last_name LIKE ?
               OR p.id_number LIKE ?
               OR pr.program_code LIKE ?
               {$historyProgramSearch}
               OR ed.department_code LIKE ?
               OR EXISTS (
                    SELECT 1 FROM ape_documents search_document
                    WHERE search_document.ape_id = ar.ape_id
                      AND search_document.document_type LIKE ?
               )
            )";
        $term = '%' . $search . '%';
        $params = array_merge($params, array_fill(0, ape_school_year_history_available() ? 7 : 6, $term));
    }
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= " ORDER BY ar.updated_at DESC, ar.created_at DESC";
    if ($limit !== null) {
        $limit = max(1, $limit);
        $sql .= " LIMIT {$limit}";
    }
    $stmt = auth_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function ape_fetch_record(int $apeId): ?array
{
    $stmt = auth_db()->prepare(ape_record_select_sql() . ' WHERE ar.ape_id = ? LIMIT 1');
    $stmt->execute([$apeId]);
    return $stmt->fetch() ?: null;
}

function ape_fetch_patient_record(int $patientId): ?array
{
    $stmt = auth_db()->prepare(ape_record_select_sql() . '
        WHERE ar.patient_id = ?
        ORDER BY ar.updated_at DESC, ar.created_at DESC
        LIMIT 1
    ');
    $stmt->execute([$patientId]);
    return $stmt->fetch() ?: null;
}

function ape_fetch_patient_records(int $patientId): array
{
    $stmt = auth_db()->prepare(ape_record_select_sql() . '
        WHERE ar.patient_id = ?
        ORDER BY ar.exam_date DESC, ar.created_at DESC
    ');
    $stmt->execute([$patientId]);
    return $stmt->fetchAll();
}

function ape_requirements_for_record(int $apeId): array
{
    $stmt = auth_db()->prepare("
        SELECT r.*, COALESCE(r.upload_group, 'initial') AS upload_group,
               TRIM(CONCAT_WS(' ', checker.first_name, checker.middle_name, checker.last_name)) AS checked_by_name
        FROM ape_requirements r
        LEFT JOIN people checker ON checker.id = r.checked_by_person_id
        WHERE r.ape_id = ?
        ORDER BY r.requirement_id ASC
    ");
    $stmt->execute([$apeId]);
    $requirements = $stmt->fetchAll();
    $latestByType = [];
    foreach (ape_documents_for_record($apeId) as $document) {
        $type = (string) ($document['document_type'] ?? '');
        if ($type !== '' && !isset($latestByType[$type])) {
            $latestByType[$type] = $document;
        }
    }
    foreach ($requirements as &$requirement) {
        $requirement['_latest_document'] = $latestByType[(string) $requirement['requirement_name']] ?? null;
    }
    unset($requirement);
    return $requirements;
}

function ape_documents_for_record(int $apeId): array
{
    $stmt = auth_db()->prepare("
        SELECT d.*,
               TRIM(CONCAT_WS(' ', uploader.first_name, uploader.middle_name, uploader.last_name)) AS uploaded_by_name,
               TRIM(CONCAT_WS(' ', verifier.first_name, verifier.middle_name, verifier.last_name)) AS verified_by_name
        FROM ape_documents d
        LEFT JOIN people uploader ON uploader.id = d.uploaded_by_person_id
        LEFT JOIN people verifier ON verifier.id = d.verified_by_person_id
        WHERE d.ape_id = ?
        ORDER BY d.uploaded_at DESC, d.document_id DESC
    ");
    $stmt->execute([$apeId]);
    return $stmt->fetchAll();
}

function ape_findings_for_record(int $apeId): array
{
    $stmt = auth_db()->prepare("
        SELECT f.*, TRIM(CONCAT_WS(' ', recorder.first_name, recorder.middle_name, recorder.last_name)) AS recorded_by_name
        FROM ape_findings f
        LEFT JOIN people recorder ON recorder.id = f.recorded_by_person_id
        WHERE f.ape_id = ?
        ORDER BY f.recorded_at DESC, f.finding_id DESC
    ");
    $stmt->execute([$apeId]);
    return $stmt->fetchAll();
}

function ape_activities_for_patient_record(int $apeId, int $patientId, int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    $stmt = auth_db()->prepare("
        SELECT l.*, l.action AS action_label,
               TRIM(CONCAT_WS(' ', actor.first_name, actor.middle_name, actor.last_name)) AS user_name
        FROM ape_activity_logs l
        INNER JOIN ape_records ar ON ar.ape_id = l.ape_id AND ar.patient_id = ?
        LEFT JOIN people actor ON actor.id = l.performed_by_person_id
        WHERE l.ape_id = ?
        ORDER BY l.created_at DESC, l.activity_id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$patientId, $apeId]);
    return $stmt->fetchAll();
}

function ape_default_requirements(): array
{
    return ape_required_documents();
}

function ape_seed_default_requirements(int $apeId, string $status = 'Missing'): void
{
    $stmt = auth_db()->prepare("
        INSERT IGNORE INTO ape_requirements (ape_id, requirement_name, status, upload_group)
        VALUES (?, ?, ?, 'initial')
    ");
    foreach (ape_default_requirements() as $requirement) {
        $stmt->execute([$apeId, $requirement, $status]);
    }
}

function ape_log_activity(int $apeRecordId, ?int $personId, string $actionLabel, ?string $notes = null): void
{
    $stmt = auth_db()->prepare('INSERT INTO ape_activity_logs (ape_id, performed_by_person_id, action, notes) VALUES (?, ?, ?, ?)');
    $stmt->execute([$apeRecordId, $personId ?: null, $actionLabel, $notes]);
    audit_log_event('ape', $actionLabel, $personId ?: null, 'staff', 'ape_record', $apeRecordId, ['notes' => $notes]);
}

function ape_document_storage_root(): string
{
    return dirname(__DIR__, 2) . '/storage/documents/ape';
}

function ape_document_relative_name(string $storedPath): ?string
{
    $normalizedPath = str_replace('\\', '/', trim($storedPath));
    if ($normalizedPath === '' || str_contains($normalizedPath, "\0") || str_contains($normalizedPath, '..')) {
        return null;
    }

    $relativeName = null;
    foreach (['/storage/documents/ape/', '/public/uploads/ape/', '/uploads/ape/'] as $marker) {
        $position = strripos('/' . ltrim($normalizedPath, '/'), $marker);
        if ($position !== false) {
            $relativeName = substr('/' . ltrim($normalizedPath, '/'), $position + strlen($marker));
            break;
        }
    }
    if ($relativeName === null && basename($normalizedPath) === $normalizedPath) {
        $relativeName = $normalizedPath;
    }

    if (
        $relativeName === null
        || $relativeName === ''
        || basename($relativeName) !== $relativeName
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $relativeName)
    ) {
        return null;
    }

    return $relativeName;
}

function ape_document_lookup(string $storedPath): array
{
    $relativeName = ape_document_relative_name($storedPath);
    if ($relativeName === null) {
        return ['status' => 'invalid_path', 'relative_name' => null, 'absolute_path' => null];
    }

    $candidates = [
        ape_document_storage_root() . DIRECTORY_SEPARATOR . $relativeName,
        dirname(__DIR__, 2) . '/public/uploads/ape/' . $relativeName,
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            return ['status' => 'available', 'relative_name' => $relativeName, 'absolute_path' => $candidate];
        }
    }

    return ['status' => 'missing_file', 'relative_name' => $relativeName, 'absolute_path' => null];
}

function ape_document_absolute_path(string $storedPath): ?string
{
    return ape_document_lookup($storedPath)['absolute_path'];
}

function ape_document_download_name(string $idNumber, string $documentType, string $originalFilename): string
{
    $baseName = ape_document_name_base($idNumber, $documentType);
    $extension = ape_document_safe_extension($originalFilename);

    return $baseName . $extension;
}

function ape_document_name_base(string $idNumber, string $documentType): string
{
    $safeId = preg_replace('/[^A-Za-z0-9-]+/', '-', trim($idNumber)) ?: 'student';
    $safeType = preg_replace('/[^A-Za-z0-9]+/', '-', trim($documentType)) ?: 'document';
    $safeType = trim($safeType, '-');

    return trim($safeId, '-') . '_' . $safeType;
}

function ape_document_safe_extension(string $filename): string
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return preg_match('/^[a-z0-9]{1,10}$/', $extension) ? '.' . $extension : '';
}

function ape_document_storage_name(string $idNumber, string $documentType, string $originalFilename, ?string $timestamp = null, ?string $token = null): string
{
    $timestamp ??= gmdate('Ymd-His');
    $token ??= bin2hex(random_bytes(4));
    if (!preg_match('/^\d{8}-\d{6}$/', $timestamp) || !preg_match('/^[a-f0-9]{8}$/', $token)) {
        throw new InvalidArgumentException('Invalid APE document storage identifier.');
    }

    return ape_document_name_base($idNumber, $documentType)
        . '_' . $timestamp
        . '_' . $token
        . ape_document_safe_extension($originalFilename);
}

/**
 * Prevent workflow transitions from approving database rows whose files are
 * no longer present in protected or legacy storage.
 *
 * Only the latest upload for each document type is checked. Older replaced
 * uploads are historical records and must not block a valid current upload.
 */
function ape_assert_documents_available(PDO $db, int $apeId, array $documentIds = [], bool $excludeClearance = false): void
{
    $params = [$apeId];
    $conditions = [
        'd.ape_id = ?',
        'd.document_id = (
            SELECT MAX(latest.document_id)
            FROM ape_documents latest
            WHERE latest.ape_id = d.ape_id
              AND latest.document_type = d.document_type
        )',
    ];
    if ($excludeClearance) {
        $conditions[] = "d.document_type <> 'Clearance'";
    }
    if ($documentIds !== []) {
        $documentIds = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn(int $id): bool => $id > 0)));
        if ($documentIds === []) {
            throw new InvalidArgumentException('Select at least one APE document.');
        }
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $conditions[] = "d.document_id IN ({$placeholders})";
        $params = array_merge($params, $documentIds);
    }

    $stmt = $db->prepare('SELECT d.document_id, d.document_type, d.file_path FROM ape_documents d WHERE ' . implode(' AND ', $conditions));
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $missing = [];
    foreach ($rows as $row) {
        if (ape_document_lookup((string) $row['file_path'])['status'] !== 'available') {
            $missing[] = (string) $row['document_type'];
        }
    }
    if ($missing !== []) {
        throw new RuntimeException('Cannot approve APE documents because these files are missing from clinic storage: ' . implode(', ', array_unique($missing)) . '. Restore the document volume or request a new upload.');
    }
}

function ape_store_uploaded_file(array $file, string $idNumber, string $documentType): array
{
    if (empty($file['name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Choose a valid PDF or image to upload.');
    }
    if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('APE documents must not exceed 2 MB per file.');
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $allowedMimeTypes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];
    if (!isset($allowedMimeTypes[$extension])) {
        throw new InvalidArgumentException('APE documents must be PDF, JPG, JPEG, or PNG files.');
    }
    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    $detectedMimeType = is_file($temporaryPath) ? (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath) : false;
    if ($detectedMimeType !== $allowedMimeTypes[$extension]) {
        throw new InvalidArgumentException('APE documents must be valid PDF, JPG, JPEG, or PNG files.');
    }

    $uploadDir = ape_document_storage_root() . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('The protected APE document folder could not be created.');
    }

    $filename = ape_document_storage_name((string) $idNumber, $documentType, (string) $file['name']);
    if (!move_uploaded_file($temporaryPath, $uploadDir . $filename)) {
        throw new RuntimeException('The APE document could not be saved.');
    }

    return [
        'original_filename' => basename((string) $file['name']),
        'file_path' => 'storage/documents/ape/' . $filename,
        'absolute_path' => $uploadDir . $filename,
    ];
}
