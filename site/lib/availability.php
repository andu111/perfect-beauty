<?php
if (!defined('PB')) { http_response_code(404); exit; }

function hours_for(int $weekday): ?array {
    static $all;
    $all ??= array_column(db()->query('SELECT * FROM hours')->fetchAll(), null, 'weekday');
    $h = $all[$weekday] ?? null;
    return ($h && $h['open'] && $h['close']) ? $h : null;
}

/** Busy intervals (minutes from midnight) on a date: bookings (+buffer), breaks and time off. */
function busy_on(string $date, ?int $ignoreBookingId = null): array {
    $buffer = (int)setting('buffer', 0);
    $busy = [];
    $q = db()->prepare("SELECT id,start_at,end_at FROM bookings WHERE status IN " . PB_STATUSES_BUSY .
        " AND start_at < ? AND end_at > ?");
    $q->execute(["$date 24:00", "$date 00:00"]);
    foreach ($q as $b) {
        if ($ignoreBookingId && (int)$b['id'] === $ignoreBookingId) continue;
        $busy[] = [clamp_min($b['start_at'], $date), clamp_min($b['end_at'], $date) + $buffer];
    }
    $q = db()->prepare('SELECT start_at,end_at FROM time_off WHERE start_at < ? AND end_at > ?');
    $q->execute(["$date 24:00", "$date 00:00"]);
    foreach ($q as $t) $busy[] = [clamp_min($t['start_at'], $date), clamp_min($t['end_at'], $date)];
    return $busy;
}

/** Minutes from midnight of $date for a 'Y-m-d H:i' stamp, clamped to that day. */
function clamp_min(string $stamp, string $date): int {
    $d = substr($stamp, 0, 10);
    if ($d < $date) return 0;
    if ($d > $date) return 1440;
    return hm_to_min(substr($stamp, 11, 5));
}

/** Start times ('HH:MM') a service of $dur minutes can be booked on $date. */
function day_slots(string $date, int $dur, bool $forCustomer = true, ?int $ignoreBookingId = null): array {
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, tz());
    $h = hours_for((int)$day->format('w'));
    if (!$h) return [];

    $open = hm_to_min($h['open']); $close = hm_to_min($h['close']);
    $step = max(5, (int)setting('slot_step', 15));
    $buffer = (int)setting('buffer', 0);
    $busy = busy_on($date, $ignoreBookingId);
    if ($h['break_start'] && $h['break_end']) $busy[] = [hm_to_min($h['break_start']), hm_to_min($h['break_end'])];

    $earliest = -1;
    if ($forCustomer) {
        $first = now()->modify('+' . (int)setting('min_notice_hours', 12) . ' hours');
        $last = now()->setTime(0, 0)->modify('+' . (int)setting('max_days', 60) . ' days');
        if ($day > $last) return [];
        $fd = $first->format('Y-m-d');
        if ($date < $fd) return [];
        if ($date === $fd) $earliest = hm_to_min($first->format('H:i'));
    }

    $slots = [];
    for ($s = $open; $s + $dur <= $close; $s += $step) {
        if ($s < $earliest) continue;
        $e = $s + $dur + $buffer;
        foreach ($busy as [$bs, $be]) if ($s < $be && $e > $bs) continue 2;
        $slots[] = min_to_hm($s);
    }
    return $slots;
}

/** Does a booking at $date $time for $dur clash with anything (ignores opening hours and notice)? */
function clashes(string $date, string $time, int $dur, ?int $ignoreBookingId = null): bool {
    $s = hm_to_min($time); $e = $s + $dur;
    foreach (busy_on($date, $ignoreBookingId) as [$bs, $be]) if ($s < $be && $e > $bs) return true;
    return false;
}

function end_stamp(string $date, string $time, int $dur): string {
    return DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time", tz())->modify("+$dur minutes")->format('Y-m-d H:i');
}

/** Public weekly hours for the website. */
function hours_list(): array {
    $out = [];
    foreach (db()->query('SELECT * FROM hours ORDER BY weekday')->fetchAll() as $h)
        $out[] = ['weekday' => (int)$h['weekday'], 'open' => $h['open'], 'close' => $h['close'],
                  'break_start' => $h['break_start'], 'break_end' => $h['break_end']];
    return $out;
}
