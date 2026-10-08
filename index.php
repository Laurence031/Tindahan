<?php
/**
 * TINDAHAN API  ->  api/index.php?action=...
 * Ginagamit ng pure_pos_application.html at index.html (admin).
 *
 * GET : ping, products, reports, notifications, payroll, sync_json
 * POST: product_save, product_delete, sale, notif_read,
 *       employee_save, employee_delete, payroll_save, payroll_status,
 *       demo_sales, demo_clear
 */
require __DIR__ . '/firebase.php';
date_default_timezone_set(cfg('timezone'));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: ' . (cfg('allow_origin') ?: '*'));
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ================= helpers ================= */
function out($d, $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
function fail($msg, $code = 400) { out(['ok' => false, 'error' => $msg], $code); }

function txt($in, $k, $max, $required = false) {
    $v = isset($in[$k]) ? trim((string)$in[$k]) : '';
    if ($required && $v === '') fail("Kulang ang '$k'.");
    if (mb_strlen($v) > $max) fail("Masyadong mahaba ang '$k' (max $max).");
    return $v;
}
function num($in, $k, $min, $max, $default = null) {
    if (!isset($in[$k]) || $in[$k] === '') {
        if ($default === null) fail("Kulang ang '$k'.");
        return $default;
    }
    if (!is_numeric($in[$k])) fail("Dapat number ang '$k'.");
    $v = (float)$in[$k];
    if ($v < $min || $v > $max) fail("Ang '$k' ay dapat nasa pagitan ng $min at $max.");
    return $v;
}
function money($n) { return round((float)$n, 2); }
function safe_key($s) { $s = preg_replace('/[.$#\[\]\/]/', '-', (string)$s); return $s === '' ? 'Others' : $s; }
function next_id($name, $start = 0) {
    $n = db_get("meta/$name");
    $n = ($n === null) ? $start : (int)$n;
    $n++;
    db_set("meta/$name", $n);
    return $n;
}
function push_key() { return date('YmdHis') . substr(bin2hex(random_bytes(3)), 0, 5); }
function notify($title, $msg, $type = 'info') {
    db_set('notifications/main/' . push_key(), [
        'title' => $title, 'msg' => $msg, 'type' => $type,
        'ts' => date('c'), 'read' => false,
    ]);
}

$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) fail('Invalid JSON.');
}

/* ================= PRODUCTS ================= */
$ICONS = [
    'Beverages' => 'fa-bottle-water', 'Snacks' => 'fa-cookie-bite', 'Instant noodles' => 'fa-bowl-food',
    'Biscuits' => 'fa-cookie', 'Canned goods' => 'fa-fish', 'Condiments' => 'fa-bottle-droplet',
    'Frozen foods' => 'fa-snowflake', 'Essential' => 'fa-basket-shopping',
];

/** Unang laman ng products (galing sa POS mo). Isang beses lang tatakbo. */
function seed_products_if_needed() {
    if (db_get('meta/productsSeeded') !== null) return;
    $seed = [
        // name, category, price, cost, stock, icon
        ['Coca-cola 1.5L', 'Beverages', 45, 38.00, 24, 'fa-bottle-water'],
        ['Piattos (Sour Cream)', 'Snacks', 20, 16.50, 18, 'fa-cookie-bite'],
        ['Pancit Canton', 'Instant noodles', 15, 12.50, 22, 'fa-bowl-food'],
        ['Skyflakes Crackers', 'Biscuits', 10, 8.30, 40, 'fa-box-tissue'],
        ['Milo (Sachet)', 'Beverages', 10, 8.00, 15, 'fa-mug-hot'],
        ['Century Tuna (180g)', 'Canned goods', 32, 27.00, 8, 'fa-fish'],
        ['Bear Brand (Choco)', 'Beverages', 18, 15.00, 5, 'fa-glass-water'],
        ['Mang Tomas', 'Condiments', 25, 20.50, 12, 'fa-wine-bottle'],
        ['Jufran Ketchup', 'Condiments', 35, 29.00, 6, 'fa-bottle-droplet'],
        ['Pineapple Juice', 'Beverages', 25, 20.00, 12, 'fa-whiskey-glass'],
    ];
    $batch = [];
    foreach ($seed as $s) {
        $id = next_id('productCounter');
        $batch["p$id"] = [
            'id' => $id, 'name' => $s[0], 'category' => $s[1], 'price' => $s[2], 'cost' => $s[3],
            'shipping' => 0, 'other' => 0, 'stock' => $s[4], 'reorder' => cfg('default_reorder'),
            'barcode' => '', 'icon' => $s[5], 'createdAt' => date('c'),
        ];
    }
    db_update('products', $batch);
    db_set('meta/productsSeeded', true);
}

