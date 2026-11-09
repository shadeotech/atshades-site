<?php
// GET /api/slots.php — open franchise-meeting times.
define('ATS_API', 1);
require __DIR__ . '/lib.php';
cors();
respond(200, ['ok' => true, 'days' => booking_slots(), 'minutes' => (int)(cfg()['booking']['minutes'] ?? 30)]);
