$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$phpPath = 'C:\xampp\php\php.exe'
$runnerPath = Join-Path $projectRoot 'scripts\backup\run_backup.php'
$taskName = 'CLINiQ Daily Backup'

if (-not (Test-Path -LiteralPath $phpPath)) {
    throw "PHP was not found at $phpPath"
}
if (-not (Test-Path -LiteralPath $runnerPath)) {
    throw "Backup runner was not found at $runnerPath"
}

$action = New-ScheduledTaskAction -Execute $phpPath -Argument ('"' + $runnerPath + '" --scheduled') -WorkingDirectory $projectRoot
$triggers = @(
    New-ScheduledTaskTrigger -Daily -At '8:00 AM'
    New-ScheduledTaskTrigger -AtLogOn
)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2)

try {
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $triggers -Settings $settings -Description 'Creates the once-daily internal CLINiQ database and document backup at 8:00 AM or the next available startup.' -Force | Out-Null
    Write-Output "Registered scheduled task with missed-start and logon triggers: $taskName"
} catch {
    $taskCommand = "$phpPath $runnerPath --scheduled"
    & schtasks.exe /Create /TN $taskName /TR $taskCommand /SC DAILY /ST 08:00 /RL LIMITED /F
    if ($LASTEXITCODE -ne 0) {
        throw 'Windows denied both administrator and current-user task registration methods.'
    }
    Write-Output "Registered current-user daily task: $taskName. Electron provides the missed-start catch-up."
}
