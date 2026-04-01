<?php
// upload.php — HandCam video upload/delete handler

// Must be before any output
$VIDEO_DIR  = __DIR__ . '/videos/';
$INDEX_FILE = $VIDEO_DIR . 'index.json';

// Ensure videos dir exists and is writable
if (!is_dir($VIDEO_DIR)) {
    mkdir($VIDEO_DIR, 0777, true);
    chmod($VIDEO_DIR, 0777);
}

// Always output JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

function loadIndex($f) {
    if (!file_exists($f)) return [];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function saveIndex($f, $videos) {
    $result = file_put_contents($f, json_encode(array_values($videos), JSON_PRETTY_PRINT));
    if ($result === false) {
        error_log('HandCam: failed to write index.json — check permissions on ' . $f);
    }
    chmod($f, 0666);
    return $result !== false;
}

function jsonErr($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

// ── DELETE ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $file = basename($_POST['file'] ?? '');
    if (!$file) jsonErr('No file specified');
    $path = $VIDEO_DIR . $file;
    if (file_exists($path) && !unlink($path)) jsonErr('Could not delete file', 500);
    $videos = loadIndex($INDEX_FILE);
    $videos = array_values(array_filter($videos, fn($v) => $v['file'] !== $file));
    saveIndex($INDEX_FILE, $videos);
    echo json_encode(['ok' => true]);
    exit;
}

// ── UPLOAD ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['video'])) {
    $f = $_FILES['video'];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        $codes = [1=>'File too large (server limit)',2=>'File too large',3=>'Partial upload',4=>'No file',6=>'No tmp dir',7=>'Cannot write'];
        jsonErr($codes[$f['error']] ?? 'Upload error '.$f['error'], 500);
    }

    $origName = $f['name'];
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed  = ['mp4','mov','webm','avi','m4v','mkv'];
    if (!in_array($ext, $allowed)) jsonErr('File type not allowed: ' . $ext);

    if ($f['size'] > 500 * 1024 * 1024) jsonErr('File too large (max 500MB)');

    $name     = trim($_POST['name'] ?? '') ?: pathinfo($origName, PATHINFO_FILENAME);
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
    $filename = $safeName . '.' . $ext;
    $counter  = 1;
    while (file_exists($VIDEO_DIR . $filename)) {
        $filename = $safeName . '_' . $counter++ . '.' . $ext;
    }

    if (!move_uploaded_file($f['tmp_name'], $VIDEO_DIR . $filename)) {
        jsonErr('Failed to save file — check disk space and permissions', 500);
    }
    chmod($VIDEO_DIR . $filename, 0644);

    $videos   = loadIndex($INDEX_FILE);
    $videos[] = ['name' => $name, 'file' => $filename];
    $saved    = saveIndex($INDEX_FILE, $videos);

    if (!$saved) {
        jsonErr('Video saved but index.json could not be written — run: chmod 777 /root/mysite/html/handy/videos', 500);
    }

    echo json_encode(['ok' => true, 'file' => $filename, 'name' => $name]);
    exit;
}

// ── STATUS CHECK ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'ok'        => true,
        'videos_dir'=> is_dir($VIDEO_DIR),
        'writable'  => is_writable($VIDEO_DIR),
        'videos'    => count(loadIndex($INDEX_FILE)),
    ]);
    exit;
}

jsonErr('Invalid request');