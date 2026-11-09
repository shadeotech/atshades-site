<?php
// Shared helpers for the At Shades API. Not an endpoint.
if (!defined('ATS_API')) { http_response_code(404); exit; }
ini_set('display_errors', '0');   // never leak warnings into JSON replies

function cfg() {
  static $c = null;
  if ($c === null) {
    $f = __DIR__ . '/config.php';
    if (!is_file($f)) fail(500, 'Server not configured (api/config.php missing).');
    $c = require $f;
  }
  return $c;
}

function respond($code, $data) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data);
  exit;
}
function fail($code, $msg) { respond($code, ['ok' => false, 'error' => $msg]); }

function cors() {
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  $allowed = cfg()['allowed_origins'] ?? [];
  if ($origin !== '') {
    $self = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
    if ($origin !== $self && !in_array($origin, $allowed, true)) fail(403, 'Origin not allowed.');
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
  }
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

function data_dir() {
  $d = rtrim(cfg()['data_dir'] ?? (__DIR__ . '/../data'), '/');
  if (!is_dir($d)) @mkdir($d, 0750, true);
  return $d;
}

function read_json_body() {
  $raw = file_get_contents('php://input');
  if (strlen($raw) > 20000) fail(413, 'Request too large.');
  $j = json_decode($raw, true);
  if (!is_array($j)) fail(400, 'Invalid request.');
  return $j;
}

function clean($v, $max = 200) {
  if (!is_string($v)) $v = is_scalar($v) ? (string)$v : '';
  $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v));
  return mb_substr($v, 0, $max);
}
function one_line($v, $max = 200) { return clean(preg_replace('/[\r\n]+/', ' ', (string)$v), $max); }
function valid_email($e) { return (bool)filter_var($e, FILTER_VALIDATE_EMAIL); }

function client_ip() { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

function rate_limit($bucket = 'forms', $limit = null) {
  $limit = $limit ?? (int)(cfg()['rate_limit_per_hour'] ?? 12);
  $f = data_dir() . '/ratelimit-' . preg_replace('/[^a-z]/', '', $bucket) . '.json';
  $fh = fopen($f, 'c+');
  if (!$fh) return;
  flock($fh, LOCK_EX);
  $all = json_decode(stream_get_contents($fh) ?: '{}', true) ?: [];
  $now = time(); $key = hash('sha256', client_ip());
  foreach ($all as $k => $ts) { $all[$k] = array_values(array_filter($ts, fn($t) => $t > $now - 3600)); if (!$all[$k]) unset($all[$k]); }
  $hits = $all[$key] ?? [];
  if (count($hits) >= $limit) { flock($fh, LOCK_UN); fclose($fh); fail(429, 'Too many requests. Please try again later or call us.'); }
  $hits[] = $now; $all[$key] = $hits;
  ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($all));
  flock($fh, LOCK_UN); fclose($fh);
}

// Bots that post instantly or fill the hidden field are dropped silently.
function looks_like_bot($body) {
  if (!empty($body['website'])) return true;
  if (isset($body['elapsed']) && (int)$body['elapsed'] < 2500) return true;
  return false;
}

