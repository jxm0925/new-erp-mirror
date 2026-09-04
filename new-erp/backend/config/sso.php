<?php

return [
    'shared_secret' => env('SSO_SHARED_SECRET', 'jiantan-training-sso-20260623-9f2c1d7b'),
    'ticket_ttl' => (int) env('SSO_TICKET_TTL', 300),
];
