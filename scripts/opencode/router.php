<?php

/*
 * The workstation's OpenAI-compatible text endpoint (App\Support\Caster\OpencodeGateway), served by `php -S` from
 * `php artisan caster:text-gateway`. POST /v1/chat/completions writes with the local opencode CLI; GET /health answers.
 */

use App\Support\Caster\OpencodeGateway;

require __DIR__.'/../../vendor/autoload.php';

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
header('Content-Type: application/json');

if ($method === 'GET' && $path === '/health') {
    echo '{"ok":true}';

    return;
}

if ($method !== 'POST' || ! str_ends_with($path, '/chat/completions')) {
    http_response_code(404);
    echo json_encode(['error' => ['type' => 'not_found', 'message' => $path]]);

    return;
}

$request = json_decode((string) file_get_contents('php://input'), true);
[$status, $body] = OpencodeGateway::fromEnvironment()->handle(is_array($request) ? $request : []);

http_response_code($status);
echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
