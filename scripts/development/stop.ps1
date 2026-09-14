[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$composeFile = Join-Path $projectRoot 'compose.yaml'
$developmentComposeFile = Join-Path $projectRoot 'compose.dev.yaml'

$folder = (Split-Path -Leaf $projectRoot).ToLowerInvariant() -replace '[^a-z0-9_-]', '-'
$bytes = [System.Text.Encoding]::UTF8.GetBytes($projectRoot.ToLowerInvariant())
$sha = [System.Security.Cryptography.SHA256]::Create()
try {
    $hash = [Convert]::ToHexString($sha.ComputeHash($bytes)).Substring(0, 8).ToLowerInvariant()
} finally {
    $sha.Dispose()
}

$env:COMPOSE_PROJECT_NAME = "cliniq-dev-$folder-$hash"
& docker compose -f $composeFile -f $developmentComposeFile down
if ($LASTEXITCODE -ne 0) {
    throw 'The CLINiQ development services did not stop cleanly.'
}

Write-Output 'Development containers stopped. Database and uploaded-file volumes were preserved.'
