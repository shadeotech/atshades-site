<?php
// Copy this file to config.php (same folder) on the server and fill it in.
// config.php is git-ignored so passwords never end up in the repo.

return [

  // Sites allowed to post to the API. Add both the bare and www domain.
  'allowed_origins' => ['https://atshades.com', 'https://www.atshades.com'],

  // Where leads, bookings and rate-limit files are kept. Best placed OUTSIDE
  // public_html, e.g. '/home/u123456789/atshades-data'. The default works too
  // (the folder ships with an .htaccess that blocks web access).
  'data_dir' => __DIR__ . '/../data',

  // ---- Email -------------------------------------------------------------
  // Hostinger: hPanel → Emails → create a mailbox such as leads@atshades.com,
  // then use smtp.hostinger.com, port 465, ssl, with that mailbox's password.
  //
  // No Hostinger mailbox yet? Send through the Gmail account instead:
  // turn on 2-Step Verification for atshadesus@gmail.com, create an App
  // Password (myaccount.google.com/apppasswords), then use
  //   host smtp.gmail.com, port 465, ssl, username atshadesus@gmail.com,
  //   password = the 16-character app password, from_email = atshadesus@gmail.com
  'smtp' => [
    'host'     => 'smtp.hostinger.com',
    'port'     => 465,
    'secure'   => 'ssl',            // 'ssl' (465) or 'tls' (587)
    'username' => 'leads@atshades.com',
    'password' => 'CHANGE_ME',
  ],
  'from_email' => 'leads@atshades.com',
  'from_name'  => 'At Shades',

  // Every lead is copied here, whatever franchise it is routed to.
  'main_inbox' => 'atshadesus@gmail.com',

  // Franchise development team: franchise enquiries and meeting bookings.
  'franchise_team_inbox' => 'atshadesus@gmail.com',

  // Consult and quiz leads go to the franchise nearest the visitor's ZIP.
  // Keys must match the city names in index.html (LOCATIONS) exactly.
  // Leave one blank and that franchise's leads go to main_inbox only.
  // For now all are blank, so every lead goes to atshadesus@gmail.com.
  'franchise_inboxes' => [
    'Dallas — Design District'     => '',
    'Houston — Upper Kirby'        => '',
    'Phoenix — Scottsdale'         => '',
    'Los Angeles — Culver City'    => '',
    'Denver — RiNo'                => '',
    'Chicago — West Loop'          => '',
    'Atlanta — Westside'           => '',
    'Miami — Design District'      => '',
    'Charlotte — South End'        => '',
    'New York — Long Island City'  => '',
    'Boston — Seaport'             => '',
    'Seattle — SoDo'               => '',
  ],

  // Send the visitor a confirmation email (consult, franchise enquiry, quiz).
  'send_visitor_confirmation' => true,

  // Anonymous quiz completions (no email given) are logged. Set true to also
  // email main_inbox about each one.
  'email_anonymous_quiz' => false,

  // ---- Franchise meeting booking ----------------------------------------
  'booking' => [
    'timezone'   => 'America/Chicago',          // the team's business hours
    'slots'      => ['09:00', '10:30', '12:00', '13:30', '15:00', '16:30'],
    'minutes'    => 30,
    'days_ahead' => 10,                         // weekdays offered
    'min_notice_hours' => 12,
    'blocked_dates' => [],                      // e.g. ['2026-12-25']
    // Optional fixed meeting links. Leave blank and the invite says the team
    // will send the link.
    'links' => [
      'Zoom'            => '',
      'Google Meet'     => '',
      'Microsoft Teams' => '',
    ],
  ],

  // ---- ERA (app.atshades.com) -------------------------------------------
  // Every lead is POSTed to ERA's website intake. Franchise enquiries and
  // bookings land in the franchise pipeline; consult and quiz leads land in
  // the CRM of the franchisee whose territory covers the visitor's ZIP.
  // The secret must match WEBSITE_INTAKE_SECRET in ERA's Vercel settings
  // (generate one with: openssl rand -hex 24). Until it is replaced, leads
  // go to email only.
  'era' => [
    'enabled' => true,
    'url'     => 'https://app.atshades.com/api/intake/website',
    'headers' => [
      'Authorization: Bearer CHANGE_ME_WEBSITE_INTAKE_SECRET',
    ],
    'field_map' => [],
    'timeout' => 8,
  ],

  // ---- Sun study ---------------------------------------------------------
  // Google Cloud key with Geocoding API, Time Zone API and Street View Static
  // API enabled. It stays on the server; visitors never see it. Restrict it in
  // Google Cloud to those three APIs. Without a key the sun study still works
  // (OpenStreetMap geocoding, the visitor's time zone, manual facing) but has
  // no street photo and cannot detect which way the house faces.
  'google_maps_key' => '',

  // ---- Abuse protection --------------------------------------------------
  'rate_limit_per_hour' => 12,   // submissions per IP per hour
];
