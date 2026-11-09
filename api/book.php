<?php
// POST /api/book.php — book a franchise meeting and send calendar invites.
define('ATS_API', 1);
require __DIR__ . '/lib.php';

cors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST only.');
$b = read_json_body();
if (looks_like_bot($b)) respond(200, ['ok' => true]);
rate_limit();

$c = cfg(); $bk = $c['booking'];
$name  = one_line($b['name'] ?? '', 120);
$email = one_line($b['email'] ?? '', 160);
$platform = one_line($b['platform'] ?? '', 40);
$start = (int)($b['start'] ?? 0);
if ($name === '' || !valid_email($email)) fail(422, 'Please add your name and a valid email for the invite.');
if (!array_key_exists($platform, $bk['links'] ?? [])) $platform = 'Zoom';

// Is the requested time one we actually offer and still free?
$offered = false;
foreach (booking_slots() as $day) foreach ($day['slots'] as $s) if ($s['start'] === $start && !$s['taken']) $offered = true;
if (!$offered) fail(409, 'That time was just taken. Please pick another.');

// Reserve it under a lock so two people can't take the same slot.
$fh = fopen(bookings_path(), 'c+');
flock($fh, LOCK_EX);
$all = json_decode(stream_get_contents($fh) ?: '[]', true) ?: [];
foreach ($all as $x) if ($x['start'] === $start) { flock($fh, LOCK_UN); fclose($fh); fail(409, 'That time was just taken. Please pick another.'); }
$id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
$all = array_values(array_filter($all, fn($x) => $x['start'] > time() - 86400 * 60));   // prune old
$all[] = ['id' => $id, 'start' => $start, 'name' => $name, 'email' => $email, 'platform' => $platform, 'created' => date('c')];
ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
flock($fh, LOCK_UN); fclose($fh);

$minutes = (int)($bk['minutes'] ?? 30);
$link = $bk['links'][$platform] ?? '';
$tz = new DateTimeZone($bk['timezone']);
$when = (new DateTime('@' . $start))->setTimezone($tz)->format('l, F j, Y \a\t g:i a T');

$lead = [
  'id' => $id, 'type' => 'franchise_meeting', 'received' => date('c'),
  'name' => $name, 'email' => $email, 'platform' => $platform,
  'meeting_start' => gmdate('c', $start), 'meeting_minutes' => $minutes,
  'ip' => client_ip(),
];
log_lead($lead);
$era = push_to_era($lead);

$team = $c['franchise_team_inbox'] ?? $c['main_inbox'];
$desc = "Franchise introduction call with the At Shades franchise development team.\n"
  . ($link ? "Join: $link" : "The meeting link will be sent by the team before the call.");
$ics = build_ics("$id@atshades", $start, $minutes, "At Shades franchise call — $name", $desc, $c['from_email'], $email, $link ?: $platform);

send_mail($email, 'Your At Shades franchise call — ' . $when,
  "Hi " . strtok($name, ' ') . ",\n\nYou're booked for a $minutes-minute call on $platform:\n$when\n\n"
  . ($link ? "Join here: $link\n\n" : "We'll email the $platform link before the call.\n\n")
  . "The calendar invite is attached. Need to change the time? Just reply to this email.\n\nAt Shades franchise development\n",
  ['ics' => $ics, 'reply_to' => $team]);

send_mail([$team], "Franchise call booked — $name, $when",
  "New franchise meeting\n\n" . format_lines(['Name' => $name, 'Email' => $email, 'When' => $when, 'Platform' => $platform, 'Booking ID' => $id])
  . ($link ? '' : "\nNo fixed $platform link is configured. Send one to $email before the call.\n")
  . ($era === false ? "\nNote: the push to ERA failed; it is saved for retry.\n" : ''),
  ['ics' => $ics, 'reply_to' => $email]);

respond(200, ['ok' => true, 'id' => $id, 'when' => $when]);
