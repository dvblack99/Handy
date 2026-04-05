<?php
// save_settings.php — writes settings.json for HandCam webapp
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error'=>'POST only']); exit;
}

$SETTINGS_FILE = __DIR__ . '/settings.json';

$body = file_get_contents('php://input');
if (!$body) { http_response_code(400); echo json_encode(['error'=>'No body']); exit; }

$data = json_decode($body, true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

// Validate and sanitise each section
$allowed = [
  'global' => ['dwellMs','sensitivity','cursorRadius','smoothing','previewOn','micOn','camMount','scrollZone','scrollSpeed'],
  'videos' => ['volume','autoplay','loop','dwellMs'],
  'music'  => ['volume','dwellMs'],
  'games'  => ['ballzMaxBalls','ballzGravity','ballzSpawnRate','dwellMs'],
  'other'  => ['dwellMs'],
];

// Load existing settings as base
$existing = [];
if (file_exists($SETTINGS_FILE)) {
    $existing = json_decode(file_get_contents($SETTINGS_FILE), true) ?: [];
}

foreach ($allowed as $section => $keys) {
    if (isset($data[$section]) && is_array($data[$section])) {
        if (!isset($existing[$section])) $existing[$section] = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data[$section])) {
                $val = $data[$section][$key];
                // Type coercion
                if (is_bool($val) || $val === 'true' || $val === 'false') {
                    $existing[$section][$key] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
                } elseif (is_numeric($val)) {
                    $existing[$section][$key] = $val + 0; // cast to number
                } elseif (is_null($val)) {
                    $existing[$section][$key] = null;
                } else {
                    $existing[$section][$key] = $val;
                }
            }
        }
    }
}

// Write atomically
$tmp = $SETTINGS_FILE . '.tmp';
$result = file_put_contents($tmp, json_encode($existing, JSON_PRETTY_PRINT));
if ($result === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not write settings file. Check permissions.']);
    exit;
}
rename($tmp, $SETTINGS_FILE);
chmod($SETTINGS_FILE, 0644);

echo json_encode(['ok' => true, 'saved' => array_keys($data)]);
