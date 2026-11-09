<?php
// POST /api/lead.php  — consult requests, franchise enquiries, quiz results.
define('ATS_API', 1);
require __DIR__ . '/lib.php';

cors();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST only.');
$b = read_json_body();
$type = $b['type'] ?? '';
if (!in_array($type, ['consult', 'franchise', 'quiz'], true)) fail(400, 'Unknown form.');
if (looks_like_bot($b)) respond(200, ['ok' => true]);   // pretend success

$c = cfg();
$email = one_line($b['email'] ?? '', 160);
$anonQuiz = $type === 'quiz' && $email === '';

if (!$anonQuiz) rate_limit();
if ($email !== '' && !valid_email($email)) fail(422, 'Please enter a valid email address.');

$lead = [
  'id'        => date('Ymd-His') . '-' . bin2hex(random_bytes(3)),
  'type'      => $type,
  'received'  => date('c'),
  'name'      => one_line($b['name'] ?? '', 120),
  'email'     => $email,
  'phone'     => one_line($b['phone'] ?? '', 40),
  'zip'       => one_line($b['zip'] ?? '', 40),
  'franchise' => one_line($b['franchise'] ?? '', 80),
  'page'      => one_line($b['page'] ?? '', 200),
  'lat'       => is_numeric($b['lat'] ?? null) ? max(-90, min(90, (float)$b['lat'])) : null,
  'lng'       => is_numeric($b['lng'] ?? null) ? max(-180, min(180, (float)$b['lng'])) : null,
  'ip'        => client_ip(),
  'user_agent'=> one_line($_SERVER['HTTP_USER_AGENT'] ?? '', 250),
];

// Only trust a franchise name that exists in config.
if ($lead['franchise'] !== '' && !array_key_exists($lead['franchise'], $c['franchise_inboxes'] ?? [])) $lead['franchise'] = '';

$quiz = null;
if (isset($b['quiz']) && is_array($b['quiz'])) {
  $q = $b['quiz'];
  $quiz = [
    'recommended' => one_line($q['recommended'] ?? '', 80),
    'runner_up'   => one_line($q['runner_up'] ?? '', 80),
    'answers'     => array_slice(array_map(fn($a) => [
      'q' => one_line($a['q'] ?? '', 160), 'a' => one_line($a['a'] ?? '', 160)
    ], array_filter((array)($q['answers'] ?? []), 'is_array')), 0, 10),
  ];
}

if ($type === 'consult') {
  if ($lead['name'] === '' || ($email === '' && $lead['phone'] === '')) fail(422, 'Please add your name and an email or phone number.');
  $lead['project_type'] = one_line($b['project_type'] ?? '', 40);
  $lead['message']      = clean($b['message'] ?? '', 3000);
} elseif ($type === 'franchise') {
  if ($lead['name'] === '' || $email === '') fail(422, 'Please add your name and email.');
  $lead['region']     = one_line($b['region'] ?? '', 120);
  $lead['background'] = one_line($b['background'] ?? '', 60);
  $lead['message']    = clean($b['message'] ?? '', 3000);
} else {
  if (!$quiz || $quiz['recommended'] === '') fail(422, 'Missing quiz result.');
}
if ($quiz) $lead['quiz'] = $quiz;

log_lead($lead);
$era = $anonQuiz ? null : push_to_era($lead);

// ---- who hears about it --------------------------------------------------
$fr = $lead['franchise'] !== '' ? franchise_inbox($lead['franchise']) : null;
$to = [];
if ($type === 'franchise') $to = [$c['franchise_team_inbox'] ?? '', $c['main_inbox']];
elseif (!$anonQuiz || !empty($c['email_anonymous_quiz'])) $to = array_filter([$fr, $c['main_inbox']]);

$quizText = '';
if ($quiz) {
  $quizText = "\nShade finder result\n  Recommended: {$quiz['recommended']}\n  Runner-up:   {$quiz['runner_up']}\n";
  foreach ($quiz['answers'] as $a) $quizText .= "  - {$a['q']}: {$a['a']}\n";
}

$labels = ['consult' => 'Consultation request', 'franchise' => 'Franchise enquiry', 'quiz' => 'Shade finder result'];
if ($to) {
  $who = $lead['name'] ?: ($email ?: 'Anonymous visitor');
  $body = $labels[$type] . "\n\n" . format_lines([
    'Name' => $lead['name'], 'Email' => $email, 'Phone' => $lead['phone'],
    'ZIP / city' => $lead['zip'], 'Franchise' => $lead['franchise'] ?: ($type === 'franchise' ? '' : 'Not matched — route manually'),
    'Project' => $lead['project_type'] ?? '', 'Region' => $lead['region'] ?? '', 'Background' => $lead['background'] ?? '',
  ]);
  if (!empty($lead['message'])) $body .= "\nMessage\n" . $lead['message'] . "\n";
  $body .= $quizText . "\nLead ID: {$lead['id']}\n";
  if ($era === false) $body .= "Note: the push to ERA failed; it is saved for retry.\n";
  send_mail($to, "{$labels[$type]} — $who" . ($lead['franchise'] ? " ({$lead['franchise']})" : ''), $body, ['reply_to' => $email]);
}

// ---- confirmation to the visitor ----------------------------------------
if ($email !== '' && !empty($c['send_visitor_confirmation'])) {
  $first = $lead['name'] !== '' ? strtok($lead['name'], ' ') : 'there';
  if ($type === 'consult') {
    $msg = "Hi $first,\n\nThanks for requesting a consultation with At Shades. "
      . ($lead['franchise'] ? "Your request is with our {$lead['franchise']} team. " : '')
      . "We'll call or email within one business day to set a time, and bring samples to the visit.\n";
  } elseif ($type === 'franchise') {
    $msg = "Hi $first,\n\nThanks for your interest in owning an At Shades franchise. A member of the franchise development team will be in touch shortly.\n";
  } else {
    $msg = "Hi $first,\n\nHere's your shade finder result.\n$quizText\nWhen you're ready, book a free consultation and a specialist will bring samples to your window.\n";
  }
  send_mail($email, $type === 'quiz' ? 'Your At Shades recommendation' : 'We received your request — At Shades', $msg . "\nAt Shades\n");
}

respond(200, ['ok' => true, 'id' => $lead['id']]);
