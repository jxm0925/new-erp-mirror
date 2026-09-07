<?php

return [
    // No built-in secret: every environment must explicitly share its own key
    // with the trusted legacy ERP ticket issuer.
    'shared_secret' => env('SSO_SHARED_SECRET', ''),
    'ticket_ttl' => (int) env('SSO_TICKET_TTL', 300),
];
