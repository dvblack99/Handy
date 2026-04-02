<?php
$VIDEO_DIR  = __DIR__ . '/videos/';
$TEMP_DIR   = __DIR__ . '/videos/.tmp/';
$INDEX_FILE = $VIDEO_DIR . 'index.json';

foreach ([$VIDEO_DIR, $TEMP_DIR] as $dir) {
    if (!is_dir($dir)) { mkdir($dir, 0777, true); chmod($dir, 0777); }
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

function loadIndex($f) {
    if (!file_exists($f)) return [];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function saveIndex($f, $v) {
    $r = file_put_contents($f, json_encode(array_values($v), JSON_PRETTY_PRINT));
    if (file_exists($f)) chmod($f, 0666);
    return $r !== false;
}
function jsonErr($m, $c=400) { http_response_code($c); echo json_encode(['error'=>$m]); exit; }

// ── STATUS (GET) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'ok'       => true,
        'videos'   => count(loadIndex($INDEX_FILE)),
        'writable' => is_writable($VIDEO_DIR),
    ]);
    exit;
}

// ── DELETE ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete') {
    $file = basename($_POST['file']??'');
    if (!$file) jsonErr('No file');
    $path = $VIDEO_DIR.$file;
    if (file_exists($path)) unlink($path);
    $videos = loadIndex($INDEX_FILE);
    $videos = array_values(array_filter($videos, fn($v)=>$v['file']!==$file));
    saveIndex($INDEX_FILE, $videos);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── CHUNKED UPLOAD ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['chunk'])) {
    $chunkIndex  = (int)($_POST['chunk_index']  ?? 0);
    $totalChunks = (int)($_POST['total_chunks'] ?? 1);
    $uploadId    = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['upload_id'] ?? '');
    $origName    = basename($_POST['filename']   ?? 'upload.mp4');
    $name        = trim($_POST['name']           ?? '') ?: pathinfo($origName, PATHINFO_FILENAME);

    if (!$uploadId) jsonErr('Missing upload_id');

    $chunk = $_FILES['chunk'];
    if ($chunk['error'] !== UPLOAD_ERR_OK) {
        $codes=[1=>'Too large',2=>'Too large',3=>'Partial',4=>'No file',6=>'No tmp',7=>'Write fail'];
        jsonErr($codes[$chunk['error']] ?? 'Upload error '.$chunk['error'], 500);
    }

    // Save chunk to temp file
    $tmpFile = $TEMP_DIR.$uploadId.'.tmp';
    $mode    = ($chunkIndex === 0) ? 'wb' : 'ab';
    $fp      = fopen($tmpFile, $mode);
    if (!$fp) jsonErr('Cannot open temp file', 500);
    fwrite($fp, file_get_contents($chunk['tmp_name']));
    fclose($fp);

    // Not the last chunk — acknowledge and wait
    if ($chunkIndex < $totalChunks - 1) {
        echo json_encode(['ok'=>true, 'chunk_received'=>true, 'chunk'=>$chunkIndex]);
        exit;
    }

    // Last chunk — assemble final file
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed  = ['mp4','mov','webm','avi','m4v','mkv'];
    if (!in_array($ext, $allowed)) {
        unlink($tmpFile);
        jsonErr('File type not allowed: '.$ext);
    }

    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
    $filename = $safeName.'.'.$ext;
    $counter  = 1;
    while (file_exists($VIDEO_DIR.$filename)) {
        $filename = $safeName.'_'.$counter++.'.'.$ext;
    }

    if (!rename($tmpFile, $VIDEO_DIR.$filename)) {
        if (!copy($tmpFile, $VIDEO_DIR.$filename)) {
            unlink($tmpFile);
            jsonErr('Failed to save file', 500);
        }
        unlink($tmpFile);
    }
    chmod($VIDEO_DIR.$filename, 0644);

    // ── FFMPEG: remux to MP4 with faststart + generate thumbnail ──
    $ffmpeg   = trim(shell_exec('which ffmpeg') ?: '');
    $finalFile = $filename;

    if ($ffmpeg) {
        $srcPath   = $VIDEO_DIR.$filename;
        $mp4Name   = preg_replace('/\.[^.]+$/', '', $filename).'.mp4';
        $mp4Path   = $VIDEO_DIR.$mp4Name;
        $thumbName = preg_replace('/\.[^.]+$/', '', $filename).'.jpg';
        $thumbPath = $VIDEO_DIR.$thumbName;

        // Remux to MP4 with moov atom at front (faststart) — fast, no re-encode
        $cmd = "$ffmpeg -y -i ".escapeshellarg($srcPath)
             . " -c copy -movflags +faststart "
             . escapeshellarg($mp4Path)
             . " 2>/dev/null";
        shell_exec($cmd);

        if (file_exists($mp4Path) && filesize($mp4Path) > 1000) {
            // Remove original if it was MOV/etc, keep mp4
            if ($srcPath !== $mp4Path) unlink($srcPath);
            chmod($mp4Path, 0644);
            $finalFile = $mp4Name;

            // Generate thumbnail at 2 seconds
            $thumbCmd = "$ffmpeg -y -i ".escapeshellarg($mp4Path)
                      . " -ss 00:00:02 -vframes 1 -vf scale=320:180:force_original_aspect_ratio=decrease"
                      . " ".escapeshellarg($thumbPath)
                      . " 2>/dev/null";
            shell_exec($thumbCmd);
            if (file_exists($thumbPath)) chmod($thumbPath, 0644);
        } else {
            // ffmpeg failed — keep original file as-is
            if (file_exists($mp4Path)) unlink($mp4Path);
        }
    }

    // Update index
    $videos   = loadIndex($INDEX_FILE);
    $thumbFile = preg_replace('/\.[^.]+$/', '', $finalFile).'.jpg';
    $videos[] = [
        'name'  => $name,
        'file'  => $finalFile,
        'thumb' => file_exists($VIDEO_DIR.$thumbFile) ? $thumbFile : null,
    ];
    if (!saveIndex($INDEX_FILE, $videos)) {
        jsonErr('File saved but index.json failed — chmod 777 '.$VIDEO_DIR, 500);
    }

    echo json_encode(['ok'=>true, 'file'=>$filename, 'name'=>$name]);
    exit;
}

// ── LEGACY single-file upload (fallback) ─────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_FILES['video'])) {
    $f    = $_FILES['video'];
    $name = trim($_POST['name']??'') ?: pathinfo($f['name'], PATHINFO_FILENAME);
    if ($f['error']!==UPLOAD_ERR_OK) jsonErr('Upload error '.$f['error'], 500);
    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext,['mp4','mov','webm','avi','m4v','mkv'])) jsonErr('Type not allowed');
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/','_',pathinfo($f['name'],PATHINFO_FILENAME));
    $fn   = $safe.'.'.$ext; $c=1;
    while(file_exists($VIDEO_DIR.$fn)) $fn=$safe.'_'.$c++.'.'.$ext;
    if (!move_uploaded_file($f['tmp_name'],$VIDEO_DIR.$fn)) jsonErr('Save failed',500);
    chmod($VIDEO_DIR.$fn,0644);
    $videos=loadIndex($INDEX_FILE); $videos[]=['name'=>$name,'file'=>$fn];
    saveIndex($INDEX_FILE,$videos);
    echo json_encode(['ok'=>true,'file'=>$fn,'name'=>$name]);
    exit;
}

jsonErr('Invalid request');