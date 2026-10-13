#!/data/data/com.termux/files/usr/bin/bash

set -Eeuo pipefail

APP_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PORT="${PORT:-8002}"
HOST="${HOST:-0.0.0.0}"
SERVER_PID=""
WAKE_LOCKED=0

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

cleanup() {
    if [[ -n "$SERVER_PID" ]]; then
        kill "$SERVER_PID" 2>/dev/null || true
    fi
    if [[ "$WAKE_LOCKED" -eq 1 ]] && command -v termux-wake-unlock >/dev/null 2>&1; then
        termux-wake-unlock || true
    fi
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

[[ -f "$APP_DIR/artisan" && -f "$APP_DIR/vendor/autoload.php" ]] || die "Run the one-time setup first with: bash setup-termux.sh"
[[ -f "$APP_DIR/.env" && -f "$APP_DIR/database/database.sqlite" ]] || die "App configuration/database is missing. Run: bash setup-termux.sh"
[[ "$PORT" =~ ^[0-9]+$ ]] && (( PORT >= 1024 && PORT <= 65535 )) || die "PORT must be between 1024 and 65535."
[[ "$HOST" == "127.0.0.1" || "$HOST" == "0.0.0.0" ]] || die "HOST must be 127.0.0.1 or 0.0.0.0."
[[ "${PREFIX:-}" == "/data/data/com.termux/files/usr" ]] || die "This start script is intended to run inside Termux on Android."

for extension in pdo_sqlite sqlite3 mbstring openssl tokenizer xml ctype json fileinfo dom; do
    php -m | grep -qi "^${extension}$" || die "PHP extension '$extension' is missing. Run setup-termux.sh again after checking the Termux PHP package."
done

cd "$APP_DIR"
URL="http://127.0.0.1:${PORT}/posetivacwingspos"
LAN_IP="$(ip route get 1.1.1.1 2>/dev/null | awk '{for (i=1; i<=NF; i++) if ($i=="src") {print $(i+1); exit}}' || true)"
if [[ -z "$LAN_IP" ]]; then
    LAN_IP="$(ip -o -4 addr show wlan0 2>/dev/null | awk '{split($4, address, "/"); print address[1]; exit}' || true)"
fi
if [[ -z "$LAN_IP" ]] && command -v getprop >/dev/null 2>&1; then
    LAN_IP="$(getprop dhcp.wlan0.ipaddress 2>/dev/null | sed 's,/.*,,')"
fi
LAN_URL=""
if [[ "$HOST" == "0.0.0.0" && -n "$LAN_IP" ]]; then
    LAN_URL="http://${LAN_IP}:${PORT}/posetivacwingspos"
    export APP_URL="http://${LAN_IP}:${PORT}"
fi

if curl --silent --fail --max-time 2 "$URL" >/dev/null 2>&1; then
    printf 'ETIVAC Wings POS is already running: %s\n' "$URL"
    if [[ "$HOST" == "0.0.0.0" && -n "$LAN_URL" ]]; then
        if curl --silent --fail --max-time 2 "$LAN_URL" >/dev/null 2>&1; then
            printf 'For other devices on the same Wi-Fi, open: %s\n' "$LAN_URL"
        else
            printf 'The existing server is not reachable from the Wi-Fi address. Stop the old server with Ctrl+C and run this updated start script again.\n'
            exit 1
        fi
    fi
    command -v termux-open-url >/dev/null 2>&1 && termux-open-url "$URL"
    exit 0
fi

if command -v termux-wake-lock >/dev/null 2>&1; then
    termux-wake-lock
    WAKE_LOCKED=1
fi

printf 'Starting ETIVAC Wings POS: %s\n' "$URL"
printf 'Keep Termux open while using the POS. Press Ctrl+C to stop the server.\n'
if [[ "$HOST" == "0.0.0.0" ]]; then
    if [[ -n "$LAN_URL" ]]; then
        printf 'For other devices on the same Wi-Fi, open: %s\n' "$LAN_URL"
    else
        printf 'Could not detect the tablet Wi-Fi IP. Connect to Wi-Fi and check Android Settings > About tablet > Status > IP address.\n'
        printf 'The server is listening on Wi-Fi; other devices can use http://<tablet-ip>:%s/posetivacwingspos\n' "$PORT"
    fi
    printf 'Use only on a trusted private Wi-Fi network. Do not expose this server to the public internet.\n'
else
    printf 'This server is local-only. To allow other Wi-Fi devices, restart with HOST=0.0.0.0 bash start-termux.sh.\n'
fi

php artisan serve --host="$HOST" --port="$PORT" &
SERVER_PID=$!

for _ in {1..20}; do
    if curl --silent --fail --max-time 2 "$URL" >/dev/null 2>&1; then
        break
    fi
    if ! kill -0 "$SERVER_PID" 2>/dev/null; then
        wait "$SERVER_PID"
        die "Laravel server stopped during startup."
    fi
    sleep 1
done

if ! curl --silent --show-error --fail --max-time 5 "$URL" >/dev/null; then
    die "The POS did not respond at $URL. Check storage/logs/laravel.log."
fi

if command -v termux-open-url >/dev/null 2>&1; then
    termux-open-url "$URL"
fi

wait "$SERVER_PID"
