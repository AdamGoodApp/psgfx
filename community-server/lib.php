<?php
// Shared helpers for the PSGFX community icon server.
declare(strict_types=1);
if (!is_file(__DIR__ . '/config.php')) { http_response_code(500); exit('{"ok":false,"error":"The server is not set up yet (config.php is missing)."}'); }
require __DIR__ . '/config.php';
if (!defined('GITHUB_API')) define('GITHUB_API', 'https://api.github.com');
const DATA_DIR = __DIR__ . '/data';
const MIRROR_DIR = __DIR__ . '/files';   // copies of every icon and thumbnail, served by this site
const TREE_TTL = 90;   // seconds the icon list is cached before asking GitHub again

// PSGFX runs on http://127.0.0.1 in the user's browser, so every answer allows any origin.
// Nothing here relies on cookies, so that is safe.
function cors(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}
function out(array $j, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400): void { out(['ok' => false, 'error' => $msg], $code); }

// Who is asking, as a salted hash: enough to rate-limit and block, without storing addresses.
function who(): string {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    return substr(hash('sha256', $ip . '|' . GITHUB_TOKEN), 0, 20);
}

// Small JSON files in data/, updated under a lock so two requests can't overwrite each other.
function data_path(string $name): string {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
    return DATA_DIR . '/' . $name . '.json';
}
function data_read(string $name): array {
    $f = data_path($name);
    if (!is_file($f)) return [];
    $j = json_decode((string)file_get_contents($f), true);
    return is_array($j) ? $j : [];
}
function data_update(string $name, callable $fn) {
    $h = fopen(data_path($name), 'c+');
    flock($h, LOCK_EX);
    $j = json_decode((string)stream_get_contents($h), true);
    if (!is_array($j)) $j = [];
    $ret = $fn($j);
    ftruncate($h, 0); rewind($h);
    fwrite($h, json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fflush($h); flock($h, LOCK_UN); fclose($h);
    return $ret;
}

function gh(string $method, string $path, ?array $body = null): array {
    $ch = curl_init(GITHUB_API . $path);
    $hdr = ['Accept: application/vnd.github+json', 'User-Agent: psgfx-community', 'X-GitHub-Api-Version: 2022-11-28',
            'Authorization: Bearer ' . GITHUB_TOKEN];
    if ($body !== null) { $hdr[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = is_string($res) ? json_decode($res, true) : null;
    return [$code, is_array($j) ? $j : []];
}

function valid_title(string $t): bool { return (bool)preg_match('/^[A-Z]{4}\d{5}$/', $t); }
function raw_url(string $path): string { return 'https://raw.githubusercontent.com/' . GITHUB_REPO . '/' . GITHUB_BRANCH . '/' . $path; }

// Every icon in the repository: icons/<TITLE ID>/<time>_<hash>_<name>.png. GitHub is the source of truth,
// so an icon you delete on GitHub disappears from PSGFX too. Cached for TREE_TTL seconds.
function all_icons(bool $fresh = false): array {
    $cache = data_path('tree-cache');
    if (!$fresh && is_file($cache) && time() - filemtime($cache) < TREE_TTL) {
        $j = json_decode((string)file_get_contents($cache), true);
        if (is_array($j)) return $j;
    }
    [$code, $j] = gh('GET', '/repos/' . GITHUB_REPO . '/git/trees/' . GITHUB_BRANCH . '?recursive=1');
    if ($code === 404 || $code === 409) $j = ['tree' => []];   // empty repository: no icons yet
    elseif ($code !== 200) {
        if (is_file($cache)) return json_decode((string)file_get_contents($cache), true) ?: [];
        fail('GitHub is not answering right now. Try again in a minute.', 502);
    }
    $icons = [];
    foreach ($j['tree'] ?? [] as $e) {
        if (($e['type'] ?? '') !== 'blob') continue;
        if (!preg_match('#^icons/([A-Z]{4}\d{5})/((\d+)_([0-9a-f]{8})_?([^/]*))\.png$#', $e['path'], $m)) continue;
        $icons[] = ['id' => $e['path'], 'title' => $m[1], 'time' => (int)$m[3], 'by' => str_replace('-', ' ', $m[5]), 'sha' => $e['sha']];
    }
    usort($icons, fn($a, $b) => $b['time'] <=> $a['time']);
    file_put_contents($cache, json_encode($icons), LOCK_EX);
    return $icons;
}
function forget_icons(): void { @unlink(data_path('tree-cache')); }

// Reports: { "<icon path>": {"by": [who...], "ok": bool} }. "ok" means you reviewed it and kept it.
function is_hidden(array $reports, string $id): bool {
    $r = $reports[$id] ?? null;
    return $r && empty($r['ok']) && count($r['by'] ?? []) >= REPORTS_TO_HIDE;
}

// This site's own address, for links into files/. Uses whichever of http/https the request came in on.
function site_base(): string {
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}
function mirror_put(string $path, string $bytes): void {
    $f = MIRROR_DIR . '/' . $path;
    if (!is_dir(dirname($f))) mkdir(dirname($f), 0755, true);
    file_put_contents($f, $bytes);
}
function mirror_delete(string $path): void {
    @unlink(MIRROR_DIR . '/' . $path);
    @unlink(MIRROR_DIR . '/thumbs/' . substr($path, 6));
}
// What PSGFX and other apps get for one icon. "url"/"thumb" are on GitHub; "mirror"/"mirror_thumb" are on this site.
function icon_out(array $i): array {
    $t = 'thumbs/' . substr($i['id'], 6);
    $o = ['id' => $i['id'], 'url' => raw_url($i['id']), 'thumb' => raw_url($t), 'by' => $i['by'], 'time' => $i['time']];
    if (is_file(MIRROR_DIR . '/' . $i['id'])) { $o['mirror'] = site_base() . 'files/' . $i['id']; $o['mirror_thumb'] = site_base() . 'files/' . $t; }
    return $o;
}
