[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$volumeNames = @(
    'cliniq_cliniq_database',
    'cliniq_cliniq_documents',
    'cliniq_cliniq_uploads',
    'cliniq_cliniq_backups',
    'cliniq_cliniq_sessions'
)

$existingVolumes = @(& docker volume ls --format '{{.Name}}')
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to list Docker volumes.'
}

foreach ($volumeName in $volumeNames) {
    if ($existingVolumes -contains $volumeName) {
        Write-Output "Verified Docker volume: $volumeName"
        continue
    }
    & docker volume create $volumeName
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to create Docker volume: $volumeName"
    }
    Write-Output "Created protected Docker volume: $volumeName"
}
