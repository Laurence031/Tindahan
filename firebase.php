<?php
/**
 * Firebase Realtime Database helper (REST) + JSON mirror.
 *
 *  db_get / db_set / db_update / db_delete
 *  - storage = 'firebase' -> cURL papunta sa Firebase REST API
 *  - storage = 'local'    -> data/db.json mismo ang database (pang-test)
 *
 * Pagkatapos ng bawat request na may write, kukunin ulit ang buong database at isi-save
 * sa data/db.json, kaya laging makikita mo sa JSON ang laman ng Firebase.
 */

function cfg($key) {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c[$key] ?? null;
}

class DbError extends Exception {}

/* ---------- LOCAL MODE (JSON file) ---------- */
function local_load() {
    $f = cfg('json_file');
    if (!is_file($f)) return [];
    $j = json_decode(file_get_contents($f), true);
    return is_array($j) ? $j : [];
}
function local_save($data) {
    $f = cfg('json_file');
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0775, true);
    $tmp = $f . '.tmp';
    file_put_contents($tmp, json_encode($data ?: new stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, $f);
}
function path_keys($path) {
    return array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
}
function local_op($method, $path, $val = null, $query = []) {
    $data = local_load();
    $keys = path_keys($path);
    if ($method === 'GET') {
        foreach ($keys as $k) {
            if (!is_array($data) || !array_key_exists($k, $data)) return null;
            $data = $data[$k];
        }
        return local_query($data, $query);
    }
    if ($method === 'DELETE') {
        $ref = &$data;
        $last = array_pop($keys);
        foreach ($keys as $k) {
            if (!isset($ref[$k]) || !is_array($ref[$k])) { return null; }
            $ref = &$ref[$k];
        }
        if ($last !== null) unset($ref[$last]);
        local_save($data);
        return null;
    }
    $ref = &$data;
    foreach ($keys as $k) {
        if (!isset($ref[$k]) || !is_array($ref[$k])) $ref[$k] = [];
        $ref = &$ref[$k];
    }
    if ($method === 'PUT') {
        $ref = $val;
    } else { // PATCH (shallow merge)
        if (!is_array($ref)) $ref = [];
        foreach ($val as $k => $v) $ref[$k] = $v;
    }
    local_save($data);
    return $val;
}

/** Gayahin ang orderBy="$key" + startAt/endAt/limitToLast ng Firebase (local mode) */
function local_query($data, $query) {
    if (!is_array($data) || empty($query['orderBy'])) return $data;
    ksort($data);
    $strip = function ($v) { return trim((string)$v, '"'); };
    if (isset($query['startAt'])) $data = array_filter($data, function ($k) use ($query, $strip) { return (string)$k >= $strip($query['startAt']); }, ARRAY_FILTER_USE_KEY);
    if (isset($query['endAt']))   $data = array_filter($data, function ($k) use ($query, $strip) { return (string)$k <= $strip($query['endAt']); }, ARRAY_FILTER_USE_KEY);
    if (isset($query['limitToLast'])) $data = array_slice($data, -(int)$query['limitToLast'], null, true);
    return $data;
}

/* ---------- FIREBASE MODE (REST) ---------- */
function fb_request($method, $path, $payload = null, $query = []) {
    $url = rtrim(cfg('firebase_url'), '/') . '/' . ltrim($path, '/') . '.json';
    if (cfg('firebase_auth')) $query['auth'] = cfg('firebase_auth');
    if ($query) $url .= '?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) throw new DbError('Hindi maabot ang Firebase: ' . $err);
    $json = json_decode($body, true);
    if ($code >= 400) {
        $msg = is_array($json) && isset($json['error']) ? $json['error'] : "HTTP $code";
        if ($code === 401 || $code === 403) $msg .= ' - tingnan ang Firebase Rules o ang firebase_auth sa config.php';
        throw new DbError('Firebase error: ' . $msg);
    }
    return $json;
}

/* ---------- PUBLIC API ---------- */
$GLOBALS['db_dirty'] = false;

function use_local() { return cfg('storage') === 'local'; }

function db_get($path, $query = []) {
    return use_local() ? local_op('GET', $path, null, $query) : fb_request('GET', $path, null, $query);
}
function db_set($path, $data) {
    $GLOBALS['db_dirty'] = true;
    return use_local() ? local_op('PUT', $path, $data) : fb_request('PUT', $path, $data);
}
function db_update($path, $data) {
    $GLOBALS['db_dirty'] = true;
    return use_local() ? local_op('PATCH', $path, $data) : fb_request('PATCH', $path, $data);
}
function db_delete($path) {
    $GLOBALS['db_dirty'] = true;
    return use_local() ? local_op('DELETE', $path) : fb_request('DELETE', $path);
}

/** Kopya ng buong Firebase -> data/db.json */
function db_mirror() {
    if (use_local()) return;
    try {
        $all = fb_request('GET', '');
        local_save(is_array($all) ? $all : []);
    } catch (Exception $e) {
        error_log('[tindahan] mirror failed: ' . $e->getMessage());
    }
}

register_shutdown_function(function () {
    if (!empty($GLOBALS['db_dirty'])) db_mirror();
});
