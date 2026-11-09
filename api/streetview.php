<?php
// GET /api/streetview.php?lat=..&lng=..&heading=.. — Street View photo of the
// house, fetched with the server's key so the key never reaches the browser.
define('ATS_API', 1);
require __DIR__ . '/lib.php';
cors();

$lat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($_GET['lng'] ?? null, FILTER_VALIDATE_FLOAT);
$heading = filter_var($_GET['heading'] ?? 0, FILTER_VALIDATE_FLOAT);
if ($lat === false || $lng === false || abs($lat) > 90 || abs($lng) > 180) fail(422, 'Bad location.');
$key = trim(cfg()['google_maps_key'] ?? '');
if ($key === '' || strpos($key, 'PASTE_') === 0) fail(404, 'Street imagery is not configured.');

$lat = round($lat, 5); $lng = round($lng, 5); $heading = (int)round(fmod($heading + 360, 360));
$file = data_dir() . "/streetcache/{$lat}_{$lng}_{$heading}.jpg";
if (!is_file($file)) {
  rate_limit('street', 60);
  $ch = curl_init('https://maps.googleapis.com/maps/api/streetview?' . http_build_query([
    'size' => '800x500', 'fov' => 75, 'pitch' => 8, 'source' => 'outdoor', 'radius' => 80,
    'location' => "$lat,$lng", 'heading' => $heading, 'return_error_code' => 'true', 'key' => $key,
  ]));
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
  $img = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($img === false || $code !== 200) fail(404, 'No street imagery here.');
  @mkdir(dirname($file), 0750, true);
  file_put_contents($file, $img);
}
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=604800');
readfile($file);
