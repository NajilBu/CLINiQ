[CmdletBinding()]
param(
    [ValidateRange(1024, 65535)]
    [int]$Port = 8081,
    [switch]$NoBuild
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$dockerEnv = Join-Path $projectRoot 'docker\.env'
$dockerEnvExample = Join-Path $projectRoot 'docker\.env.example'
$composeFile = Join-Path $projectRoot 'compose.yaml'
$developmentComposeFile = Join-Path $projectRoot 'compose.dev.yaml'

function Get-CliniqDevelopmentProjectName {
    param([string]$Root)

    $folder = (Split-Path -Leaf $Root).ToLowerInvariant() -replace '[^a-z0-9_-]', '-'
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($Root.ToLowerInvariant())
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $hash = [Convert]::ToHexString($sha.ComputeHash($bytes)).Substring(0, 8).ToLowerInvariant()
    } finally {
        $sha.Dispose()
    }
    return "cliniq-dev-$folder-$hash"
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker was not found. Install Docker Desktop, start it, and run this script again.'
}

& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw 'Docker Desktop is not running. Start Docker Desktop and run this script again.'
}

if (-not (Test-Path -LiteralPath $dockerEnv)) {
    if (-not (Test-Path -LiteralPath $dockerEnvExample)) {
        throw "Docker environment template not found: $dockerEnvExample"
    }

    Copy-Item -LiteralPath $dockerEnvExample -Destination $dockerEnv
    Write-Output 'Created docker/.env from docker/.env.example.'
    Write-Warning 'Replace all placeholder secrets in docker/.env before using non-test data.'
}

$env:COMPOSE_PROJECT_NAME = Get-CliniqDevelopmentProjectName -Root $projectRoot
$env:CLINIQ_DEV_PORT = $Port.ToString()
$composeArguments = @('-f', $composeFile, '-f', $developmentComposeFile)

& docker compose @composeArguments config --quiet
if ($LASTEXITCODE -ne 0) {
    throw 'The Docker development configuration is invalid.'
}

$upArguments = @('up', '-d')
if (-not $NoBuild) {
    $upArguments += '--build'
}
$upArguments += @('database', 'app')

& docker compose @composeArguments @upArguments
if ($LASTEXITCODE -ne 0) {
    throw 'The CLINiQ development services did not start.'
}

Write-Output "Development project: $env:COMPOSE_PROJECT_NAME"
Write-Output "Clinic UI: http://localhost:$Port/public/"
Write-Output "Patient portal: http://localhost:$Port/patient-portal/"
Write-Output 'Database migrations run automatically before Apache starts.'