function prod_out($p) {
    return [
        'id' => (int)$p['id'], 'name' => $p['name'], 'category' => $p['category'],
        'price' => money($p['price']), 'cost' => money($p['cost'] ?? 0),
        'shipping' => money($p['shipping'] ?? 0), 'other' => money($p['other'] ?? 0),
        'stock' => (int)$p['stock'], 'reorder' => (int)($p['reorder'] ?? cfg('default_reorder')),
        'barcode' => $p['barcode'] ?? '', 'icon' => $p['icon'] ?? 'fa-box',
    ];
}
function load_products() {
    $raw = db_get('products');
    $list = [];
    if (is_array($raw)) foreach ($raw as $p) if (is_array($p) && isset($p['id'])) $list[] = prod_out($p);
    usort($list, function ($a, $b) { return $a['id'] <=> $b['id']; });
    return $list;
}
function unit_cost($p) { return money($p['cost'] + $p['shipping'] + $p['other']); }

function log_stock($pid, $type, $qty, $note = '') {
    db_set('stockMovements/' . push_key(), [
        'productId' => $pid, 'type' => $type, 'qty' => $qty, 'note' => $note, 'ts' => date('c'),
    ]);
}

/* ================= SALES / AGGREGATES ================= */
function day_add(&$d, $t) {
    $d['date']    = $t['date'];
    $d['revenue'] = money(($d['revenue'] ?? 0) + $t['total']);
    $d['cost']    = money(($d['cost'] ?? 0) + $t['cost']);
    $d['orders']  = ($d['orders'] ?? 0) + 1;
    $qty = 0;
    foreach ($t['items'] as $it) {
        $qty += $it['qty'];
        $ck = safe_key($it['category']);
        $c = $d['byCategory'][$ck] ?? ['revenue' => 0, 'cost' => 0, 'qty' => 0];
        $c['revenue'] = money($c['revenue'] + $it['price'] * $it['qty']);
        $c['cost']    = money($c['cost'] + $it['cost'] * $it['qty']);
        $c['qty']    += $it['qty'];
        $d['byCategory'][$ck] = $c;

        $pk = 'p' . $it['id'];
        $p = $d['products'][$pk] ?? ['name' => $it['name'], 'cat' => $it['category'], 'qty' => 0, 'revenue' => 0];
        $p['qty'] += $it['qty'];
        $p['revenue'] = money($p['revenue'] + $it['price'] * $it['qty']);
        $d['products'][$pk] = $p;
    }
    $d['items'] = ($d['items'] ?? 0) + $qty;
    $mk = safe_key($t['method']);
    $m = $d['byPayment'][$mk] ?? ['amount' => 0, 'orders' => 0];
    $m['amount'] = money($m['amount'] + $t['total']);
    $m['orders'] += 1;
    $d['byPayment'][$mk] = $m;
}

function rebuild_daily() {
    $all = db_get('transactions');
    $days = [];
    if (is_array($all)) foreach ($all as $t) {
        if (!is_array($t) || ($t['status'] ?? '') !== 'completed') continue;
        $dk = $t['date'];
        if (!isset($days[$dk])) $days[$dk] = [];
        day_add($days[$dk], $t);
    }
    if ($days) db_set('dailySales', $days); else db_delete('dailySales');
    return count($days);
}

