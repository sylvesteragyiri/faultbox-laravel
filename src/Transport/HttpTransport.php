<?php

namespace Faultbox\Laravel\Transport;

/**
 * Sends events with ext-curl when available, otherwise PHP streams. Bodies
 * are gzip-compressed when ext-zlib is loaded. Strict timeouts keep a slow
 * or unreachable tracker from holding up the application.
 */
final class HttpTransport implements Transport
{
    public const USER_AGENT = 'faultbox-laravel/1.0';

    public function __construct(
        private readonly string $dsn,
        private readonly string $apiKey,
        private readonly float $timeout = 2.0,
        private readonly float $connectTimeout = 1.0,
    ) {
    }

    public function send(string $path, array $payload): Response
    {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false) {
                return new Response(0, error: 'could not encode event: ' . json_last_error_msg());
            }

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'User-Agent: ' . self::USER_AGENT,
            ];
            if (function_exists('gzencode') && strlen($json) > 1024) {
                $gz = gzencode($json, 6);
                if ($gz !== false) {
                    $json = $gz;
                    $headers[] = 'Content-Encoding: gzip';
                }
            }

            $url = rtrim($this->dsn, '/') . $path;

            return function_exists('curl_init')
                ? $this->sendWithCurl($url, $headers, $json)
                : $this->sendWithStreams($url, $headers, $json);
        } catch (\Throwable $e) {
            return new Response(0, error: $e->getMessage());
        }
    }

    private function sendWithCurl(string $url, array $headers, string $body): Response
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $result === false ? curl_error($ch) : null;
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        return new Response(
            $result === false ? 0 : $status,
            is_string($result) ? $result : '',
            isset($responseHeaders['retry-after']) ? (int) $responseHeaders['retry-after'] : null,
            $error,
        );
    }

    private function sendWithStreams(string $url, array $headers, string $body): Response
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);

        $result = @file_get_contents($url, false, $context);
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);
        if ($result === false || $responseHeaders === []) {
            return new Response(0, error: error_get_last()['message'] ?? 'request failed');
        }

        $status = 0;
        $retryAfter = null;
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($line, 'retry-after:') === 0) {
                $retryAfter = (int) trim(substr($line, 12));
            }
        }

        return new Response($status, $result, $retryAfter);
    }
}
