<?php

declare(strict_types=1);

/*
 * Without this file, Laravel uses its vendor default: allowed_origins => ['*'],
 * which leaves the API open to any origin. It is set explicitly even though it
 * almost repeats a default: it is the only way for the posture to be reviewable.
 *
 * In practice the browser only talks to the Next BFF and the BFF talks to the
 * API from the server, so this list only covers local development.
 */
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-Request-Id'],
    'exposed_headers' => ['Retry-After'],
    'max_age' => 3600,
    // There are no cookies between browser and API: the BFF injects the credential.
    'supports_credentials' => false,
];
