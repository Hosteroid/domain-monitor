<?php

/**
 * Routes of the token API, below /api/v1.
 *
 * Each row: method, path, handler, whether it changes anything (needs a write token), and what it
 * counts as against the rate limit (a call that starts a WHOIS lookup counts as ten).
 * A {name} part of a path is handed to the controller URL-decoded.
 * HEAD is answered by the GET row and OPTIONS by the kernel.
 */

use App\Controllers\ApiController;
use App\Controllers\ApiDomainController;

return [
    ['GET', '', [ApiController::class, 'index'], false, 1],
    ['GET', '/stats', [ApiController::class, 'stats'], false, 1],
    ['GET', '/ssl', [ApiController::class, 'certificates'], false, 1],
    ['GET', '/groups', [ApiController::class, 'groups'], false, 1],
    ['GET', '/tags', [ApiController::class, 'tags'], false, 1],

    ['GET', '/domains', [ApiDomainController::class, 'index'], false, 1],
    ['POST', '/domains', [ApiDomainController::class, 'store'], true, 10],
    ['GET', '/domains/{domain}', [ApiDomainController::class, 'show'], false, 1],
    ['GET', '/domains/{domain}/dns', [ApiDomainController::class, 'dns'], false, 1],
    ['PATCH', '/domains/{domain}', [ApiDomainController::class, 'update'], true, 1],
    ['DELETE', '/domains/{domain}', [ApiDomainController::class, 'destroy'], true, 1],
    ['POST', '/domains/{domain}/refresh', [ApiDomainController::class, 'refresh'], true, 10],

    // The same two calls as POST, for hosts whose firewall lets only GET and POST through
    ['POST', '/domains/{domain}', [ApiDomainController::class, 'update'], true, 1],
    ['POST', '/domains/{domain}/delete', [ApiDomainController::class, 'destroy'], true, 1],
];
