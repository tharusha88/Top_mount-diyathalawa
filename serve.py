"""
Local preview server for the Top Mountain Resort site.

  python serve.py          # serves this folder on http://localhost:5175 and opens the browser
  python serve.py 8080     # another port

Works from any folder (it always serves the folder this file is in), picks the
next free port if the one asked for is busy, and sends the right content types
for .webp, .mp4 and .svg, which Python's default server on Windows does not.

"localhost" can mean 127.0.0.1 or ::1, and the browser may pick either. Other
dev servers (Vite, for one) often listen on just ::1, so a port counts as busy
if anything answers on either address, and this server then takes both, so
http://localhost:<port> can only ever reach this site.
"""
import errno
import functools
import http.server
import socket
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


class Server6(Server):
    address_family = socket.AF_INET6


# the bind errors that mean "someone else has this port" (Windows: WSAEADDRINUSE, WSAEACCES)
IN_USE = {errno.EADDRINUSE, errno.EACCES, 10048, 10013}


def answering(host, port):
    """True if any server accepts connections on host:port (including one listening on all addresses)."""
    try:
        with socket.create_connection((host, port), timeout=0.3):
            return True
    except OSError:
        return False


def claim(port, handler):
    """Listen on both 127.0.0.1 and ::1, or return None if the port is taken on either."""
    if answering("127.0.0.1", port) or answering("::1", port):
        return None
    try:
        servers = [Server(("127.0.0.1", port), handler)]
    except OSError:
        return None
    try:
        servers.append(Server6(("::1", port), handler))
    except OSError as e:
        if e.errno in IN_USE or getattr(e, "winerror", None) in IN_USE:
            servers[0].server_close()
            return None
        # this machine has no IPv6 loopback, so "localhost" can only mean 127.0.0.1
    return servers


def main():
    handler = functools.partial(Handler, directory=str(ROOT))
    for port in range(START_PORT, START_PORT + 20):
        servers = claim(port, handler)
        if servers:
            break
        print(f"Port {port} is busy, trying {port + 1}...")
    else:
        sys.exit("No free port found. Close other servers and try again.")

    # with both loopback addresses held, "localhost" is safe; otherwise name the address outright
    host = "localhost" if len(servers) == 2 else "127.0.0.1"
    url = f"http://{host}:{port}/"
    print(f"\n  Top Mountain Resort is running at {url}")
    print(f"  Serving: {ROOT}")
    print("  Press Ctrl+C to stop.\n")
    if "--no-browser" not in sys.argv:
        threading.Timer(0.8, lambda: webbrowser.open(url)).start()
    for extra in servers[1:]:
        threading.Thread(target=extra.serve_forever, daemon=True).start()
    try:
        servers[0].serve_forever()
    except KeyboardInterrupt:
        print("\nStopped.")
    finally:
        for s in servers:
            s.server_close()


if __name__ == "__main__":
    main()
