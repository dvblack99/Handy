<?php
// upload.php — handles video uploads and deletions for HandCam
// Place in /root/mysite/html/handy/

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$VIDEO_DIR = __DIR__ . '/videos/';
$INDEX_FILE = $VIDEO_DIR . 'index.json';

// Ensure videos dir exists
if (!is_dir($VIDEO_DIR)) {
    mkdir($VIDEO_DIR, 0755, true);
}

// Load existing index
function loadIndex($indexFile) {
    if (!file_exists($indexFile)) return [];
    $data = json_decode(file_get_contents($indexFile), true);
    return is_array($data) ? $data : [];
}

// Save index
function saveIndex($indexFile, $videos) {
    file_put_contents($indexFile, json_encode(array_values($videos), JSON_PRETTY_PRINT));
}

// ── DELETE ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $file = basename($_POST['file'] ?? '');
    if (!$file) { http_response_code(400); echo json_encode(['error' => 'No file specified']); exit; }

    $path = $VIDEO_DIR . $file;
    if (file_exists($path)) unlink($path);

    $videos = loadIndex($INDEX_FILE);
    $videos = array_filter($videos, fn($v) => $v['file'] !== $file);
    saveIndex($INDEX_FILE, $videos);

    echo json_encode(['ok' => true]);
    exit;
}

// ── UPLOAD ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['video'])) {
    $file     = $_FILES['video'];
    $name     = trim($_POST['name'] ?? '') ?: pathinfo($file['name'], PATHINFO_FILENAME);
    $origName = $file['name'];
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    // Allowed video types
    $allowed = ['mp4', 'mov', 'webm', 'avi', 'm4v', 'mkv'];
    if (!in_array($ext, $allowed)) {
        http_response_code(400);
        echo json_encode(['error' => 'File type not allowed: ' . $ext]);
        exit;
    }

    // Max 500MB
    if ($file['size'] > 500 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'File too large (max 500MB)']);
        exit;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(500);
        echo json_encode(['error' => 'Upload error code: ' . $file['error']]);
        exit;
    }

    // Sanitise filename
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
    $filename = $safeName . '.' . $ext;

    // Avoid collisions
    $counter = 1;
    while (file_exists($VIDEO_DIR . $filename)) {
        $filename = $safeName . '_' . $counter . '.' . $ext;
        $counter++;
    }

    if (!move_uploaded_file($file['tmp_name'], $VIDEO_DIR . $filename)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save file']);
        exit;
    }

    // Update index
    $videos = loadIndex($INDEX_FILE);
    $videos[] = ['name' => $name, 'file' => $filename];
    saveIndex($INDEX_FILE, $videos);

    echo json_encode(['ok' => true, 'file' => $filename, 'name' => $name]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
