<?php

$recaptchaSiteKey = getenv('RECAPTCHA_SITE_KEY') ?: '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI';
$recaptchaSecretKey = getenv('RECAPTCHA_SECRET_KEY') ?: '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe';

return [
    'RECAPTCHA_SITE_KEY' => $recaptchaSiteKey,
    'RECAPTCHA_SECRET_KEY' => $recaptchaSecretKey,
    'recaptcha' => $recaptchaSiteKey,
];
