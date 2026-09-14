[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$scriptPath = Join-Path $projectRoot 'scripts\reliability\start_cliniq.ps1'
$taskName = 'CLINiQ Server Startup'

if (-not (Test-Path -LiteralPath $scriptPath)) {
    throw "Startup script was not found: $scriptPath"
}

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`""
$trigger = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 15)

try {
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Description 'Starts Docker Desktop, verifies protected CLINiQ volumes, then starts CLINiQ after the clinic server user logs on.' -Force -ErrorAction Stop | Out-Null
    Write-Output "Registered startup task: $taskName"
} catch {
    $taskCommand = "powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`""
    & schtasks.exe /Create /TN $taskName /TR $taskCommand /SC ONLOGON /RL LIMITED /F
    if ($LASTEXITCODE -ne 0) {
        throw 'Windows denied both administrator and current-user startup task registration methods.'
    }
    Write-Output "Registered current-user startup task: $taskName"
}
