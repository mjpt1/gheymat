<?php
// Gheymat - free price aggregator (tgju public JSON + nobitex public stats), cached 90s
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cacheFile = __DIR__ . '/cache/prices.json';
$ttl = 90;
if (!is_dir(__DIR__ . '/cache')) @mkdir(__DIR__ . '/cache', 0755, true);

if (is_file($cacheFile) && time() - filemtime($cacheFile) < $ttl) {
    readfile($cacheFile);
    exit;
}

function fetch($url) {
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'header' => "User-Agent: Mozilla/5.0\r\nReferer: https://www.tgju.org/\r\n"], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $r = @file_get_contents($url, false, $ctx);
        return $r ?: null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Referer: https://www.tgju.org/'],
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code == 200 && $r) ? $r : null;
}

$out = ['ok' => false, 'updated' => time(), 'items' => [], 'sources' => []];

$raw = fetch('https://call1.tgju.org/ajax.json') ?: fetch('https://call2.tgju.org/ajax.json') ?: fetch('https://call3.tgju.org/ajax.json');
if ($raw) {
    $j = json_decode($raw, true);
    if (isset($j['current'])) {
        foreach ($j['current'] as $k => $v) {
            $out['items'][$k] = [
                'p' => (float)str_replace(',', '', $v['p'] ?? 0),
                'h' => (float)str_replace(',', '', $v['h'] ?? 0),
                'l' => (float)str_replace(',', '', $v['l'] ?? 0),
                'd' => (float)str_replace(',', '', $v['d'] ?? 0),
                'dp' => (float)($v['dp'] ?? 0),
                'dt' => $v['dt'] ?? '',
                't' => $v['t'] ?? '',
                'ts' => $v['ts'] ?? '',
            ];
        }
        $out['ok'] = true;
        $out['sources'][] = 'tgju';
    }
}

// Wallex public markets (live 24/7, prices in toman -> stored in rial for consistency)
$wx = fetch('https://api.wallex.ir/v1/markets');
if ($wx) {
    $j = json_decode($wx, true);
    $syms = $j['result']['symbols'] ?? [];
    $now = (new DateTime('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d H:i:s');
    foreach (['USDTTMN', 'BTCTMN', 'ETHTMN', 'PAXGTMN', 'XAUTTMN', 'TONTMN', 'SOLTMN', 'XRPTMN', 'DOGETMN', 'TRXTMN'] as $k) {
        $s = $syms[$k]['stats'] ?? null;
        if (!$s || empty($s['lastPrice'])) continue;
        $ch = (float)($s['24h_ch'] ?? 0);
        $out['items']['wallex-' . strtolower($k)] = [
            'p' => (float)$s['lastPrice'] * 10, 'h' => (float)($s['24h_highPrice'] ?? 0) * 10, 'l' => (float)($s['24h_lowPrice'] ?? 0) * 10,
            'd' => 0, 'dp' => abs($ch), 'dt' => $ch < 0 ? 'low' : 'high', 't' => '', 'ts' => $now, 'live' => 1,
        ];
    }
    if ($syms) { $out['sources'][] = 'wallex'; $out['ok'] = true; }
}

$json = json_encode($out, JSON_UNESCAPED_UNICODE);
if ($out['ok']) {
    file_put_contents($cacheFile, $json, LOCK_EX);
    echo $json;
} elseif (is_file($cacheFile)) {
    readfile($cacheFile); // serve stale
} else {
    http_response_code(502);
    echo $json;
}
