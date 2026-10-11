<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/public/referrals/create.php');
if ($source === false) {
    throw new RuntimeException('Unable to read the referral creation route.');
}

if (!str_contains($source, 'data-no-ajax="true" data-loading-after-confirm="true"')
    || !str_contains($source, 'name="submission_token"')
    || !str_contains($source, "unset(\$_SESSION['referral_submission_tokens'][\$submissionToken]);")
    || !str_contains($source, 'This referral form was already submitted.')) {
    throw new RuntimeException('Referral creation must use a native, one-time submission flow.');
}

echo "Referral creation submission checks passed. No database writes.\n";
