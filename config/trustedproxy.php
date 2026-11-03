<?php

use App\Support\TrustedProxies;

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Laravel's TrustProxies middleware reads "trustedproxy.proxies" on every request. The default is
| to trust nobody: the client IP, the scheme, the host and the port then come from the connection
| itself, so a client cannot choose them with X-Forwarded-* headers.
|
| TRUSTED_PROXIES is a comma-separated list of the reverse proxies in front of the application, as
| IP addresses or CIDR ranges (for example "10.0.0.5,172.16.0.0/12"). Every spelling of "trust
| everyone" is refused (see App\Support\TrustedProxies): `*`, `REMOTE_ADDR`, `0.0.0.0/0`, `::/0`,
| the unspecified addresses and ranges wider than IPv4 /8 or IPv6 /7. A refused entry is dropped, so
| the failure is "trusted too little", and "bizflow:preflight" reports it. The one deliberate
| exception is `*` together with TRUSTED_PROXIES_ALLOW_ALL=true, for an application reachable only
| through its proxy; the demo and production preflight checks reject that too.
|
*/

$parsed = TrustedProxies::parse(
    (string) env('TRUSTED_PROXIES', ''),
    filter_var(env('TRUSTED_PROXIES_ALLOW_ALL', false), FILTER_VALIDATE_BOOLEAN),
);

return [

    'proxies' => $parsed['proxies'],

    'rejected' => $parsed['rejected'],

    'allow_all' => $parsed['allow_all'],

];
