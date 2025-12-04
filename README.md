# migration-manager + scraper

## Overview

This repository contains two components:

- `wp-migration-manager/`: a WordPress installation containing the migration manager plugin and site files.
- `scraper/`: a Python FastAPI service that uses Playwright (Chromium) to scrape pages and extract structured content groups.

Use this guide to:

- Test the scraper locally
- Exercise the API endpoints
- Deploy the scraper safely to a server (Docker-based)
- Understand environment and security requirements

---

## Quick Start: Local Testing (Scraper)

### Prerequisites

- Python 3.11+
- Node-free environment is fine (Playwright is installed via Python)
- macOS/Linux recommended

### Option A: Run directly on your machine

1. Create and activate a virtualenv (recommended):

```bash
python3 -m venv .venv
source .venv/bin/activate  # On Windows: .venv\Scripts\activate
```

2. Install dependencies:

```bash
pip install -r scraper/requirements.txt
python -m playwright install --with-deps
```

3. Start the API:

```bash
# From project root
uvicorn scraper.app:app --host 0.0.0.0 --port 8000 --reload

# Or from scraper directory
cd scraper
uvicorn app:app --host 0.0.0.0 --port 8000 --reload
```

4. Test endpoints:

- Health check: `curl http://localhost:8000/`
- Scrape a page: `curl "http://localhost:8000/scrape?url=https://example.com" | jq .`
- Extract URLs (same domain): `curl "http://localhost:8000/extract-urls?url=https://example.com" | jq .`

### Option B: Run with Docker

From the `scraper/` directory:

```bash
docker build -t scraper:latest scraper

docker run --rm -it -p 8000:8000 --cap-drop=ALL --security-opt=no-new-privileges scraper:latest
```

Then call the same test URLs against `http://localhost:8000`.

---

## Testing the Viewer (Static HTML)

The file `scraper/index.html` is a simple viewer to visualize the scraper’s JSON output. Open it in a browser and paste the JSON returned from `/scrape`.

You can also serve it locally:

```
python -m http.server 8080 --directory scraper
```

Then visit `http://localhost:8080/index.html`.

---

## Secure Server Deployment (Scraper)

### Recommended stack

- Docker or Podman
- Reverse proxy: Nginx or Traefik
- TLS certificates via Let’s Encrypt (e.g., certbot or Traefik ACME)

### Build & run

```
# Build image
docker build -t ghcr.io/your-org/scraper:latest scraper

# Run container (read-only FS, no privilege escalation)
docker run -d \
  --name scraper \
  -p 127.0.0.1:8000:8000 \
  --read-only \
  --tmpfs /tmp:rw,noexec,nosuid,size=128m \
  --cap-drop=ALL \
  --security-opt no-new-privileges \
  --restart unless-stopped \
  ghcr.io/your-org/scraper:latest
```

Expose the service only on `127.0.0.1` and place it behind a reverse proxy that handles HTTPS, rate limits, and IP allowlists if needed.

### Example Nginx (reverse proxy)

```
server {
  listen 80;
  server_name scraper.example.com;
  location /.well-known/acme-challenge/ { root /var/www/certbot; }
  location / { return 301 https://$host$request_uri; }
}

server {
  listen 443 ssl http2;
  server_name scraper.example.com;

  ssl_certificate     /etc/letsencrypt/live/scraper.example.com/fullchain.pem;
  ssl_certificate_key /etc/letsencrypt/live/scraper.example.com/privkey.pem;
  add_header Referrer-Policy no-referrer always;
  add_header X-Content-Type-Options nosniff always;
  add_header X-Frame-Options DENY always;
  add_header X-XSS-Protection "1; mode=block" always;

  location / {
    proxy_pass http://127.0.0.1:8000;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 120s;
    limit_req zone=scraper burst=10 nodelay;
  }
}
```

### Hardening checklist

- Run as non-root inside container (future improvement: add USER in Dockerfile)
- Use `--cap-drop=ALL` and `--security-opt no-new-privileges`
- Set container filesystem as `--read-only` with `--tmpfs /tmp`
- Bind to loopback and front with HTTPS proxy
- Add WAF/rate-limiting (e.g., Nginx `limit_req`)
- Monitor logs for errors/timeouts

### Operational limits

- Respect target sites’ robots.txt and terms
- Add per-host rate limiting at the proxy level
- Consider allowlists or API keys on your proxy to restrict usage

---

## Environment Requirements

- CPU: x86_64 or ARM64 with Chromium support
- RAM: 512MB minimum (1–2GB recommended for heavy pages)
- Disk: ~300MB for image + Playwright browsers
- OS: Linux (Debian/Ubuntu/Alpine-based), macOS for local dev
- Network: outbound HTTPS

### Python runtime

- Python 3.11+
- FastAPI, Uvicorn
- Playwright 1.40.0 with Chromium

### Project structure

The `scraper/` directory is organized as a Python package:

```
scraper/
├── __init__.py          # Package marker
├── app.py               # FastAPI application (main entry point)
├── config.py            # Configuration settings
├── requirements.txt     # Production dependencies
├── requirements-dev.txt # Development dependencies (pytest, black, etc.)
├── Dockerfile           # Container configuration
├── start.sh             # Startup script
└── index.html           # JSON viewer
```

### Docker image

- Based on `python:3.11-slim`
- Installs system libs required by Chromium
- Installs Playwright with `playwright install --with-deps`

---

## WordPress (migration-manager)

This folder contains a standard WordPress install including admin files and a plugin. To test locally:

- Use PHP 8.1+, MySQL/MariaDB, and a web server (Apache/Nginx)
- Configure `wp-config.php` database credentials
- Ensure file permissions allow WordPress to run (no world-writable files)

For production:

- Place behind HTTPS
- Keep `wp-admin` access locked down (IP restriction or 2FA)
- Regularly update WordPress core, themes, and plugins
- Configure backups and monitoring

---

## Troubleshooting

- Browser not initialized: ensure Playwright installed and container has required libs
- Timeouts: increase `proxy_read_timeout` and inspect target site blocking
- High CPU: lower concurrency (one request at a time) or scale horizontally
- Import errors: ensure you're running from the project root when using `scraper.app:app`, or use `app:app` when inside the `scraper/` directory

---

## License

See component-specific licenses where applicable.
