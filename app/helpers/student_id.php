<?php

const STUDENT_ID_FORMAT_LABEL = '00-00000';
const STUDENT_ID_REGEX = '/^\d{2}-\d{5}$/';
const STUDENT_ID_HTML_PATTERN = '\d{2}-\d{5}';

function is_valid_student_id(?string $studentId): bool
{
    return preg_match(STUDENT_ID_REGEX, trim((string) $studentId)) === 1;
}

function student_id_format_message(string $subject = 'Student ID'): string
{
    return $subject . ' must use the format ' . STUDENT_ID_FORMAT_LABEL . '.';
}
