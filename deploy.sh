#!/usr/bin/env bash
# Manual deploy: package the blog admin and API and push it to the cPanel host.
#
#   bash deploy.sh
#
# It assumes a `konstelasi` entry in your own ~/.ssh/config (host, port, user
# and key), so no credential of any kind lives in this repository. Run it
# from Git Bash on Windows, since `npm run` may find the WSL bash.exe first.
#
# Everything is built here, so the host needs PHP but neither Composer nor
# Node. The host's .env and storage/ are never touched, because the archive
# leaves both out.
set -euo pipefail

cd "$(dirname "$0")"

REMOTE_HOST="konstelasi"

# Relative to the remote $HOME, the default working directory of an SSH
# session, so the account's username never appears here. The subdomain
# blog.konstelasi.co.id has its document root at ~/konstelasi-blog/public.
# This folder must never be public_html, anything under stardust, or one of
# the site's own folders (konstelasi-staging, konstelasi-site), because the
# cleanup below deletes code folders inside it.
REMOTE_DIR="konstelasi-blog"

# The PHP binary on the host. cPanel's default `php` may be older than the
# version MultiPHP gives the subdomain, so set this to the matching binary
# if needed, for example REMOTE_PHP=/opt/cpanel/ea-php84/root/usr/bin/php.
REMOTE_PHP="${REMOTE_PHP:-php}"

ARCHIVE_NAME="konstelasi-blog-deploy-$(date +%Y%m%d%H%M%S).tar.gz"

cleanup() {
  rm -f "$ARCHIVE_NAME"
  # Put the development packages back, so the tests run again locally.
  echo "==> Restoring development dependencies..."
  composer install --no-interaction --quiet || true
}
trap cleanup EXIT

echo "==> Testing..."
composer install --no-interaction --quiet
php artisan test

echo "==> Building the admin theme..."
npm ci
npm run build

if [ ! -f public/build/manifest.json ]; then
  echo "Error: public/build/manifest.json not found after the build." >&2
  exit 1
fi

echo "==> Installing production dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Packaging..."
# bootstrap/cache holds caches with this machine's paths in them, so the
# host builds its own.
tar -czf "$ARCHIVE_NAME" \
  --exclude='./.git' \
  --exclude='./.env' \
  --exclude='./.env.*' \
  --exclude='./storage' \
  --exclude='./node_modules' \
  --exclude='./tests' \
  --exclude='./phpunit.xml' \
  --exclude='./.phpunit.cache' \
  --exclude='./.phpunit.result.cache' \
  --exclude='./public/storage' \
  --exclude='./public/hot' \
  --exclude='./bootstrap/cache/*.php' \
  --exclude='./bootstrap/cache/filament' \
  --exclude='./database/*.sqlite' \
  --exclude="./${ARCHIVE_NAME}" \
  --exclude='./deploy.sh' \
  .

echo "==> Uploading to ${REMOTE_HOST}:~/${ARCHIVE_NAME} ..."
scp "$ARCHIVE_NAME" "${REMOTE_HOST}:${ARCHIVE_NAME}"

echo "==> Installing into ~/${REMOTE_DIR} ..."
# The code folders are wiped before extracting, so files that a release
# removed (an old vendor package, a theme file with a stale hash) don't stay
# behind. .env, storage/ and anything cPanel keeps in public/ are left alone.
ssh "$REMOTE_HOST" bash -s <<EOF
set -euo pipefail
cd "${REMOTE_DIR}" 2>/dev/null || { mkdir -p "${REMOTE_DIR}" && cd "${REMOTE_DIR}"; }

if [ ! -f .env ]; then
  echo "Error: ~/${REMOTE_DIR}/.env is missing. Write it once by hand (README.md, first deploy)." >&2
  rm -f "\$HOME/${ARCHIVE_NAME}"
  exit 1
fi

if [ -f artisan ] && [ -d vendor ]; then
  "${REMOTE_PHP}" artisan down --retry=30 || true
fi

rm -rf app config database resources routes vendor \
  public/build public/css/filament public/js/filament public/fonts/filament
rm -rf bootstrap/cache/*.php bootstrap/cache/filament

tar -xzf "\$HOME/${ARCHIVE_NAME}" -C .
rm -f "\$HOME/${ARCHIVE_NAME}"

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
  storage/framework/views storage/logs bootstrap/cache

"${REMOTE_PHP}" artisan package:discover
"${REMOTE_PHP}" artisan migrate --force
# Post images live on the public disk. After the first deploy the link
# already exists, and the command says so without harm.
"${REMOTE_PHP}" artisan storage:link || true
"${REMOTE_PHP}" artisan optimize
"${REMOTE_PHP}" artisan up
EOF

echo "==> Deployed to ~/${REMOTE_DIR}"
