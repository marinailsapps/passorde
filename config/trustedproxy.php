<?php

return [

    /*
    | Proxies whose X-Forwarded-* headers are trusted (comma-separated IPs or
    | CIDRs). The default is Docker's address pool for compose networks, so
    | Caddy in front of the app is trusted and $request->ip() is the phone's
    | address, not the proxy's. In production set it to Caddy's network.
    */

    'proxies' => env('TRUSTED_PROXIES') ?: '172.16.0.0/12',

];
