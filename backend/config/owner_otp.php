<?php

return [
    'enabled' => (bool) env('OWNER_OTP_ENABLED', false),
    'owners' => [env('OWNER_OTP_FIRST_EMAIL'), env('OWNER_OTP_SECOND_EMAIL')],
    'mailer' => env('OWNER_OTP_MAILER', 'smtp'),
    'expires_minutes' => 5,
    'max_attempts' => 5,
    'resend_seconds' => 60,
    'send_window_minutes' => 15,
    'max_sends' => 3,
];
