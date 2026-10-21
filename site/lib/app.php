<?php
/*
 * Perfect Beauty booking system: shared setup.
 * Data lives in perfect-beauty-data/ next to the website folder, never inside it.
 */
if (!defined('PB')) { http_response_code(404); exit; }

date_default_timezone_set('Europe/London');
define('PB_DATA', getenv('PB_DATA_DIR') ?: dirname(__DIR__, 2) . '/perfect-beauty-data');
define('PB_STATUSES_BUSY', "('pending','accepted')");

function tz(): DateTimeZone { static $t; return $t ??= new DateTimeZone('Europe/London'); }
function now(): DateTimeImmutable { return new DateTimeImmutable('now', tz()); }

function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    if (!is_dir(PB_DATA) && !mkdir(PB_DATA, 0700, true)) throw new RuntimeException('Cannot create data folder');
    $pdo = new PDO('sqlite:' . PB_DATA . '/app.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 8000; PRAGMA foreign_keys = ON;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v < 1) migrate_v1($pdo);
    if ($v < 2) {
        // Extra admin accounts. Alexandra's password stays in settings.admin_hash (account "alexandra").
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (name TEXT PRIMARY KEY, hash TEXT NOT NULL, created_at TEXT NOT NULL);");
        $cols = array_column($pdo->query('PRAGMA table_info(admin_tokens)')->fetchAll(), 'name');
        if (!in_array('user', $cols, true)) $pdo->exec('ALTER TABLE admin_tokens ADD COLUMN user TEXT');
        $pdo->exec('PRAGMA user_version = 2');
    }
    if ($v < 3) {
        // Each phone can be switched on or off for alerts; email alerts get their own switch.
        $cols = array_column($pdo->query('PRAGMA table_info(push_subs)')->fetchAll(), 'name');
        if (!in_array('enabled', $cols, true)) $pdo->exec('ALTER TABLE push_subs ADD COLUMN enabled INTEGER NOT NULL DEFAULT 1');
        $pdo->exec("INSERT OR IGNORE INTO settings(key,value) VALUES('email_alerts','1'); PRAGMA user_version = 3;");
    }
}

