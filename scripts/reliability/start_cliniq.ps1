[CmdletBinding()]
param(
    [int]$DockerWaitSeconds = 300
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$dockerDesktop = Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'

& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    if (-not (Test-Path -LiteralPath $dockerDesktop)) {
        throw 'Docker Desktop was not found. Install Docker Desktop before configuring CLINiQ startup.'
    }
    Start-Process -FilePath $dockerDesktop -WindowStyle Hidden
}

$deadline = (Get-Date).AddSeconds($DockerWaitSeconds)
do {
    & docker info *> $null
    if ($LASTEXITCODE -eq 0) {
        break
    }
    Start-Sleep -Seconds 5
} while ((Get-Date) -lt $deadline)

if ($LASTEXITCODE -ne 0) {
    throw "Docker Desktop did not become ready within $DockerWaitSeconds seconds."
}

& "$PSScriptRoot\ensure_cliniq_volumes.ps1"
& docker compose -f (Join-Path $projectRoot 'compose.yaml') up -d
if ($LASTEXITCODE -ne 0) {
    throw 'CLINiQ services did not start.'
}
Write-Output 'CLINiQ application, database, and backup scheduler are running.'
