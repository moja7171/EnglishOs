#!/usr/bin/env python3
"""
Small HTTP relay for English OS's AI calls (Gemini/Groq/Pexels) when the
production server can't reach them directly (filtered network) but this
machine can. Unlike a real forward proxy (which needs the CONNECT method
and a raw-TCP tunnel — infra that turned out to need paid/verified
services), this speaks plain HTTP request/response, so it works over a
simple HTTP tunnel like localhost.run's free anonymous mode.

Protocol: any request to this server with header X-Relay-Url set to the
real destination and X-Relay-Auth matching RELAY_SECRET gets forwarded
verbatim (method, headers, body) to that destination, and the real
response (status, headers, body) is returned as-is. Never touches or
needs to know about the app's actual API keys — those travel inside the
forwarded headers untouched. See App\\Services\\Concerns\\UsesOutboundProxy,
which is the Laravel side of this same protocol.

Destination is checked against an allowlist so a leaked secret can only
ever be used to reach these specific providers, not as an open relay to
anywhere.

Run it: requires the `requests` package (pip install requests).

    RELAY_SECRET=<a-strong-random-value> python3 scripts/ai-relay.py 8899

Then expose port 8899 with a plain HTTP tunnel reachable from the
production server (its own HTTP mode, not raw TCP — a free anonymous
localhost.run tunnel works: `ssh -R 80:127.0.0.1:8899 nokey@localhost.run`),
and set on the server:

    AI_PROXY_URL=<the tunnel's https URL>
    AI_PROXY_SECRET=<the same RELAY_SECRET>
"""
import os
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse

import requests

RELAY_SECRET = os.environ.get("RELAY_SECRET")
if not RELAY_SECRET:
    sys.exit("RELAY_SECRET env var must be set")

ALLOWED_HOSTS = {
    "generativelanguage.googleapis.com",
    "api.groq.com",
    "api.pexels.com",
    "images.pexels.com",
    "videos.pexels.com",
}

HOP_BY_HOP = {
    "connection", "keep-alive", "proxy-authenticate", "proxy-authorization",
    "te", "trailers", "transfer-encoding", "upgrade", "host",
}


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def _relay(self):
        auth = self.headers.get("X-Relay-Auth", "")
        target = self.headers.get("X-Relay-Url", "")

        if auth != RELAY_SECRET:
            self._respond(401, b"bad auth")
            return

        parsed = urlparse(target)
        if parsed.scheme != "https" or parsed.hostname not in ALLOWED_HOSTS:
            self._respond(403, b"target not allowed")
            return

        length = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(length) if length else None

        fwd_headers = {
            k: v for k, v in self.headers.items()
            if k.lower() not in HOP_BY_HOP and not k.lower().startswith("x-relay-")
        }

        try:
            resp = requests.request(
                self.command, target, headers=fwd_headers, data=body,
                timeout=30, allow_redirects=False,
            )
        except requests.RequestException as e:
            self._respond(502, f"relay fetch failed: {e}".encode())
            return

        # content-encoding excluded too: requests already transparently
        # decompresses gzip/deflate into resp.content, but leaves the
        # original header in place — forwarding both means the client
        # tries to gunzip bytes that are already plain, corrupting them.
        out_headers = {
            k: v for k, v in resp.headers.items()
            if k.lower() not in HOP_BY_HOP and k.lower() != "content-encoding"
        }
        self._respond(resp.status_code, resp.content, out_headers)

    def _respond(self, status, body, headers=None):
        self.send_response(status)
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        self._relay()

    def do_POST(self):
        self._relay()

    def log_message(self, fmt, *args):
        sys.stderr.write("%s - %s\n" % (self.address_string(), fmt % args))


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8899
    server = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    print(f"relay listening on 127.0.0.1:{port}")
    server.serve_forever()
