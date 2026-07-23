<?php

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    // TrustProxies reads this configuration automatically. Keep null unless
    // the deployment provides explicit proxy IP addresses or CIDR ranges.
    'proxies' => $proxies === ''
        ? null
        : array_values(array_filter(array_map('trim', explode(',', $proxies)))),
];
