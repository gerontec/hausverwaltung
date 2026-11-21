<?php
// mayan.php - client for the local Mayan bridge (tools/mayan_bridge.py on 127.0.0.1:18095)
declare(strict_types=1);

function mayan_call(string $path, array $args = [], int $timeout = 60, bool $raw = false): mixed {
    $key = @file_get_contents(APP_ROOT . '/data/bridge.key');
    if ($key === false) throw new RuntimeException('Mayan-Brücke nicht eingerichtet (data/bridge.key fehlt).');
    $ctx = stream_context_create(['http' => ['header' => 'X-HV-Key: ' . trim($key), 'timeout' => $timeout, 'ignore_errors' => true]]);
    $body = @file_get_contents('http://127.0.0.1:18095' . $path . ($args ? '?' . http_build_query($args) : ''), false, $ctx);
    if ($body === false) throw new RuntimeException('Mayan-Brücke nicht erreichbar (Dienst hv-mayan-bridge auf dem dell).');
    $code = (int)preg_replace('/^HTTP\/\S+\s+(\d+).*/', '$1', $http_response_header[0] ?? 'HTTP/1.1 500');
    if ($code !== 200) {
        $j = json_decode($body, true);
        throw new RuntimeException('Mayan: ' . ($j['error'] ?? $j['err'] ?? "HTTP $code"));
    }
    return $raw ? $body : json_decode($body, true);
}

function mayan_search(string $q, bool $all = false, int $limit = 20): array {
    return mayan_call('/search', ['q' => $q, 'all' => $all ? 1 : 0, 'limit' => $limit], 90);
}