function log_lead($lead) {
  $f = data_dir() . '/leads.jsonl';
  file_put_contents($f, json_encode($lead, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

function franchise_inbox($city) {
  $map = cfg()['franchise_inboxes'] ?? [];
  $e = $map[$city] ?? '';
  return valid_email($e) ? $e : null;
}

// ---------------------------------------------------------------- email ----

function send_mail($to, $subject, $text, $opts = []) {
  $c = cfg();
  $to = array_values(array_unique(array_filter((array)$to, 'valid_email')));
  if (!$to) return false;
  $fromEmail = $c['from_email']; $fromName = $c['from_name'] ?? '';
  $replyTo = $opts['reply_to'] ?? null;
  $boundary = 'b' . bin2hex(random_bytes(12));
  $enc = fn($s) => '=?UTF-8?B?' . base64_encode($s) . '?=';

  $headers = [
    'Date: ' . date('r'),
    'From: ' . $enc($fromName) . " <$fromEmail>",
    'To: ' . implode(', ', $to),
    'Subject: ' . $enc($subject),
    'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . substr(strrchr($fromEmail, '@'), 1) . '>',
    'MIME-Version: 1.0',
  ];
  if ($replyTo && valid_email($replyTo)) $headers[] = "Reply-To: $replyTo";

  $textPart = chunk_split(base64_encode($text));
  if (!empty($opts['ics'])) {
    $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n$textPart"
      . "--$boundary\r\nContent-Type: text/calendar; charset=UTF-8; method=REQUEST\r\nContent-Transfer-Encoding: base64\r\n"
      . "Content-Disposition: attachment; filename=\"invite.ics\"\r\n\r\n" . chunk_split(base64_encode($opts['ics']))
      . "--$boundary--\r\n";
  } else {
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: base64';
    $body = $textPart;
  }

  $smtp = $c['smtp'] ?? null;
  if ($smtp && !empty($smtp['host']) && ($smtp['password'] ?? '') !== 'CHANGE_ME') {
    try { return smtp_send($smtp, $fromEmail, $to, implode("\r\n", $headers) . "\r\n\r\n" . $body); }
    catch (Throwable $e) { error_log('[atshades] SMTP failed: ' . $e->getMessage()); }
  }
  // Fallback: PHP mail() (works on Hostinger, weaker deliverability).
  $h = array_filter($headers, fn($l) => stripos($l, 'To:') !== 0 && stripos($l, 'Subject:') !== 0);
  return @mail(implode(', ', $to), $enc($subject), $body, implode("\r\n", $h), "-f$fromEmail");
}

function smtp_send($s, $from, $rcpts, $data) {
  $host = ($s['secure'] ?? 'ssl') === 'ssl' ? 'ssl://' . $s['host'] : $s['host'];
  $fp = @stream_socket_client("$host:" . (int)$s['port'], $errno, $err, 15);
  if (!$fp) throw new Exception("connect: $err");
  stream_set_timeout($fp, 15);
  $read = function () use ($fp) {
    $out = '';
    while (($line = fgets($fp, 515)) !== false) { $out .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
    return $out;
  };
  $cmd = function ($c, $expect) use ($fp, $read) {
    if ($c !== null) fwrite($fp, $c . "\r\n");
    $r = $read();
    if ((int)substr($r, 0, 3) !== $expect) throw new Exception('SMTP ' . trim($r) . ' after ' . strtok((string)$c, ' '));
    return $r;
  };
  $me = $_SERVER['SERVER_NAME'] ?? 'localhost';
  $cmd(null, 220);
  $cmd("EHLO $me", 250);
  if (($s['secure'] ?? '') === 'tls') {
    $cmd('STARTTLS', 220);
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new Exception('STARTTLS failed');
    $cmd("EHLO $me", 250);
  }
  $cmd('AUTH LOGIN', 334);
  $cmd(base64_encode($s['username']), 334);
  $cmd(base64_encode($s['password']), 235);
  $cmd("MAIL FROM:<$from>", 250);
  foreach ($rcpts as $r) $cmd("RCPT TO:<$r>", 250);
  $cmd('DATA', 354);
  $data = preg_replace('/^\./m', '..', $data);   // dot-stuffing
  $cmd($data . "\r\n.", 250);
  fwrite($fp, "QUIT\r\n"); fclose($fp);
  return true;
}

// ------------------------------------------------------------------ ERA ----

function push_to_era($lead) {
  $e = cfg()['era'] ?? [];
  if (empty($e['enabled']) || empty($e['url'])) return null;
  foreach ($e['headers'] ?? [] as $h) if (strpos($h, 'CHANGE_ME') !== false) return null;   // not set up yet
  $payload = [];
  foreach ($lead as $k => $v) $payload[$e['field_map'][$k] ?? $k] = $v;
  $ch = curl_init($e['url']);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $e['headers'] ?? []),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => (int)($e['timeout'] ?? 8),
  ]);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  $ok = $code >= 200 && $code < 300;
  if (!$ok) {
    error_log("[atshades] ERA push failed ($code) $err " . substr((string)$res, 0, 300));
    // Keep failed pushes so they can be replayed.
    file_put_contents(data_dir() . '/era-failed.jsonl', json_encode(['lead' => $lead, 'code' => $code, 'error' => $err]) . "\n", FILE_APPEND | LOCK_EX);
  }
  return $ok;
}

function format_lines($pairs) {
  $out = '';
  foreach ($pairs as $k => $v) if ($v !== '' && $v !== null) $out .= str_pad($k . ':', 14) . $v . "\n";
  return $out;
}

// -------------------------------------------------------------- booking ----

function bookings_path() { return data_dir() . '/bookings.json'; }

function load_bookings() {
  $f = bookings_path();
  return is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
}

// Upcoming weekday slots in the team's timezone, as UTC timestamps.
function booking_slots() {
  $b = cfg()['booking'];
  $tz = new DateTimeZone($b['timezone'] ?? 'America/Chicago');
  $now = time();
  $earliest = $now + 3600 * (int)($b['min_notice_hours'] ?? 12);
  $taken = array_flip(array_map(fn($x) => $x['start'], load_bookings()));
  $days = [];
  $d = new DateTime('tomorrow', $tz);
  $guard = 0;
  while (count($days) < (int)($b['days_ahead'] ?? 10) && $guard++ < 60) {
    $ymd = $d->format('Y-m-d');
    if ((int)$d->format('N') <= 5 && !in_array($ymd, $b['blocked_dates'] ?? [], true)) {
      $slots = [];
      foreach ($b['slots'] as $hm) {
        $t = DateTime::createFromFormat('Y-m-d H:i', "$ymd $hm", $tz)->getTimestamp();
        $slots[] = ['start' => $t, 'taken' => isset($taken[$t]) || $t < $earliest];
      }
      $days[] = ['date' => $ymd, 'slots' => $slots];
    }
    $d->modify('+1 day');
  }
  return $days;
}

function build_ics($uid, $start, $minutes, $summary, $description, $organizer, $attendee, $location = '') {
  $fmt = fn($t) => gmdate('Ymd\THis\Z', $t);
  $esc = fn($s) => str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\;", "\\,", "\\n", "\\n"], $s);
  $lines = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//At Shades//Booking//EN', 'METHOD:REQUEST', 'CALSCALE:GREGORIAN',
    'BEGIN:VEVENT',
    "UID:$uid", 'DTSTAMP:' . $fmt(time()), 'DTSTART:' . $fmt($start), 'DTEND:' . $fmt($start + 60 * $minutes),
    'SUMMARY:' . $esc($summary), 'DESCRIPTION:' . $esc($description),
    $location !== '' ? 'LOCATION:' . $esc($location) : null,
    "ORGANIZER;CN=At Shades:mailto:$organizer",
    "ATTENDEE;ROLE=REQ-PARTICIPANT;RSVP=TRUE:mailto:$attendee",
    'STATUS:CONFIRMED', 'END:VEVENT', 'END:VCALENDAR',
  ];
  return implode("\r\n", array_filter($lines, fn($l) => $l !== null)) . "\r\n";
}

// ---------------------------------------------------------------- http -----

function http_get_json($url, $headers = [], $timeout = 8) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
  ]);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  return ($res !== false && $code >= 200 && $code < 300) ? json_decode($res, true) : null;
}

// Initial compass bearing from a to b, degrees clockwise from north.
function bearing_deg($lat1, $lng1, $lat2, $lng2) {
  $r = M_PI / 180;
  $y = sin(($lng2 - $lng1) * $r) * cos($lat2 * $r);
  $x = cos($lat1 * $r) * sin($lat2 * $r) - sin($lat1 * $r) * cos($lat2 * $r) * cos(($lng2 - $lng1) * $r);
  return fmod(atan2($y, $x) / $r + 360, 360);
}
