#!/usr/bin/env bash
#
# Build a double-clickable macOS application for DineReserve.
#
#   scripts/build-macos-app.sh            -> dist/DineReserve.app
#
# The bundle is self-contained: it ships the static front-end, the PHP engine and
# the PHP CLI binary. Double-clicking starts the server on 127.0.0.1:8317 (or the
# next free port), seeds a database under ~/Library/Application Support/DineReserve
# on first run and opens the entry page in the default browser.
#
# Requirements: macOS, a static PHP 8.1+ CLI (default ~/.local/dinereserve/php,
# override with PHP_BIN).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_NAME="DineReserve"
BUNDLE_ID="com.dinereserve.app"
APP="$ROOT/dist/$APP_NAME.app"
PHP_BIN="${PHP_BIN:-$HOME/.local/dinereserve/php}"
PORT="${PORT:-8317}"

if [ ! -x "$PHP_BIN" ]; then
  echo "PHP CLI not found at $PHP_BIN — set PHP_BIN to a PHP 8.1+ binary" >&2
  exit 1
fi

echo "Building $APP"
rm -rf "$APP"
mkdir -p "$APP/Contents/MacOS" "$APP/Contents/Resources"

# ---------------------------------------------------------------- payload
RES="$APP/Contents/Resources"
mkdir -p "$RES/app"
rsync -a \
  --exclude '.git' --exclude 'tests' --exclude 'vendor' --exclude 'dist' \
  --exclude 'storage' --exclude 'database/*.sqlite*' --exclude '.github' \
  "$ROOT/" "$RES/app/"
cp "$PHP_BIN" "$RES/php"
chmod +x "$RES/php"
cp "$ROOT/scripts/router.php" "$RES/router.php"

# --------------------------------------------------------------- launcher
cat >"$APP/Contents/MacOS/$APP_NAME" <<LAUNCHER
#!/bin/bash
# DineReserve launcher: start the bundled PHP server, open the browser, stay alive
# until the application quits (trap: stop the server).
set -u
BUNDLE="\$(cd "\$(dirname "\$0")/.." && pwd)"
RES="\$BUNDLE/Resources"
APP_DIR="\$RES/app"
PHP="\$RES/php"
ROUTER="\$RES/router.php"
DATA="\$HOME/Library/Application Support/DineReserve"
DB="\$DATA/dinereserve.sqlite"
LOG="\$DATA/server.log"
PID_FILE="\$DATA/server.pid"
BASE_PORT=${PORT}

mkdir -p "\$DATA"

find_port() {
  local port=\$BASE_PORT
  while [ \$port -lt \$((BASE_PORT + 30)) ]; do
    if ! nc -z 127.0.0.1 \$port >/dev/null 2>&1; then
      echo \$port
      return 0
    fi
    port=\$((port + 1))
  done
  echo \$BASE_PORT
  return 0
}

start_server() {
  if [ -f "\$PID_FILE" ]; then
    local old
    old=\$(cat "\$PID_FILE" 2>/dev/null || true)
    if [ -n "\$old" ] && kill -0 "\$old" 2>/dev/null; then
      curl -s --max-time 2 "http://127.0.0.1:\$PORT/api/v1/health" >/dev/null 2>&1 && return 0
      kill "\$old" 2>/dev/null || true
    fi
  fi
  if [ ! -f "\$DB" ]; then
    "\$PHP" "\$APP_DIR/bin/seed.php" "\$DB" >>"\$LOG" 2>&1 || true
  fi
  cd "\$APP_DIR"
  export APP_ROOT="\$APP_DIR"
  DB_PATH="\$DB" RESTAURANT_TIMEZONE="Asia/Macau" \\
    "\$PHP" -S 127.0.0.1:\$PORT -t "\$APP_DIR" "\$ROUTER" >>"\$LOG" 2>&1 &
  echo \$! >"\$PID_FILE"
}

stop_server() {
  if [ -f "\$PID_FILE" ]; then
    local pid
    pid=\$(cat "\$PID_FILE" 2>/dev/null || true)
    [ -n "\$pid" ] && kill "\$pid" 2>/dev/null || true
    rm -f "\$PID_FILE"
  fi
}

trap stop_server EXIT TERM INT
PORT=\$(find_port)
start_server
for _ in \$(seq 1 40); do
  if curl -s --max-time 1 "http://127.0.0.1:\$PORT/api/v1/health" >/dev/null 2>&1; then
    break
  fi
  sleep 0.25
done
open "http://127.0.0.1:\$PORT/login.html"

# Keep the app alive, and restart the server if it ever stops answering.
while true; do
  sleep 15 &
  wait \$!
  if ! curl -s --max-time 2 "http://127.0.0.1:\$PORT/api/v1/health" >/dev/null 2>&1; then
    start_server
  fi
done
LAUNCHER
chmod +x "$APP/Contents/MacOS/$APP_NAME"

# ------------------------------------------------------------------ info
cat >"$APP/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>CFBundleName</key><string>$APP_NAME</string>
  <key>CFBundleDisplayName</key><string>$APP_NAME</string>
  <key>CFBundleIdentifier</key><string>$BUNDLE_ID</string>
  <key>CFBundleVersion</key><string>1.0.0</string>
  <key>CFBundleShortVersionString</key><string>1.0.0</string>
  <key>CFBundlePackageType</key><string>APPL</string>
  <key>CFBundleExecutable</key><string>$APP_NAME</string>
  <key>CFBundleIconFile</key><string>AppIcon</string>
  <key>LSMinimumSystemVersion</key><string>11.0</string>
  <key>NSHighResolutionCapable</key><true/>
  <key>LSUIElement</key><false/>
</dict>
</plist>
PLIST

# ------------------------------------------------------------------ icon
if [ -f "$ROOT/assets/app/icon-1024.png" ]; then
  ICONSET="$ROOT/dist/AppIcon.iconset"
  rm -rf "$ICONSET"; mkdir -p "$ICONSET"
  for size in 16 32 64 128 256 512; do
    sips -z $size $size "$ROOT/assets/app/icon-1024.png" --out "$ICONSET/icon_${size}x${size}.png" >/dev/null
    double=$((size * 2))
    sips -z $double $double "$ROOT/assets/app/icon-1024.png" --out "$ICONSET/icon_${size}x${size}@2x.png" >/dev/null
  done
  iconutil -c icns "$ICONSET" -o "$APP/Contents/Resources/AppIcon.icns" >/dev/null
  rm -rf "$ICONSET"
fi

# ---------------------------------------------------------------- sign/zip
codesign --force --deep --sign - "$APP" >/dev/null 2>&1 || true
ZIP="$ROOT/dist/$APP_NAME-macOS.zip"
rm -f "$ZIP"
ditto -c -k --keepParent "$APP" "$ZIP"

echo "built $APP"
echo "zip   $ZIP ($(du -sh "$ZIP" | cut -f1))"
