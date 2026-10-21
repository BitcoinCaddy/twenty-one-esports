<?php

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Browser tests only. The in-process server of pest-plugin-browser
 * (Pest\Browser\Drivers\LaravelHttpServer::handleRequest) builds the request
 * from the raw body and passes no files (`[], // @TODO files...`), so a
 * Livewire upload arrives with an empty `files` field and _finishUpload()
 * dies on `$tmpPath[0]` (measured: 500 on /livewire-…/update). PHP's own
 * multipart parsing is what this stands in for: it reads the raw
 * multipart/form-data body into the request's fields and files, and leaves
 * any request that already carries properly nested files alone.
 *
 * pest-plugin-browser 5.1 parses the body itself, but files under a list name
 * (`files[]`, what Livewire sends) arrive keyed with the literal brackets
 * (`"files[]"`) instead of nested under `files`, so `$request->file('files')`
 * is null and the upload dies on `$tmpPath[0]` as before (measured with
 * 5.1.2, not with 5.0.1). Such a request is parsed again from the raw body.
 *
 * Registered per test as a global middleware (tests/Browser/ClanEditTest.php).
 */
final class ParseMultipartBody
{
    public function handle(Request $request, Closure $next): mixed
    {
        $contentType = (string) $request->headers->get('content-type');

        if (preg_match('/^multipart\/form-data;.*boundary="?([^";]+)"?/i', $contentType, $match) === 1 && $this->needsParsing($request)) {
            // Start from nothing: the fields the server already read would be listed twice otherwise.
            $request->files->replace();
            $request->request->replace();

            $this->parse($request, (string) $request->getContent(), $match[1]);
        }

        return $next($request);
    }

    private function needsParsing(Request $request): bool
    {
        if ($request->files->count() === 0) {
            return true;
        }

        foreach (array_keys($request->files->all()) as $key) {
            if (str_contains((string) $key, '[')) {
                return true;
            }
        }

        return false;
    }

    private function parse(Request $request, string $body, string $boundary): void
    {
        foreach (explode('--'.$boundary, $body) as $part) {
            $part = ltrim($part, "\r\n");

            if ($part === '' || str_starts_with($part, '--') || ! str_contains($part, "\r\n\r\n")) {
                continue;
            }

            [$head, $content] = explode("\r\n\r\n", $part, 2);
            $content = substr($content, 0, -2); // the CRLF before the next boundary

            if (preg_match('/name="([^"]*)"/i', $head, $name) !== 1) {
                continue;
            }

            $key = $name[1];
            $isList = str_ends_with($key, '[]');
            $key = $isList ? substr($key, 0, -2) : $key;

            if (preg_match('/filename="([^"]*)"/i', $head, $filename) === 1) {
                $type = preg_match('/content-type:\s*([^\r\n]+)/i', $head, $typeMatch) === 1 ? trim($typeMatch[1]) : null;
                $path = (string) tempnam(sys_get_temp_dir(), 'mp');
                file_put_contents($path, $content);
                $file = new UploadedFile($path, $filename[1], $type, UPLOAD_ERR_OK, true);

                $request->files->set($key, $isList ? [...(array) $request->files->get($key, []), $file] : $file);

                continue;
            }

            $request->request->set($key, $isList ? [...(array) $request->request->all($key), $content] : $content);
        }
    }
}