function migrate_v1(PDO $pdo): void {
    $pdo->exec("
      CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
      CREATE TABLE services (id INTEGER PRIMARY KEY, category TEXT NOT NULL, name TEXT NOT NULL,
        price INTEGER NOT NULL, duration INTEGER NOT NULL, active INTEGER NOT NULL DEFAULT 1, sort INTEGER NOT NULL DEFAULT 0);
      CREATE TABLE hours (weekday INTEGER PRIMARY KEY, open TEXT, close TEXT, break_start TEXT, break_end TEXT);
      CREATE TABLE time_off (id INTEGER PRIMARY KEY, start_at TEXT NOT NULL, end_at TEXT NOT NULL, note TEXT);
      CREATE TABLE bookings (id INTEGER PRIMARY KEY, ref TEXT UNIQUE NOT NULL, token TEXT UNIQUE NOT NULL,
        service_id INTEGER, service_name TEXT NOT NULL, price INTEGER NOT NULL, duration INTEGER NOT NULL,
        start_at TEXT NOT NULL, end_at TEXT NOT NULL, name TEXT NOT NULL, phone TEXT NOT NULL, email TEXT,
        notes TEXT, status TEXT NOT NULL, source TEXT NOT NULL DEFAULT 'online', reason TEXT,
        created_at TEXT NOT NULL, decided_at TEXT, ip TEXT);
      CREATE INDEX bookings_start ON bookings(start_at);
      CREATE INDEX bookings_status ON bookings(status);
      CREATE TABLE push_subs (id INTEGER PRIMARY KEY, endpoint TEXT UNIQUE NOT NULL, p256dh TEXT NOT NULL,
        auth TEXT NOT NULL, label TEXT, created_at TEXT NOT NULL);
      CREATE TABLE admin_tokens (hash TEXT PRIMARY KEY, expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL, ua TEXT);
      CREATE TABLE attempts (ip TEXT, kind TEXT, at INTEGER);
    ");
    $set = $pdo->prepare('INSERT INTO settings(key,value) VALUES(?,?)');
    foreach ([
        'business_name' => 'Perfect Beauty',
        'site_url' => 'https://perfect-beauty.uk',
        'notify_email' => '',
        'from_email' => 'bookings@perfect-beauty.uk',
        'slot_step' => '15',          // minutes between possible start times
        'buffer' => '0',              // minutes kept free after each appointment
        'min_notice_hours' => '12',   // how soon a customer can book
        'max_days' => '60',           // how far ahead customers can book
        'cancel_hours' => '24',       // customers can cancel online up to this many hours before
        'deposit_percent' => '0',     // used once online payments are connected
        'admin_hash' => '',
    ] as $k => $val) $set->execute([$k, $val]);

    // Example hours until she sets her own (0 = Sunday).
    $h = $pdo->prepare('INSERT INTO hours(weekday,open,close,break_start,break_end) VALUES(?,?,?,?,?)');
    foreach ([[0, null, null], [1, '09:30', '18:00'], [2, '09:30', '18:00'], [3, '09:30', '18:00'],
              [4, '09:30', '18:00'], [5, '09:30', '18:00'], [6, '10:00', '16:00']] as [$d, $o, $c])
        $h->execute([$d, $o, $c, null, null]);

    $svc = $pdo->prepare('INSERT INTO services(category,name,price,duration,sort) VALUES(?,?,?,?,?)');
    $i = 0;
    foreach (require __DIR__ . '/seed-services.php' as $cat => $items)
        foreach ($items as [$name, $price, $dur]) $svc->execute([$cat, $name, $price * 100, $dur, $i++]);

    $pdo->exec('PRAGMA user_version = 1');
}

/* ---------- admin accounts ---------- */
const PB_OWNER = 'alexandra';
/** Hash for an admin account, or '' if it has no password yet. */
function admin_user_hash(string $name): string {
    if ($name === PB_OWNER) return setting('admin_hash', '');
    $q = db()->prepare('SELECT hash FROM admin_users WHERE name=?'); $q->execute([$name]);
    return (string)($q->fetchColumn() ?: '');
}
function set_admin_user_hash(string $name, string $hash): void {
    if ($name === PB_OWNER) { set_setting('admin_hash', $hash); return; }
    db()->prepare('INSERT INTO admin_users(name,hash,created_at) VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET hash=excluded.hash')
        ->execute([$name, $hash, date('Y-m-d H:i:s')]);
}
/** All accounts that have a password: name => hash. */
function admin_accounts(): array {
    $a = [];
    if (setting('admin_hash', '') !== '') $a[PB_OWNER] = setting('admin_hash');
    foreach (db()->query('SELECT name,hash FROM admin_users')->fetchAll() as $r) $a[$r['name']] = $r['hash'];
    return $a;
}

/* ---------- settings ---------- */
function setting(string $key, $default = null) {
    static $cache;
    if ($cache === null) $cache = db()->query('SELECT key,value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    if (func_num_args() === 1 && $key === '__reset') { $cache = null; return null; }
    return $cache[$key] ?? $default;
}
function set_setting(string $key, string $value): void {
    db()->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')->execute([$key, $value]);
    setting('__reset');
}

/* ---------- http helpers ---------- */
function json_out($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400, array $extra = []): never { json_out(['ok' => false, 'error' => $msg] + $extra, $code); }
function body(): array {
    $d = json_decode(file_get_contents('php://input') ?: '[]', true);
    return is_array($d) ? $d : [];
}
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

/** Simple rate limit: returns false if this IP did $kind more than $max times in $window seconds. */
function rate_ok(string $kind, int $max, int $window, bool $record = true): bool {
    $pdo = db(); $t = time();
    $pdo->prepare('DELETE FROM attempts WHERE at < ?')->execute([$t - 86400]);
    $q = $pdo->prepare('SELECT COUNT(*) FROM attempts WHERE ip=? AND kind=? AND at>?');
    $q->execute([client_ip(), $kind, $t - $window]);
    if ((int)$q->fetchColumn() >= $max) return false;
    if ($record) $pdo->prepare('INSERT INTO attempts(ip,kind,at) VALUES(?,?,?)')->execute([client_ip(), $kind, $t]);
    return true;
}

/* ---------- formatting ---------- */
function hm_to_min(string $hm): int { [$h, $m] = array_map('intval', explode(':', $hm)); return $h * 60 + $m; }
function min_to_hm(int $m): string { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }
function money(int $pence): string { return '£' . ($pence % 100 ? number_format($pence / 100, 2) : intdiv($pence, 100)); }
function nice_when(string $startAt): string {
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i', $startAt, tz());
    return $d ? $d->format('D j M') . ' at ' . $d->format('H:i') : $startAt;
}
function dur_text(int $m): string { return $m >= 60 ? intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : '') : $m . 'm'; }
function valid_hm(?string $s): bool { return is_string($s) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s) === 1; }
function valid_date(?string $s): bool {
    if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, tz());
    return $d && $d->format('Y-m-d') === $s;
}
function site_url(string $path = ''): string { return rtrim(setting('site_url', 'https://perfect-beauty.uk'), '/') . $path; }

function booking_public(array $b): array {
    return [
        'id' => (int)$b['id'], 'ref' => $b['ref'], 'service' => $b['service_name'], 'price' => (int)$b['price'],
        'duration' => (int)$b['duration'], 'start' => $b['start_at'], 'end' => $b['end_at'],
        'name' => $b['name'], 'phone' => $b['phone'], 'email' => $b['email'], 'notes' => $b['notes'],
        'status' => $b['status'], 'source' => $b['source'], 'reason' => $b['reason'],
        'created' => $b['created_at'], 'decided' => $b['decided_at'],
    ];
}
