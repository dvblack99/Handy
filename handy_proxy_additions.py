"""
HandCam proxy endpoints — add these to your existing proxy.py
Handles video delete and index.json rewrite so PHP is not required.

These endpoints are called from admin.html at /api/handy/delete and /api/handy/write-index.
They require the X-Token header with value 'Lindsey123!'

Add the VIDEO_DIR path to match your actual server layout.
"""

import json, os, shutil

# ── Adjust this path to match where your videos folder actually is ──────────
VIDEO_DIR  = '/root/mysite/html/handy/videos'
INDEX_FILE = os.path.join(VIDEO_DIR, 'index.json')

def load_index():
    if not os.path.exists(INDEX_FILE):
        return []
    with open(INDEX_FILE, 'r') as f:
        try:    return json.load(f)
        except: return []

def save_index(videos):
    tmp = INDEX_FILE + '.tmp'
    with open(tmp, 'w') as f:
        json.dump(videos, f, indent=2)
    os.replace(tmp, INDEX_FILE)
    os.chmod(INDEX_FILE, 0o644)


# ── In your existing do_POST handler, add these path checks ─────────────────
# (paste inside your existing if/elif chain for self.path)

"""
elif self.path == '/api/handy/delete':
    if not self.check_token():
        return
    body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
    filename = os.path.basename(body.get('file', ''))
    if not filename:
        self._json({'error': 'No file'}, 400)
        return

    # Delete the video file
    video_path = os.path.join(VIDEO_DIR, filename)
    if os.path.exists(video_path):
        os.remove(video_path)

    # Delete thumbnail if present
    thumb_path = os.path.join(VIDEO_DIR, os.path.splitext(filename)[0] + '.jpg')
    if os.path.exists(thumb_path):
        os.remove(thumb_path)

    # Remove from index.json
    videos = load_index()
    videos = [v for v in videos if v.get('file') != filename]
    save_index(videos)

    self._json({'ok': True})

elif self.path == '/api/handy/write-index':
    if not self.check_token():
        return
    body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
    videos = body.get('videos', [])
    if not isinstance(videos, list):
        self._json({'error': 'Invalid videos list'}, 400)
        return
    save_index(videos)
    self._json({'ok': True})
"""


# ── Also add this nginx location block to route /api/handy/ to the proxy ────
"""
# In your nginx config, inside the server {} block, add:

location /api/handy/ {
    proxy_pass http://api-proxy:8181;
    proxy_set_header X-Token $http_x_token;
    proxy_set_header Content-Type $http_content_type;
}

# If you already have a location /api/ that catches all API routes,
# you don't need this — the existing rule will already route it.
"""


# ── ALSO: to fix upload.php (make PHP work for uploads) ─────────────────────
"""
# In your nginx config, inside the location that serves /handy/:

location ~ \.php$ {
    fastcgi_pass php:9000;          # or wherever your PHP-FPM container is
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}

# If PHP-FPM runs in the same container as nginx, use:
#   fastcgi_pass unix:/run/php/php8.1-fpm.sock;
# Check your docker-compose.yml for the PHP service name.
"""
