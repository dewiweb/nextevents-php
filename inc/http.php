<?php
/** HTTP GET minimal : cURL si dispo, sinon allow_url_fopen.
 *  Retourne [body, content_type] ; lève RuntimeException en échec. */

function http_get($url, $timeout = 30) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'nextevents-php/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
        // curl_close() déprécié en 8.5 (inutile depuis 8.0 : les
        // handles sont des objets libérés par le GC)
        $close = PHP_VERSION_ID < 80000 ? 'curl_close' : null;
        if ($body === false || $code >= 400) {
            $err = curl_error($ch);
            if ($close) $close($ch);
            throw new RuntimeException("HTTP $code $url : $err");
        }
        if ($close) $close($ch);
        return [$body, explode(';', $type)[0]];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => $timeout,
        'user_agent' => 'nextevents-php/1.0',
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false)
        throw new RuntimeException("HTTP KO $url");
    $type = '';
    // $http_response_header est déprécié en PHP 8.5 — la fonction
    // dédiée n'existe qu'à partir de 8.4. L'accès en variable variable
    // évite la dépréciation émise à la compilation du nom littéral.
    $headers = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?: [])
        : (${'http_response_header'} ?? []);
    foreach ($headers as $h) {
        if (stripos($h, 'Content-Type:') === 0)
            $type = trim(explode(';', substr($h, 13))[0]);
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m) && (int)$m[1] >= 400)
            throw new RuntimeException("HTTP $m[1] $url");
    }
    return [$body, $type];
}

function http_json($url) {
    [$body] = http_get($url);
    $d = json_decode($body, true);
    if (!is_array($d))
        throw new RuntimeException("JSON invalide : $url");
    return $d;
}
