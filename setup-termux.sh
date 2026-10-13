#!/data/data/com.termux/files/usr/bin/bash

set -Eeuo pipefail

APP_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PORT="${PORT:-8002}"
HOST="${HOST:-127.0.0.1}"

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

[[ -f "$APP_DIR/artisan" && -f "$APP_DIR/composer.json" ]] || die "Run this script from a copy of the ETIVAC Wings project."
[[ "$PORT" =~ ^[0-9]+$ ]] && (( PORT >= 1024 && PORT <= 65535 )) || die "PORT must be between 1024 and 65535."
[[ "$HOST" == "127.0.0.1" || "$HOST" == "0.0.0.0" ]] || die "HOST must be 127.0.0.1 or 0.0.0.0."
[[ "${PREFIX:-}" == "/data/data/com.termux/files/usr" ]] || die "This setup script is intended to run inside Termux on Android."

case "$APP_DIR/" in
    "$HOME/storage/shared/"*|/storage/emulated/*)
        die "Move the project into Termux home first (for example: cp -a ~/storage/shared/Download/etivacwings ~/etivacwings), then run ~/etivacwings/setup-termux.sh."
        ;;
esac

cd "$APP_DIR"

printf 'Installing Termux PHP, Composer, and curl packages...\n'
pkg update -y
pkg upgrade -y
pkg install -y php composer curl

if ! php -v >/dev/null 2>&1; then
    die "PHP cannot start. Termux packages may be out of sync; run 'pkg update -y && pkg upgrade -y' and then retry this setup."
fi

for extension in pdo_sqlite sqlite3 mbstring openssl tokenizer xml ctype json fileinfo dom; do
    php -m | grep -qi "^${extension}$" || die "PHP extension '$extension' is missing. Check the Termux PHP package and retry."
done

if [[ ! -f .env ]]; then
    cp .env.example .env
fi

set_env_value() {
    local key="$1"
    local value="$2"
    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

set_env_value APP_ENV production
set_env_value APP_DEBUG false
set_env_value APP_URL "http://${HOST}:${PORT}"
set_env_value DB_CONNECTION sqlite

mkdir -p database storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
if [[ ! -f database/database.sqlite ]]; then
    touch database/database.sqlite
fi

printf 'Installing PHP dependencies...\n'
composer install --no-dev --optimize-autoloader --no-interaction

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
fi

php artisan optimize:clear
php artisan migrate --seed --force
php artisan route:cache
php artisan view:cache

exec bash "$APP_DIR/start-termux.sh"
