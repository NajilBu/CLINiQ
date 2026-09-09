<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/SystemSettings.php';

$defaults = default_ape_required_documents();
if ($defaults !== [
    'Lab Request Form',
    'UHS Consent Form',
    'UHS Medical Record',
    'UHS Dental Record',
    'Referral Form',
]) {
    throw new RuntimeException('The initial required APE documents changed unexpectedly.');
}

$normalized = normalize_ape_required_documents(['  Lab   Request Form ', '', 'TB Certificate']);
if ($normalized !== ['Lab Request Form', 'TB Certificate']) {
    throw new RuntimeException('APE required document names must be trimmed, normalized, and kept in order.');
}

try {
    normalize_ape_required_documents(['Referral Form', 'referral form']);
    throw new RuntimeException('Duplicate APE required documents must be rejected.');
} catch (InvalidArgumentException $e) {
    if (!str_contains($e->getMessage(), 'listed more than once')) {
        throw $e;
    }
}

try {
    normalize_ape_required_documents(['']);
    throw new RuntimeException('An empty required document list must be rejected.');
} catch (InvalidArgumentException $e) {
    if (!str_contains($e->getMessage(), 'at least one')) {
        throw $e;
    }
}

$workflow = file_get_contents(dirname(__DIR__) . '/app/services/ApeWorkflow.php');
if (!str_contains($workflow, 'return ape_required_documents();')) {
    throw new RuntimeException('New patient APE records must use the configured required document list.');
}

$cycleService = file_get_contents(dirname(__DIR__) . '/app/services/ApeCycleService.php');
if (!str_contains($cycleService, 'foreach (ape_default_requirements() as $requirementName)')) {
    throw new RuntimeException('New APE cycles must use the configured required document list.');
}
if (!str_contains($cycleService, 'record.requirements_saved_at IS NULL')) {
    throw new RuntimeException('Settings synchronization must be limited to unlocked APE checklists.');
}
if (!str_contains($cycleService, 'requirement.requirement_name IN ({$placeholders})')) {
    throw new RuntimeException('Settings synchronization must remove only names from the previous global template.');
}

$settingsPage = file_get_contents(dirname(__DIR__) . '/public/settings/index.php');
foreach (['save_ape_required_documents', 'ape_required_documents[]', 'addApeRequiredDocument'] as $expected) {
    if (!str_contains($settingsPage, $expected)) {
        throw new RuntimeException("The APE settings interface is missing {$expected}.");
    }
}

echo "APE required documents settings test passed. No database writes.\n";
