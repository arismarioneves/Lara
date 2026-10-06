<?php
declare(strict_types=1);

namespace Lara;

/** Cliente HTTP mínimo (cURL) para os provedores. */
final class Http
{
    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public static function postJson(string $url, array $headers, array $body, int $timeout = 30): array
    {
        $raw = self::request($url, $headers + ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $timeout);
        $data = json_decode($raw['body'], true);
        if (!is_array($data)) {
            throw new ProviderException(self::host($url) . ': resposta não-JSON');
        }
        return $data;
    }

    /**
     * POST JSON que retorna binário (áudio).
     * @return array{body: string, type: string}
     */
    public static function postForBinary(string $url, array $headers, array $body, int $timeout = 60): array
    {
        $raw = self::request($url, $headers + ['Content-Type' => 'application/json'], json_encode($body, JSON_UNESCAPED_UNICODE), $timeout);
        return ['body' => $raw['body'], 'type' => $raw['type']];
    }

    /**
     * @param array<string, string|\CURLFile> $fields
     * @return array<string, mixed>
     */
    public static function postMultipart(string $url, array $headers, array $fields, int $timeout = 60): array
    {
        $raw = self::request($url, $headers, $fields, $timeout);
        $data = json_decode($raw['body'], true);
        if (!is_array($data)) {
            throw new ProviderException(self::host($url) . ': resposta não-JSON');
        }
        return $data;
    }

    /** @return array{status: int, body: string, type: string} */
    private static function request(string $url, array $headers, string|array $payload, int $timeout): array
    {
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new ProviderException(self::host($url) . ": $err");
        }
        if ($status < 200 || $status >= 300) {
            throw new ProviderException(sprintf('%s: HTTP %d %s', self::host($url), $status, mb_substr((string)$body, 0, 400)));
        }
        return ['status' => $status, 'body' => (string)$body, 'type' => $type];
    }

    private static function host(string $url): string
    {
        return (string)parse_url($url, PHP_URL_HOST);
    }
}
