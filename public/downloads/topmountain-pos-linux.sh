#!/usr/bin/env bash
# Sets up a Linux PC as the Top Mountain POS: a "Top Mountain POS" shortcut (desktop, app menu and
# start-up) that opens the POS in Chrome with kiosk printing, so bills, invoices and receipts print
# straight to the default printer — no print window. See hosting/POS-PRINTING.md.
#
#   bash pos-kiosk-linux.sh                         (the live POS: https://www.yourdomain.lk/pos)
#   bash pos-kiosk-linux.sh http://192.168.1.20:3000/pos   (another address)
#
# Run it as the user who works on the POS (not with sudo). Running it again updates the shortcut.
set -euo pipefail

URL="${1:-https://www.yourdomain.lk/pos}"

BROWSER=""
for b in google-chrome google-chrome-stable chromium chromium-browser microsoft-edge; do
  if command -v "$b" >/dev/null 2>&1; then BROWSER="$(command -v "$b")"; break; fi
done
if [ -z "$BROWSER" ]; then
  echo "Google Chrome isn't installed. Download the .deb from https://www.google.com/chrome/ and open it to install, then run this again."
  exit 1
fi

# Its own Chrome profile: kiosk printing only works in a Chrome that was started with it, and a
# separate profile keeps it apart from any normal Chrome window that is already open.
PROFILE="$HOME/.config/topmountain-pos"
BIN="$HOME/.local/bin"
APPS="$HOME/.local/share/applications"
AUTOSTART="$HOME/.config/autostart"
mkdir -p "$PROFILE" "$BIN" "$APPS" "$AUTOSTART"

LAUNCHER="$BIN/topmountain-pos"
cat > "$LAUNCHER" <<EOF
#!/usr/bin/env bash
# The Top Mountain POS: prints straight to the default printer (--kiosk-printing).
exec "$BROWSER" --user-data-dir="$PROFILE" --kiosk-printing --no-first-run --no-default-browser-check --start-maximized --app="$URL" "\$@"
EOF
chmod +x "$LAUNCHER"

ENTRY="[Desktop Entry]
Type=Application
Name=Top Mountain POS
Comment=The POS screen. Prints straight to the printer.
Exec=$LAUNCHER
Icon=printer
Terminal=false
Categories=Office;"
printf '%s\n' "$ENTRY" > "$APPS/topmountain-pos.desktop"
printf '%s\n' "$ENTRY" > "$AUTOSTART/topmountain-pos.desktop"   # opens when the PC starts

DESK="$(xdg-user-dir DESKTOP 2>/dev/null || echo "$HOME/Desktop")"
if [ -d "$DESK" ]; then
  cp "$APPS/topmountain-pos.desktop" "$DESK/topmountain-pos.desktop"
  chmod +x "$DESK/topmountain-pos.desktop"
  gio set "$DESK/topmountain-pos.desktop" metadata::trusted true 2>/dev/null || true
fi

echo
echo "Done: the 'Top Mountain POS' shortcut opens $URL"
echo "Browser: $BROWSER"
if command -v lpstat >/dev/null 2>&1; then
  echo "Default printer: $(lpstat -d 2>/dev/null | sed 's/^.*: //' || true)"
  echo "Printers on this PC:"; lpstat -p 2>/dev/null | sed 's/^/  /' || true
fi
echo
echo "Close any open POS window, then open 'Top Mountain POS' from the desktop or the app menu."
echo "It also opens by itself when the PC starts."
