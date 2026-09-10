<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

const CLINIQ_PROFILE_PHOTO_MAX_BYTES = 5 * 1024 * 1024;
const CLINIQ_PROFILE_PHOTO_DIRECTORY = 'uploads/profile-photos';

function profile_photo_path_for_person(int $personId): ?string
{
    if ($personId <= 0) {
        return null;
    }

    $stmt = auth_db()->prepare('SELECT profile_photo_path FROM people WHERE id = ? LIMIT 1');
    $stmt->execute([$personId]);
    return profile_photo_normalize_path($stmt->fetchColumn());
}

function profile_photo_normalize_path(mixed $path): ?string
{
    $path = ltrim(str_replace('\\', '/', trim((string) $path)), '/');
    return str_starts_with($path, CLINIQ_PROFILE_PHOTO_DIRECTORY . '/') ? $path : null;
}

function save_profile_photo_upload(array $upload, int $personId): string
{
    if ($personId <= 0) {
        throw new InvalidArgumentException('Your account could not be identified. Please sign in again.');
    }

    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Choose a JPG, PNG, or WebP photo.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The photo upload did not complete. Please try again.');
    }

    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    $size = (int) ($upload['size'] ?? 0);
    if ($size <= 0 || $size > CLINIQ_PROFILE_PHOTO_MAX_BYTES) {
        throw new InvalidArgumentException('The profile photo must be no larger than 5 MB.');
    }
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new InvalidArgumentException('The selected file is not a valid uploaded photo.');
    }

    $imageInfo = @getimagesize($temporaryPath);
    if ($imageInfo === false || ($imageInfo[0] ?? 0) < 32 || ($imageInfo[1] ?? 0) < 32) {
        throw new InvalidArgumentException('Choose a valid image that is at least 32 by 32 pixels.');
    }
    if (($imageInfo[0] ?? 0) > 6000 || ($imageInfo[1] ?? 0) > 6000) {
        throw new InvalidArgumentException('The image dimensions are too large. Use a photo smaller than 6000 by 6000 pixels.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($temporaryPath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime])) {
        throw new InvalidArgumentException('Only JPG, PNG, and WebP profile photos are allowed.');
    }

    $projectRoot = dirname(__DIR__, 2);
    $relativeDirectory = CLINIQ_PROFILE_PHOTO_DIRECTORY;
    $absoluteDirectory = $projectRoot . '/public/' . $relativeDirectory;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
        throw new RuntimeException('The profile-photo storage directory could not be created.');
    }

    $filename = sprintf('person-%d-%s.%s', $personId, bin2hex(random_bytes(12)), $extensions[$mime]);
    $relativePath = $relativeDirectory . '/' . $filename;
    $absolutePath = $absoluteDirectory . '/' . $filename;
    if (!move_uploaded_file($temporaryPath, $absolutePath)) {
        throw new RuntimeException('The profile photo could not be saved.');
    }
    @chmod($absolutePath, 0640);

    $db = auth_db();
    $oldPath = null;
    try {
        $db->beginTransaction();
        $select = $db->prepare('SELECT profile_photo_path FROM people WHERE id = ? LIMIT 1 FOR UPDATE');
        $select->execute([$personId]);
        $row = $select->fetch();
        if (!$row) {
            throw new RuntimeException('The account profile no longer exists.');
        }
        $oldPath = profile_photo_normalize_path($row['profile_photo_path'] ?? null);
        $update = $db->prepare('UPDATE people SET profile_photo_path = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([$relativePath, $personId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        @unlink($absolutePath);
        throw $e;
    }

    if ($oldPath !== null && $oldPath !== $relativePath) {
        $oldAbsolutePath = $projectRoot . '/public/' . $oldPath;
        $allowedRoot = realpath($absoluteDirectory);
        $oldDirectory = realpath(dirname($oldAbsolutePath));
        if ($allowedRoot !== false && $oldDirectory === $allowedRoot && is_file($oldAbsolutePath)) {
            @unlink($oldAbsolutePath);
        }
    }

    return $relativePath;
}
