<?php

declare(strict_types=1);

namespace TypeSafe;

final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        if ($method === '') {
            throw new TransportException('HTTP method cannot be empty.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException('Unable to initialize cURL.');
        }

        /** @var array<string, list<string>> $responseHeaders */
        $responseHeaders = [];
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) max(1, round($timeout * 1000)),
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) {
                    return $length;
                }
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))][] = trim($value);
                return $length;
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $number = curl_errno($handle);
            $message = curl_error($handle);
            throw new TransportException($message, $number === CURLE_OPERATION_TIMEDOUT);
        }
        if (!is_string($responseBody)) {
            throw new TransportException('cURL returned an invalid response body.');
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new HttpResponse($status, $responseHeaders, $responseBody);
    }
}
