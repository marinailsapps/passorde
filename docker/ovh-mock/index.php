<?php

/*
| Stand-in for the OVH SMS API, for dev walkthroughs and tools/prod-local only
| (compose profile "mock"; OVH_ENDPOINT=http://ovh-mock:8080/ovh/1.0). It answers
| GET /auth/time and POST /sms/{service}/jobs, checks that the request carries
| OVH's signed headers, and prints the SMS to the container log instead of
| sending it. A consumer key starting with "BROKEN" is refused, to rehearse a
| failed SMS. The Evoliz mock is Passorde's own (vendor/marinailsapps/passorde-connect/connectors/evoliz/mock).
*/

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$input = json_decode((string) file_get_contents('php://input'), true) ?? [];

if ($method === 'GET' && $path === '/ovh/1.0/auth/time') {
    header('Content-Type: application/json');
    echo time();
    exit;
}

if ($method === 'POST' && preg_match('#^/ovh/1\.0/sms/[^/]+/jobs$#', $path)) {
    $signed = isset($_SERVER['HTTP_X_OVH_APPLICATION'], $_SERVER['HTTP_X_OVH_TIMESTAMP'])
        && preg_match('/^\$1\$[0-9a-f]{40}$/', $_SERVER['HTTP_X_OVH_SIGNATURE'] ?? '') === 1;
    if (! $signed || str_starts_with($_SERVER['HTTP_X_OVH_CONSUMER'] ?? '', 'BROKEN')) {
        respond(403, ['class' => 'Client::Forbidden', 'message' => 'Invalid signature']);
    }
    file_put_contents('php://stderr', sprintf("[ovh-mock] SMS to %s: %s\n", implode(', ', $input['receivers'] ?? []), $input['message'] ?? ''));
    respond(200, ['totalCreditsRemoved' => 1, 'invalidReceivers' => [], 'ids' => [random_int(100000000, 999999999)], 'validReceivers' => $input['receivers'] ?? []]);
}

respond(404, ['class' => 'Client::NotFound', 'message' => "Mock has no route for {$method} {$path}"]);
