<?php

const ID_NUMBER_FORMAT_LABEL = 'up to 50 letters, numbers, spaces, or - / _';
const ID_NUMBER_REGEX = '/^[A-Za-z0-9][A-Za-z0-9 _\/-]{0,49}$/';
const ID_NUMBER_HTML_PATTERN = '[A-Za-z0-9][A-Za-z0-9 _\/-]{0,49}';

function is_valid_id_number(?string $idNumber): bool
{
    return preg_match(ID_NUMBER_REGEX, trim((string) $idNumber)) === 1;
}

function id_number_validation_message(string $subject = 'ID Number'): string
{
    return $subject . ' is required and must contain ' . ID_NUMBER_FORMAT_LABEL . '.';
}
