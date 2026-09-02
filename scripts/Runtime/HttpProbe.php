<?php

declare(strict_types=1);

namespace Runtime;

final readonly class HttpProbe
{
    public function __construct(private string $baseUrl) {}

    /** @param array<string, mixed>|null $json
     * @param  array<string, string>  $headers
     */
    public function request(string $method, string $path, ?array $json = null, array $headers = [], float $timeoutSeconds = 5.0): HttpResponse
    {
        $method = strtoupper($method);
        if (! in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true) || ! str_starts_with($path, '/')) {
            throw new RuntimeException('Invalid runtime HTTP request.');
        }

        $lines = ['Accept: application/json'];
        $content = null;
        if ($json !== null) {
            $content = json_encode($json, JSON_THROW_ON_ERROR);
            $lines[] = 'Content-Type: application/json';
        }
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'content' => $content,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($this->baseUrl.$path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        if ($body === false || $responseHeaders === []) {
            throw new RuntimeException('Runtime HTTP probe could not reach the owned app server.');
        }

        $statusLine = $responseHeaders[0];
        if (! preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            throw new RuntimeException('Runtime HTTP probe received an invalid status line.');
        }
        $parsedHeaders = [];
        foreach (array_slice($responseHeaders, 1) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $parsedHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return new HttpResponse((int) $matches[1], substr($body, 0, 8_192), $parsedHeaders);
    }
}
