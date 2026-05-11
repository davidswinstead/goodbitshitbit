#!/usr/bin/env bash
# =============================================================================
# deploy.sh — Comedy Bits Elo Tracker — rsync/SSH deployment to cPanel
#
# Use this if you are NOT using cPanel's Git Version Control.
# Run from the project root directory.
#
# Requirements:
#   - rsync and ssh available locally
#     Windows: use Git Bash, WSL, or Cygwin
#   - SSH access to the cPanel account (key auth recommended)
#
# Usage:
#   bash deploy.sh
#   bash deploy.sh --dry-run    # preview what would happen without rsync -n
# =============================================================================

set -euo pipefail

# ── Configuration ─────────────────────────────────────────────────────────────
SSH_USER="a2bremov"
SSH_HOST="davidswinstead.com"
REMOTE_DIR="/home/a2bremov/davidswinstead.com/misc/goodbitshitbit"
REMOTE="${SSH_USER}@${SSH_HOST}"

DRY_RUN=""
if [[ "${1:-}" == "--dry-run" ]]; then
    DRY_RUN="--dry-run"
    echo ">>> DRY RUN — no changes will be made <<<"
    echo ""
fi

# ── Files/dirs to exclude from the sync ──────────────────────────────────────
#  comedy.db   — NEVER overwrite production data
#  .git/       — git internals
#  .vscode/    — editor config
#  hello.md    — workspace scratch file
#  deploy.sh   — no need to ship the deploy script itself
EXCLUDES=(
    "--exclude=comedy.db"
    "--exclude=comedy.db-wal"
    "--exclude=comedy.db-shm"
    "--exclude=.git/"
    "--exclude=.vscode/"
    "--exclude=hello.md"
    "--exclude=deploy.sh"
)

# ── Step 1: Create remote directory ───────────────────────────────────────────
echo "==> [1/5] Ensuring remote directory exists..."
ssh "${REMOTE}" "mkdir -p '${REMOTE_DIR}'"

# ── Step 2: Sync files ────────────────────────────────────────────────────────
echo "==> [2/5] Syncing files (comedy.db excluded)..."
rsync \
    -avz \
    --progress \
    --checksum \
    ${DRY_RUN} \
    "${EXCLUDES[@]}" \
    ./ \
    "${REMOTE}:${REMOTE_DIR}/"

if [[ -n "${DRY_RUN}" ]]; then
    echo ""
    echo "Dry run complete — no remote changes made."
    exit 0
fi

# ── Step 3: Fix permissions on server ────────────────────────────────────────
echo "==> [3/5] Setting permissions..."
ssh "${REMOTE}" bash << REMOTE_SCRIPT
set -e
TARGET="${REMOTE_DIR}"

# Directories: world-traversable (rwxr-xr-x)
find "\$TARGET" -type d -exec chmod 755 {} +

# PHP and config files: readable by webserver, not writable (rw-r--r--)
find "\$TARGET" -type f \( -name "*.php" -o -name ".htaccess" \) -exec chmod 644 {} +

# .htpasswd: owner + group readable, not world-readable (rw-r-----)
chmod 640 "\$TARGET/.htpasswd" 2>/dev/null || chmod 644 "\$TARGET/.htpasswd"

# SQLite DB: owner + group read/write so PHP can write as the webserver user
# (rw-rw-r-- = 664)
if [ -f "\$TARGET/comedy.db" ]; then
    chmod 664 "\$TARGET/comedy.db"
fi
REMOTE_SCRIPT

# ── Step 4: Patch AuthUserFile to the live server path ───────────────────────
echo "==> [4/5] Patching .htaccess AuthUserFile path..."
ssh "${REMOTE}" \
    "sed -i 's|^AuthUserFile .*|AuthUserFile ${REMOTE_DIR}/.htpasswd|' '${REMOTE_DIR}/.htaccess'"

# ── Step 5: Init DB (first deploy only) ──────────────────────────────────────
echo "==> [5/5] Checking database..."
ssh "${REMOTE}" bash << REMOTE_SCRIPT
TARGET="${REMOTE_DIR}"
if [ -f "\$TARGET/comedy.db" ]; then
    echo "    comedy.db already exists — data preserved, no init needed."
else
    echo "    comedy.db not found — running init_db.php..."
    php "\$TARGET/init_db.php"
    chmod 664 "\$TARGET/comedy.db"
    echo "    Database initialised successfully."
fi
REMOTE_SCRIPT

# ── Done ─────────────────────────────────────────────────────────────────────
echo ""
echo "============================================================"
echo " Deployment complete!"
echo " URL:  https://davidswinstead.com/misc/goodbitshitbit/"
echo " Auth: david / smeghead"
echo "============================================================"