function make_trx($n, $items, $method, $cash, $ref, $cashier, $ts, $demo = false) {
    $subtotal = 0; $cost = 0; $qty = 0;
    foreach ($items as $it) { $subtotal += $it['price'] * $it['qty']; $cost += $it['cost'] * $it['qty']; $qty += $it['qty']; }
    $subtotal = money($subtotal);
    $vat = money($subtotal * (float)(db_get('meta/vatRate') ?? 0));
    $total = money($subtotal + $vat);
    $t = [
        'receiptNo' => 'TRX-' . $n, 'ts' => date('c', $ts), 'tsMs' => $ts * 1000, 'date' => date('Y-m-d', $ts),
        'cashier' => $cashier, 'method' => $method, 'ref' => $ref, 'items' => array_values($items),
        'itemCount' => $qty, 'subtotal' => $subtotal, 'vat' => $vat, 'total' => $total, 'cost' => money($cost),
        'cash' => money($method === 'cash' ? $cash : $total),
        'change' => money($method === 'cash' ? $cash - $total : 0),
        'status' => 'completed',
    ];
    if ($demo) $t['demo'] = true;
    return $t;
}

/* ================= PAYROLL ================= */
function period_info($id) {
    if (!preg_match('/^(\d{4})-(\d{2})-([AB])$/', $id, $m)) return null;
    $last = (int)date('t', strtotime("{$m[1]}-{$m[2]}-01"));
    $sd = $m[3] === 'A' ? 1 : 16;
    $ed = $m[3] === 'A' ? 15 : $last;
    $s = strtotime("{$m[1]}-{$m[2]}-" . sprintf('%02d', $sd));
    $e = strtotime("{$m[1]}-{$m[2]}-" . sprintf('%02d', $ed));
    return ['id' => $id, 'start' => date('Y-m-d', $s), 'end' => date('Y-m-d', $e),
            'label' => date('M j', $s) . ' – ' . date('M j, Y', $e)];
}
function period_id_for($ts) { return date('Y-m', $ts) . '-' . ((int)date('j', $ts) <= 15 ? 'A' : 'B'); }
function recent_periods() {
    $ids = []; $ts = time();
    for ($i = 0; $i < 3; $i++) {
        $id = period_id_for($ts);
        $ids[] = period_info($id);
        $ts = strtotime(period_info($id)['start']) - 86400;
    }
    return $ids;
}
function msc_ee($monthly) { // SSS employee share (5% ng MSC, MSC 5,000-35,000)
    $msc = max(5000, min(35000, round($monthly / 500) * 500));
    return $monthly > 0 ? $msc * 0.05 : 0;
}
function income_tax_monthly($t) { // TRAIN law, monthly
    if ($t <= 20833) return 0;
    if ($t <= 33332) return ($t - 20833) * 0.15;
    if ($t <= 66666) return 1875 + ($t - 33333) * 0.20;
    if ($t <= 166666) return 8541.80 + ($t - 66667) * 0.25;
    if ($t <= 666666) return 33541.80 + ($t - 166667) * 0.30;
    return 183541.80 + ($t - 666667) * 0.35;
}
/** Simplified estimate: semi-monthly, kinukuwenta ang monthly base = gross x 2 */
function compute_pay($rate, $in) {
    $days = (float)($in['daysWorked'] ?? 0);
    $ot   = (float)($in['otHours'] ?? 0);
    $late = (float)($in['lateMin'] ?? 0);
    $oth  = (float)($in['otherDed'] ?? 0);
    $regular = money($rate * $days);
    $otPay   = money($ot * ($rate / 8) * 1.25);
    $gross   = money($regular + $otPay);
    $monthly = $gross * 2;
    $sss  = money(msc_ee($monthly) / 2);
    $ph   = $monthly > 0 ? money(max(10000, min(100000, $monthly)) * 0.025 / 2) : 0;
    $pag  = money(min($monthly, 10000) * 0.02 / 2);
    $tax  = money(income_tax_monthly($monthly - ($sss + $ph + $pag) * 2) / 2);
    $lateDed = money($late * ($rate / 480));
    $totalDed = money($lateDed + $sss + $ph + $pag + $tax + $oth);
    return [
        'regular' => $regular, 'overtime' => $otPay, 'gross' => $gross,
        'late' => $lateDed, 'sss' => $sss, 'philhealth' => $ph, 'pagibig' => $pag, 'tax' => $tax,
        'other' => money($oth), 'deductions' => $totalDed, 'net' => money(max(0, $gross - $totalDed)),
    ];
}

