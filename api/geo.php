<?php
// GET /api/geo.php?q=<address or ZIP> — where an address is, for the sun study.
//
// Returns real coordinates, the IANA time zone, and (with a Google key) which
// way the front of the house faces, found from the nearest Street View camera:
// the camera sits on the street, so the house faces back toward it.
define('ATS_API', 1);
require __DIR__ . '/lib.php';
cors();

$q = one_line($_GET['q'] ?? '', 200);
if (mb_strlen($q) < 3) fail(422, 'Enter an address, city or ZIP.');

$cacheFile = data_dir() . '/geocache/' . hash('sha256', mb_strtolower($q)) . '.json';
if (is_file($cacheFile) && filemtime($cacheFile) > time() - 86400 * 30) {
  respond(200, json_decode(file_get_contents($cacheFile), true));
}
rate_limit('geo', 40);

$key = trim(cfg()['google_maps_key'] ?? '');
if (strpos($key, 'PASTE_') === 0) $key = '';
$out = null;

if ($key !== '') {
  $g = http_get_json('https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query(['address' => $q, 'key' => $key]));
  if (($g['status'] ?? '') === 'OK') {
    $r = $g['results'][0];
    $lat = $r['geometry']['location']['lat']; $lng = $r['geometry']['location']['lng'];
    $precise = in_array($r['geometry']['location_type'] ?? '', ['ROOFTOP', 'RANGE_INTERPOLATED'], true);
    $out = ['ok' => true, 'lat' => $lat, 'lng' => $lng, 'place' => $r['formatted_address'], 'precise' => $precise,
            'tz' => null, 'facing' => null, 'street' => false, 'source' => 'google'];

    $tz = http_get_json('https://maps.googleapis.com/maps/api/timezone/json?' . http_build_query(['location' => "$lat,$lng", 'timestamp' => time(), 'key' => $key]));
    if (($tz['status'] ?? '') === 'OK') $out['tz'] = $tz['timeZoneId'];

    $sv = http_get_json('https://maps.googleapis.com/maps/api/streetview/metadata?' . http_build_query(['location' => "$lat,$lng", 'radius' => 80, 'source' => 'outdoor', 'key' => $key]));
    if (($sv['status'] ?? '') === 'OK') {
      $out['street'] = true;
      $plat = $sv['location']['lat']; $plng = $sv['location']['lng'];
      $out['camera'] = ['lat' => $plat, 'lng' => $plng, 'heading' => round(bearing_deg($plat, $plng, $lat, $lng))];
      // Only trust a facing when we know where the house itself is.
      if ($precise) $out['facing'] = round(bearing_deg($lat, $lng, $plat, $plng));
    }
  } elseif (($g['status'] ?? '') === 'ZERO_RESULTS') {
    fail(404, "We couldn't find that address. Try adding the city or ZIP.");
  } else {
    error_log('[atshades] Google geocode: ' . ($g['status'] ?? 'no response') . ' ' . ($g['error_message'] ?? ''));
  }
}

if (!$out) {
  // No key (or Google failed): OpenStreetMap's Nominatim. Free, coarser.
  $n = http_get_json('https://nominatim.openstreetmap.org/search?' . http_build_query(['q' => $q, 'format' => 'jsonv2', 'limit' => 1, 'addressdetails' => 1, 'countrycodes' => 'us,ca']),
    ['User-Agent: AtShadesSunStudy/1.0 (+https://atshades.com)']);
  if (!$n) fail(404, "We couldn't find that address. Try adding the city or ZIP.");
  $out = ['ok' => true, 'lat' => (float)$n[0]['lat'], 'lng' => (float)$n[0]['lon'], 'place' => $n[0]['display_name'],
          'precise' => ($n[0]['category'] ?? '') === 'building' || ($n[0]['addresstype'] ?? '') === 'house',
          'tz' => state_timezone($n[0]['address']['state'] ?? '', (float)$n[0]['lon']), 'facing' => null, 'street' => false, 'source' => 'osm'];
}

@mkdir(dirname($cacheFile), 0750, true);
file_put_contents($cacheFile, json_encode($out));
respond(200, $out);

// Time zone from state/province, for the no-Google path. States split across
// zones use the zone most of their population lives in, with a longitude
// check for the big splits (TX, FL, TN, KY, IN, ND, SD, NE, KS, OR, ID, MI).
function state_timezone($state, $lng) {
  $z = [
    'Alabama'=>'America/Chicago','Alaska'=>'America/Anchorage','Arizona'=>'America/Phoenix','Arkansas'=>'America/Chicago',
    'California'=>'America/Los_Angeles','Colorado'=>'America/Denver','Connecticut'=>'America/New_York','Delaware'=>'America/New_York',
    'District of Columbia'=>'America/New_York','Florida'=>'America/New_York','Georgia'=>'America/New_York','Hawaii'=>'Pacific/Honolulu',
    'Idaho'=>'America/Boise','Illinois'=>'America/Chicago','Indiana'=>'America/Indiana/Indianapolis','Iowa'=>'America/Chicago',
    'Kansas'=>'America/Chicago','Kentucky'=>'America/New_York','Louisiana'=>'America/Chicago','Maine'=>'America/New_York',
    'Maryland'=>'America/New_York','Massachusetts'=>'America/New_York','Michigan'=>'America/Detroit','Minnesota'=>'America/Chicago',
    'Mississippi'=>'America/Chicago','Missouri'=>'America/Chicago','Montana'=>'America/Denver','Nebraska'=>'America/Chicago',
    'Nevada'=>'America/Los_Angeles','New Hampshire'=>'America/New_York','New Jersey'=>'America/New_York','New Mexico'=>'America/Denver',
    'New York'=>'America/New_York','North Carolina'=>'America/New_York','North Dakota'=>'America/Chicago','Ohio'=>'America/New_York',
    'Oklahoma'=>'America/Chicago','Oregon'=>'America/Los_Angeles','Pennsylvania'=>'America/New_York','Rhode Island'=>'America/New_York',
    'South Carolina'=>'America/New_York','South Dakota'=>'America/Chicago','Tennessee'=>'America/Chicago','Texas'=>'America/Chicago',
    'Utah'=>'America/Denver','Vermont'=>'America/New_York','Virginia'=>'America/New_York','Washington'=>'America/Los_Angeles',
    'West Virginia'=>'America/New_York','Wisconsin'=>'America/Chicago','Wyoming'=>'America/Denver','Puerto Rico'=>'America/Puerto_Rico',
    'British Columbia'=>'America/Vancouver','Alberta'=>'America/Edmonton','Saskatchewan'=>'America/Regina','Manitoba'=>'America/Winnipeg',
    'Ontario'=>'America/Toronto','Quebec'=>'America/Toronto','Québec'=>'America/Toronto','New Brunswick'=>'America/Halifax',
    'Nova Scotia'=>'America/Halifax','Prince Edward Island'=>'America/Halifax','Newfoundland and Labrador'=>'America/St_Johns',
    'Yukon'=>'America/Whitehorse','Northwest Territories'=>'America/Yellowknife','Nunavut'=>'America/Iqaluit',
  ];
  if ($state === 'Texas' && $lng < -104.9) return 'America/Denver';          // El Paso
  if ($state === 'Florida' && $lng < -85.0) return 'America/Chicago';        // Panhandle
  if ($state === 'Tennessee' && $lng > -85.3) return 'America/New_York';     // East TN
  if ($state === 'Kentucky' && $lng < -86.0) return 'America/Chicago';       // West KY
  if ($state === 'Indiana' && $lng < -86.9) return 'America/Chicago';        // NW/SW corners
  if ($state === 'Michigan' && $lng < -87.6) return 'America/Menominee';
  if ($state === 'North Dakota' && $lng < -101.5) return 'America/Denver';
  if ($state === 'South Dakota' && $lng < -100.5) return 'America/Denver';
  if ($state === 'Nebraska' && $lng < -101.5) return 'America/Denver';
  if ($state === 'Kansas' && $lng < -101.5) return 'America/Denver';
  if ($state === 'Oregon' && $lng > -117.6) return 'America/Boise';
  if ($state === 'Idaho' && $lng < -115.9) return 'America/Los_Angeles';     // North Idaho
  return $z[$state] ?? null;
}
