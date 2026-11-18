"""
Local preview server for the Top Mountain Resort site.

  python serve.py          # serves this folder on http://localhost:5175 and opens the browser
  python serve.py 8080     # another port

Works from any folder (it always serves the folder this file is in), picks the
next free port if the one asked for is busy, and sends the right content types
for .webp, .mp4 and .svg, which Python's default server on Windows does not.
"""
import functools
import http.server
import socketserver
import sys
import threading
import webbrowser
from pathlib import Path

ROOT = Path(__file__).resolve().parent
_ports = [a for a in sys.argv[1:] if a.isdigit()]
START_PORT = int(_ports[0]) if _ports else 5175

TYPES = {
    ".html": "text/html; charset=utf-8",
    ".css": "text/css; charset=utf-8",
    ".js": "text/javascript; charset=utf-8",
    ".json": "application/json",
    ".svg": "image/svg+xml",
    ".webp": "image/webp",
    ".jpg": "image/jpeg",
    ".jpeg": "image/jpeg",
    ".png": "image/png",
    ".mp4": "video/mp4",
}


class Handler(http.server.SimpleHTTPRequestHandler):
    extensions_map = {**http.server.SimpleHTTPRequestHandler.extensions_map, **TYPES}

    def end_headers(self):
        # always serve fresh files while editing
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def log_message(self, fmt, *args):
        if args and str(args[1]).startswith(("4", "5")):  # only report problems
            super().log_message(fmt, *args)


class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = False


def main():
    handler = functools.partial(Handler, directory=str(ROOT))
    for port in range(START_PORT, START_PORT + 20):
        try:
            httpd = Server(("127.0.0.1", port), handler)
            break
        except OSError:
            print(f"Port {port} is busy, trying {port + 1}...")
    else:
        sys.exit("No free port found. Close other servers and try again.")

    url = f"http://localhost:{port}/"
    print(f"\n  Top Mountain Resort is running at {url}")
    print(f"  Serving: {ROOT}")
    print("  Press Ctrl+C to stop.\n")
    if "--no-browser" not in sys.argv:
        threading.Timer(0.8, lambda: webbrowser.open(url)).start()
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\nStopped.")


if __name__ == "__main__":
    main()