/* ================= ROUTER ================= */
$action = $_GET['action'] ?? '';

try {
    switch ($action) {

    case 'ping':
        $db = 'ok';
        try { db_get('meta'); } catch (Exception $e) { $db = $e->getMessage(); }
        out(['ok' => $db === 'ok', 'storage' => cfg('storage'), 'database' => $db,
             'time' => date('c'), 'demo' => (bool)cfg('enable_demo_seed')]);

    case 'sync_json':
        db_mirror();
        out(['ok' => true, 'file' => 'data/db.json']);

    /* ---------- products ---------- */
    case 'products':
        seed_products_if_needed();
        out(['ok' => true, 'products' => load_products()]);

    case 'product_save':
        $id = isset($in['id']) && $in['id'] !== '' ? (int)$in['id'] : 0;
        $name = txt($in, 'name', 60, true);
        $cat  = txt($in, 'category', 30, true);
        $barcode = preg_replace('/[^A-Za-z0-9\-]/', '', txt($in, 'barcode', 40));
        $rec = [
            'name' => $name, 'category' => $cat,
            'price' => money(num($in, 'price', 0, 1000000)),
            'cost' => money(num($in, 'cost', 0, 1000000)),
            'shipping' => money(num($in, 'shipping', 0, 100000, 0)),
            'other' => money(num($in, 'other', 0, 100000, 0)),
            'stock' => (int)num($in, 'stock', 0, 1000000),
            'reorder' => (int)num($in, 'reorder', 0, 100000, cfg('default_reorder')),
            'barcode' => $barcode,
        ];
        if ($barcode !== '') { // walang duplicate barcode
            foreach (load_products() as $p) if ($p['barcode'] === $barcode && $p['id'] !== $id) fail("May product na gumagamit ng barcode na ito: {$p['name']}", 409);
        }
        if ($id) {
            $old = db_get("products/p$id");
            if (!$old) fail('Hindi nahanap ang product.', 404);
            $rec['id'] = $id;
            $rec['icon'] = $old['icon'] ?? ($ICONS[$cat] ?? 'fa-box');
            $rec['createdAt'] = $old['createdAt'] ?? date('c');
            db_set("products/p$id", $rec);
            if ((int)$old['stock'] !== $rec['stock']) log_stock($id, 'adjust', $rec['stock'] - (int)$old['stock'], 'Edited sa inventory');
        } else {
            $id = next_id('productCounter');
            $rec['id'] = $id;
            $rec['icon'] = $ICONS[$cat] ?? 'fa-box';
            $rec['createdAt'] = date('c');
            db_set("products/p$id", $rec);
            log_stock($id, 'initial', $rec['stock'], 'Bagong product');
        }
        if ($rec['stock'] <= $rec['reorder']) {
            notify($rec['stock'] == 0 ? 'Out of stock' : 'Low stock', "{$rec['name']}: {$rec['stock']} na lang ang natitira.", $rec['stock'] == 0 ? 'danger' : 'warning');
        }
        out(['ok' => true, 'product' => prod_out($rec)]);

    case 'product_delete':
        $id = (int)($in['id'] ?? 0);
        if (!$id || !db_get("products/p$id")) fail('Hindi nahanap ang product.', 404);
        db_delete("products/p$id");
        out(['ok' => true]);

    /* ---------- sale (POS) ---------- */
    case 'sale':
        $lines = $in['items'] ?? null;
        if (!is_array($lines) || !$lines) fail('Walang laman ang cart.');
        $method = in_array($in['method'] ?? 'cash', ['cash', 'gcash', 'maya'], true) ? ($in['method'] ?? 'cash') : fail('Invalid payment method.');
        $cash = money($in['cash'] ?? 0);
        $ref = txt($in, 'ref', 40);
        $cashier = txt($in, 'cashier', 40) ?: 'Admin';

        seed_products_if_needed();
        $byId = [];
        foreach (load_products() as $p) $byId[$p['id']] = $p;

        $items = []; $need = [];
        foreach ($lines as $l) {
            $pid = (int)($l['id'] ?? 0);
            $qty = (int)($l['qty'] ?? 0);
            if (!isset($byId[$pid])) fail('May item sa cart na wala na sa inventory. I-refresh ang POS.', 409);
            if ($qty < 1 || $qty > 999) fail('Invalid quantity.');
            $need[$pid] = ($need[$pid] ?? 0) + $qty;
        }
        foreach ($need as $pid => $qty) {
            $p = $byId[$pid];
            if ($p['stock'] < $qty) fail("Kulang ang stock ng {$p['name']} (natitira: {$p['stock']}).", 409);
            $items[] = ['id' => $pid, 'name' => $p['name'], 'category' => $p['category'],
                        'qty' => $qty, 'price' => $p['price'], 'cost' => unit_cost($p)];
        }
        $n = next_id('trxCounter', 1000);
        $t = make_trx($n, $items, $method, $cash, $ref, $cashier, time());
        if ($method === 'cash' && $cash < $t['total']) fail('Kulang ang cash.');

        db_set('transactions/' . str_pad($n, 8, '0', STR_PAD_LEFT), $t);

        $newStock = [];
        foreach ($need as $pid => $qty) {
            $p = $byId[$pid];
            $ns = $p['stock'] - $qty;
            db_set("products/p$pid/stock", $ns);
            log_stock($pid, 'sale', -$qty, $t['receiptNo']);
            $newStock[$pid] = $ns;
            if ($ns <= 0 && $p['stock'] > 0) notify('Out of stock', "{$p['name']} ubos na.", 'danger');
            elseif ($ns <= $p['reorder'] && $p['stock'] > $p['reorder']) notify('Low stock', "{$p['name']}: {$ns} na lang ang natitira.", 'warning');
        }
        $day = db_get('dailySales/' . $t['date']) ?: [];
        day_add($day, $t);
        db_set('dailySales/' . $t['date'], $day);

        out(['ok' => true, 'receipt' => $t, 'stock' => $newStock]);

    /* ---------- reports ---------- */
    case 'reports':
        $N = max(1, min(31, (int)($_GET['days'] ?? 7)));
        $today = strtotime(date('Y-m-d'));
        $from = $today - ($N - 1) * 86400;
        $pfrom = $from - $N * 86400;
        $r = db_get('dailySales', ['orderBy' => '"$key"', 'startAt' => '"' . date('Y-m-d', $pfrom) . '"', 'endAt' => '"' . date('Y-m-d', $today) . '"']);
        $r = is_array($r) ? $r : [];

        $sum = function ($rows) {
            $t = ['revenue' => 0, 'cost' => 0, 'orders' => 0];
            foreach ($rows as $d) { $t['revenue'] += $d['revenue'] ?? 0; $t['cost'] += $d['cost'] ?? 0; $t['orders'] += $d['orders'] ?? 0; }
            $t['revenue'] = money($t['revenue']); $t['cost'] = money($t['cost']); $t['profit'] = money($t['revenue'] - $t['cost']);
            return $t;
        };
        $series = []; $cur = []; $prev = [];
        for ($i = 0; $i < 2 * $N; $i++) {
            $ts = $pfrom + $i * 86400; $dk = date('Y-m-d', $ts);
            $d = $r[$dk] ?? [];
            if ($i >= $N) {
                $cur[] = $d;
                $series[] = ['date' => $dk, 'label' => date('M j', $ts), 'revenue' => money($d['revenue'] ?? 0),
                             'cost' => money($d['cost'] ?? 0), 'profit' => money(($d['revenue'] ?? 0) - ($d['cost'] ?? 0)), 'orders' => $d['orders'] ?? 0];
            } else $prev[] = $d;
        }
        $tot = $sum($cur); $ptot = $sum($prev);
        $chg = function ($a, $b) { return $b > 0 ? round(($a - $b) / $b * 100) : null; };

        $cat = []; $pay = []; $prod = [];
        foreach ($cur as $d) {
            foreach ($d['byCategory'] ?? [] as $k => $v) { $cat[$k]['name'] = $k; $cat[$k]['cost'] = money(($cat[$k]['cost'] ?? 0) + $v['cost']); $cat[$k]['revenue'] = money(($cat[$k]['revenue'] ?? 0) + $v['revenue']); }
            foreach ($d['byPayment'] ?? [] as $k => $v) { $pay[$k]['name'] = $k; $pay[$k]['amount'] = money(($pay[$k]['amount'] ?? 0) + $v['amount']); $pay[$k]['orders'] = ($pay[$k]['orders'] ?? 0) + $v['orders']; }
            foreach ($d['products'] ?? [] as $k => $v) { $prod[$k]['name'] = $v['name']; $prod[$k]['cat'] = $v['cat'] ?? ''; $prod[$k]['qty'] = ($prod[$k]['qty'] ?? 0) + $v['qty']; $prod[$k]['revenue'] = money(($prod[$k]['revenue'] ?? 0) + $v['revenue']); }
        }
        $cat = array_values($cat); usort($cat, function ($a, $b) { return $b['cost'] <=> $a['cost']; });
        $pay = array_values($pay); usort($pay, function ($a, $b) { return $b['amount'] <=> $a['amount']; });
        $prod = array_values($prod); usort($prod, function ($a, $b) { return $b['qty'] <=> $a['qty']; });

        $rec = db_get('transactions', ['orderBy' => '"$key"', 'limitToLast' => 25]);
        $recent = [];
        if (is_array($rec)) {
            uasort($rec, function ($a, $b) { return ($b['tsMs'] ?? 0) <=> ($a['tsMs'] ?? 0); });
            foreach (array_slice($rec, 0, 6) as $t) $recent[] = ['ts' => $t['ts'], 'receiptNo' => $t['receiptNo'], 'items' => $t['itemCount'],
                                              'total' => $t['total'], 'method' => $t['method'], 'status' => $t['status']];
        }
        out(['ok' => true, 'days' => $N, 'range' => ['from' => date('Y-m-d', $from), 'to' => date('Y-m-d', $today)],
             'totals' => $tot, 'prev' => $ptot,
             'change' => ['revenue' => $chg($tot['revenue'], $ptot['revenue']), 'cost' => $chg($tot['cost'], $ptot['cost']),
                          'profit' => $chg($tot['profit'], $ptot['profit']), 'orders' => $chg($tot['orders'], $ptot['orders'])],
             'series' => $series, 'byCategory' => $cat, 'byPayment' => $pay, 'topProducts' => array_slice($prod, 0, 5),
             'recent' => $recent, 'demo' => (bool)cfg('enable_demo_seed'), 'demoLoaded' => (bool)db_get('meta/demoLoaded')]);

    /* ---------- demo sales (pang-test ng Reports) ---------- */
    case 'demo_sales':
        if (!cfg('enable_demo_seed')) fail('Naka-off ang demo sa config.php', 403);
        if (db_get('meta/demoLoaded')) fail('Naka-load na ang demo sales. I-clear muna.', 409);
        seed_products_if_needed();
        $prods = load_products();
        if (!$prods) fail('Walang products para sa demo.');
        $n = (int)(db_get('meta/trxCounter') ?? 1000);
        $batch = [];
        for ($back = 14; $back >= 1; $back--) {
            $dayTs = strtotime(date('Y-m-d')) - $back * 86400;
            mt_srand(crc32(date('Y-m-d', $dayTs)));
            $dow = (int)date('w', $dayTs); $dom = (int)date('j', $dayTs);
            $mult = ($dow >= 5 ? 1.3 : ($dow == 0 ? 1.1 : 1.0)) * (in_array($dom, [1, 15, 30, 31]) ? 1.25 : 1.0);
            $orders = (int)round(mt_rand(34, 46) * $mult);
            $times = [];
            for ($k = 0; $k < $orders; $k++) $times[] = $dayTs + mt_rand(7 * 3600, 21 * 3600);
            sort($times); // pataas ang oras para tama ang pagkakasunod ng receipt
            foreach ($times as $ts) {
                $items = []; $pick = array_rand($prods, mt_rand(1, min(5, count($prods))));
                foreach ((array)$pick as $ix) {
                    $p = $prods[$ix];
                    $items[] = ['id' => $p['id'], 'name' => $p['name'], 'category' => $p['category'],
                                'qty' => (mt_rand(1, 10) > 8 ? 2 : 1) + (mt_rand(1, 20) > 19 ? 1 : 0), 'price' => $p['price'], 'cost' => unit_cost($p)];
                }
                $roll = mt_rand(1, 100);
                $method = $roll <= 78 ? 'cash' : ($roll <= 95 ? 'gcash' : 'maya');
                $total = 0; foreach ($items as $it) $total += $it['price'] * $it['qty'];
                $cash = $method === 'cash' ? (ceil($total / 50) * 50) : $total;
                $n++;
                $t = make_trx($n, $items, $method, $cash, '', 'Admin', $ts, true);
                $batch[str_pad($n, 8, '0', STR_PAD_LEFT)] = $t;
            }
        }
        db_update('transactions', $batch);
        db_set('meta/trxCounter', $n);
        db_set('meta/demoLoaded', true);
        $days = rebuild_daily();
        out(['ok' => true, 'transactions' => count($batch), 'days' => $days]);

    case 'demo_clear':
        if (!cfg('enable_demo_seed')) fail('Naka-off ang demo sa config.php', 403);
        $all = db_get('transactions');
        $del = 0;
        if (is_array($all)) foreach ($all as $k => $t) if (!empty($t['demo'])) { db_delete("transactions/$k"); $del++; }
        db_delete('meta/demoLoaded');
        rebuild_daily();
        out(['ok' => true, 'deleted' => $del]);

    /* ---------- notifications ---------- */
    case 'notifications':
        $raw = db_get('notifications/main', ['orderBy' => '"$key"', 'limitToLast' => 30]);
        $list = []; $unread = 0;
        if (is_array($raw)) {
            krsort($raw);
            foreach ($raw as $id => $n) { $n['id'] = $id; $list[] = $n; if (empty($n['read'])) $unread++; }
        }
        out(['ok' => true, 'unread' => $unread, 'notifications' => $list]);

    case 'notif_read':
        if (!empty($in['all'])) {
            $raw = db_get('notifications/main');
            if (is_array($raw)) foreach ($raw as $id => $n) if (empty($n['read'])) db_set("notifications/main/$id/read", true);
        } else {
            $id = preg_replace('/[^A-Za-z0-9]/', '', (string)($in['id'] ?? ''));
            if ($id && db_get("notifications/main/$id")) db_set("notifications/main/$id/read", true);
        }
        out(['ok' => true]);

    /* ---------- employees & payroll ---------- */
    case 'payroll':
        $pid = $_GET['period'] ?? period_id_for(time());
        $period = period_info($pid);
        if (!$period) fail('Invalid period.');
        $emps = db_get('employees');
        $inputs = db_get("payroll/$pid");
        $inputs = is_array($inputs) ? $inputs : [];
        $rows = []; $tot = ['gross' => 0, 'deductions' => 0, 'net' => 0];
        if (is_array($emps)) foreach ($emps as $k => $e) {
            $inp = $inputs[$k] ?? [];
            $pay = compute_pay((float)$e['dailyRate'], $inp);
            $rows[] = ['id' => (int)$e['id'], 'name' => $e['name'], 'position' => $e['position'], 'dailyRate' => money($e['dailyRate']),
                       'daysWorked' => (float)($inp['daysWorked'] ?? 0), 'otHours' => (float)($inp['otHours'] ?? 0),
                       'lateMin' => (float)($inp['lateMin'] ?? 0), 'otherDed' => money($inp['otherDed'] ?? 0),
                       'status' => $inp['status'] ?? 'Pending', 'pay' => $pay];
            $tot['gross'] += $pay['gross']; $tot['deductions'] += $pay['deductions']; $tot['net'] += $pay['net'];
        }
        usort($rows, function ($a, $b) { return $a['id'] <=> $b['id']; });
        out(['ok' => true, 'period' => $period, 'periods' => recent_periods(), 'employees' => $rows,
             'totals' => ['count' => count($rows), 'gross' => money($tot['gross']), 'deductions' => money($tot['deductions']), 'net' => money($tot['net'])]]);

    case 'employee_save':
        $id = isset($in['id']) && $in['id'] !== '' ? (int)$in['id'] : 0;
        $rec = ['name' => txt($in, 'name', 60, true), 'position' => txt($in, 'position', 40, true),
                'dailyRate' => money(num($in, 'dailyRate', 0, 10000))];
        if ($id) {
            $old = db_get("employees/e$id");
            if (!$old) fail('Hindi nahanap ang employee.', 404);
            $rec['id'] = $id; $rec['createdAt'] = $old['createdAt'] ?? date('c');
        } else { $id = next_id('employeeCounter'); $rec['id'] = $id; $rec['createdAt'] = date('c'); }
        db_set("employees/e$id", $rec);
        out(['ok' => true, 'employee' => $rec]);

    case 'employee_delete':
        $id = (int)($in['id'] ?? 0);
        if (!$id || !db_get("employees/e$id")) fail('Hindi nahanap ang employee.', 404);
        db_delete("employees/e$id");
        out(['ok' => true]);

    case 'payroll_save':
        $pid = txt($in, 'period', 10, true);
        if (!period_info($pid)) fail('Invalid period.');
        $id = (int)($in['emp'] ?? 0);
        if (!db_get("employees/e$id")) fail('Hindi nahanap ang employee.', 404);
        $old = db_get("payroll/$pid/e$id") ?: [];
        db_set("payroll/$pid/e$id", [
            'daysWorked' => num($in, 'daysWorked', 0, 16, 0), 'otHours' => num($in, 'otHours', 0, 200, 0),
            'lateMin' => num($in, 'lateMin', 0, 10000, 0), 'otherDed' => money(num($in, 'otherDed', 0, 100000, 0)),
            'status' => $old['status'] ?? 'Pending', 'updatedAt' => date('c'),
        ]);
        out(['ok' => true]);

    case 'payroll_status':
        $pid = txt($in, 'period', 10, true);
        if (!period_info($pid)) fail('Invalid period.');
        $id = (int)($in['emp'] ?? 0);
        $st = ($in['status'] ?? '') === 'Processed' ? 'Processed' : 'Pending';
        if (!db_get("employees/e$id")) fail('Hindi nahanap ang employee.', 404);
        db_set("payroll/$pid/e$id/status", $st);
        if ($st === 'Processed') notify('Payroll processed', db_get("employees/e$id/name") . ' - ' . period_info($pid)['label'], 'info');
        out(['ok' => true]);

    default:
        fail('Unknown action.', 404);
    }
} catch (DbError $e) {
    fail($e->getMessage(), 502);
} catch (Throwable $e) {
    error_log('[tindahan] ' . $e->getMessage());
    fail('Server error: ' . $e->getMessage(), 500);
}
