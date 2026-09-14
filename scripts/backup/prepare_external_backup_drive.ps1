[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[A-Za-z]:$')]
    [string]$DriveLetter
)

$ErrorActionPreference = 'Stop'
$driveRoot = "$($DriveLetter.ToUpper())\"
$driveLetterOnly = $DriveLetter.TrimEnd(':')
$volume = Get-Volume -DriveLetter $driveLetterOnly

if ($driveRoot -eq 'C:\') {
    throw 'The CLINiQ external backup destination must not be the server C: drive.'
}

try {
    $bitLocker = Get-BitLockerVolume -MountPoint $driveRoot
} catch {
    throw "Unable to confirm BitLocker on $driveRoot. Enable and unlock BitLocker before preparing this drive."
}

if ($bitLocker.ProtectionStatus -ne 'On' -or $bitLocker.VolumeStatus -ne 'FullyEncrypted') {
    throw "$driveRoot must be fully encrypted and protected by BitLocker before it can store CLINiQ backups."
}

$backupRoot = Join-Path $driveRoot 'CLINiQ-Backups'
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
$marker = [ordered]@{
    format_version = 1
    purpose = 'CLINiQ external backup drive'
    prepared_at = (Get-Date).ToString('o')
    volume_label = $volume.FileSystemLabel
    volume_serial = $volume.UniqueId
}
$markerPath = Join-Path $backupRoot '.cliniq-external-backup-drive.json'
$marker | ConvertTo-Json | Set-Content -LiteralPath $markerPath -Encoding utf8 -NoNewline

Write-Output "Prepared $backupRoot for encrypted CLINiQ backups. Configure BACKUP_EXTERNAL_HOST_PATH=$backupRoot in the project-root .env."
