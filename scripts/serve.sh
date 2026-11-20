#!/usr/bin/env bash
#
# Local deployment helper for the DineReserve demo server.
#
#   scripts/serve.sh start      start on 0.0.0.0:8080 (LAN accessible)
#   scripts/serve.sh stop       stop the server
#   scripts/serve.sh restart
#   scripts/serve.sh status     PID, port and health check
#   scripts/serve.sh logs       tail the server log
#   scripts/serve.sh install    install a launchd agent (auto-start at login, restart on crash)
#   scripts/serve.sh uninstall  remove the launchd agent
#
# Environment:
#   PORT      listen port (default 8080)
#   BIND      listen address (default 0.0.0.0 so phones/tablets on the LAN can reach it)
#   PHP_BIN   PHP CLI binary (default ~/.local/dinereserve/php, then `php`)
#   DB_PATH   SQLite file (default database/local.sqlite)
#
# The server runs the project from this repository; run `php bin/seed.php` first if
# the database is missing. Logs and the PID file live under storage/.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${PORT:-8080}"
BIND="${BIND:-0.0.0.0}"
DB_PATH="${DB_PATH:-database/local.sqlite}"
PID_FILE="$ROOT/storage/dinereserve-server.pid"
LOG_FILE="$ROOT/storage/dinereserve-server.log"

find_php() {
  if [ -n "${PHP_BIN:-}" ] && [ -x "${PHP_BIN}" ]; then echo "${PHP_BIN}"; return; fi
  if [ -x "$HOME/.local/dinereserve/php" ]; then echo "$HOME/.local/dinereserve/php"; return; fi
  if command -v php >/dev/null 2>&1; then command -v php; return; fi
  echo "PHP not found: put a PHP 8.1+ CLI at ~/.local/dinereserve/php or set PHP_BIN" >&2
  exit 1
}

is_running() {
  [ -f "$PID_FILE" ] || return 1
  local pid
  pid="$(cat "$PID_FILE" 2>/dev/null || true)"
  [ -n "$pid" ] || return 1
  kill -0 "$pid" 2>/dev/null
}

start() {
  if is_running; then
    echo "already running (pid $(cat "$PID_FILE")) on http://$BIND:$PORT"
    return 0
  fi
  mkdir -p "$ROOT/storage"
  local php
  php="$(find_php)"
  if [ "$DB_PATH" != ":memory:" ] && [ ! -f "$ROOT/$DB_PATH" ]; then
    echo "database $DB_PATH not found — running bin/seed.php first"
    (cd "$ROOT" && "$php" bin/seed.php)
  fi
  cd "$ROOT"
  DB_PATH="$DB_PATH" nohup "$php" -S "$BIND:$PORT" -t "$ROOT" "$ROOT/scripts/router.php" \
    >>"$LOG_FILE" 2>&1 &
  echo $! >"$PID_FILE"
  sleep 1
  if ! is_running; then
    echo "failed to start; see $LOG_FILE" >&2
    tail -20 "$LOG_FILE" >&2 || true
    exit 1
  fi
  local lan
  lan="$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || true)"
  echo "DineReserve running (pid $(cat "$PID_FILE"))"
  echo "  local:  http://127.0.0.1:$PORT/"
  [ -n "$lan" ] && echo "  LAN:    http://$lan:$PORT/  (phone/tablet on the same Wi-Fi)"
  echo "  host:   http://127.0.0.1:$PORT/host.html"
  echo "  entry:  http://127.0.0.1:$PORT/login.html"
}

stop() {
  if ! is_running; then
    echo "not running"
    rm -f "$PID_FILE"
    return 0
  fi
  local pid
  pid="$(cat "$PID_FILE")"
  kill "$pid" 2>/dev/null || true
  for _ in $(seq 1 20); do
    kill -0 "$pid" 2>/dev/null || break
    sleep 0.1
  done
  rm -f "$PID_FILE"
  echo "stopped (pid $pid)"
}

status() {
  if is_running; then
    echo "running (pid $(cat "$PID_FILE")) on http://$BIND:$PORT"
    curl -s --max-time 3 "http://127.0.0.1:$PORT/api/v1/health" || echo "  health check failed"
    echo
  else
    echo "not running"
    exit 1
  fi
}

PLIST_PATH="$HOME/Library/LaunchAgents/com.dinereserve.server.plist"
LAUNCHD_LABEL="com.dinereserve.server"

launchd_loaded() {
  launchctl print "gui/$(id -u)/$LAUNCHD_LABEL" >/dev/null 2>&1
}

install_agent() {
  local php lan
  php="$(find_php)"
  mkdir -p "$HOME/Library/LaunchAgents" "$HOME/Library/Logs"
  cat >"$PLIST_PATH" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$LAUNCHD_LABEL</string>
  <key>ProgramArguments</key>
  <array>
    <string>$php</string><string>-S</string><string>$BIND:$PORT</string>
    <string>-t</string><string>$ROOT</string><string>$ROOT/scripts/router.php</string>
  </array>
  <key>WorkingDirectory</key><string>$ROOT</string>
  <key>EnvironmentVariables</key>
  <dict>
    <key>DB_PATH</key><string>$DB_PATH</string>
    <key>RESTAURANT_TIMEZONE</key><string>Asia/Macau</string>
  </dict>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
  <key>ThrottleInterval</key><integer>5</integer>
  <key>StandardOutPath</key><string>$HOME/Library/Logs/DineReserve.log</string>
  <key>StandardErrorPath</key><string>$HOME/Library/Logs/DineReserve.log</string>
</dict>
</plist>
PLIST
  plutil -lint "$PLIST_PATH" >/dev/null
  launchctl bootout "gui/$(id -u)/$LAUNCHD_LABEL" 2>/dev/null || true
  launchctl bootstrap "gui/$(id -u)" "$PLIST_PATH"
  sleep 2
  lan="$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || true)"
  echo "installed $PLIST_PATH (auto-start at login, restarts if it crashes)"
  echo "  http://127.0.0.1:$PORT/"
  [ -n "$lan" ] && echo "  http://$lan:$PORT/"
}

uninstall_agent() {
  launchctl bootout "gui/$(id -u)/$LAUNCHD_LABEL" 2>/dev/null || true
  rm -f "$PLIST_PATH"
  echo "removed $PLIST_PATH"
}

case "${1:-start}" in
  start) start ;;
  stop) stop ;;
  restart) stop; start ;;
  status)
    if launchd_loaded; then
      echo "running as launchd agent ($LAUNCHD_LABEL) on http://$BIND:$PORT"
      curl -s --max-time 3 "http://127.0.0.1:$PORT/api/v1/health" || echo "  health check failed"
      echo
    else
      status
    fi
    ;;
  logs) tail -n 60 -f "${LOG_FILE}" 2>/dev/null || tail -n 60 -f "$HOME/Library/Logs/DineReserve.log" ;;
  install) install_agent ;;
  uninstall) uninstall_agent ;;
  *) echo "usage: $0 {start|stop|restart|status|logs|install|uninstall}" >&2; exit 2 ;;
esac
