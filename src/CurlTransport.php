<?php

declare(strict_types=1);

namespace TypeSafe;

final class CurlTransport implements Transport
{
    /** Most idle connections libcurl keeps cached for reuse. */
    private const MAX_CONNECTS = 100;

    /** Idle connections older than this are not reused, like upstream's 30 s keepalive expiry. */
    private const MAX_IDLE_SECONDS = 30;

    private ?\CurlHandle $handle = null;

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        if ($method === '') {
            throw new TransportException('HTTP method cannot be empty.');
        }
        if ($url === '') {
            throw new TransportException('HTTP URL cannot be empty.');
        }
        // One handle per transport: its connection cache survives curl_reset(), so repeated sends
        // reuse the TLS connection instead of paying a new handshake each time. curl_reset() clears
        // every option, so nothing from the previous request (method, body, headers) leaks.
        if ($this->handle === null) {
            $handle = curl_init();
            if ($handle === false) {
                throw new TransportException('Unable to initialize cURL.');
            }
            $this->handle = $handle;
        } else {
            curl_reset($this->handle);
        }
        $handle = $this->handle;

        /** @var array<string, list<string>> $responseHeaders */
        $responseHeaders = [];
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt_array($handle, [
            CURLOPT_MAXCONNECTS => self::MAX_CONNECTS,
            CURLOPT_MAXAGE_CONN => self::MAX_IDLE_SECONDS,
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
