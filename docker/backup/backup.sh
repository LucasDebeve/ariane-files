#!/bin/sh
# Encrypted, deduplicated backup to a second provider with restic.
# Runs on the host (systemd timer, see docs/deploiement.md). Needs restic installed
# and RESTIC_REPOSITORY / RESTIC_PASSWORD (+ provider credentials) in .env.prod.
set -eu
cd "$(dirname "$0")/../.."
set -a; . ./.env.prod; set +a

STAGING=$(mktemp -d /var/tmp/ariane-backup.XXXXXX)
trap 'rm -rf "$STAGING"' EXIT

# 1. Consistent PostgreSQL dump.
docker compose --env-file .env.prod exec -T database \
    pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom --no-owner > "$STAGING/ariane.dump"

# 2. Garage: metadata snapshots + data blocks (volume directories on the host).
GARAGE_META=$(docker volume inspect -f '{{ .Mountpoint }}' ariane_garage_meta)
GARAGE_DATA=$(docker volume inspect -f '{{ .Mountpoint }}' ariane_garage_data)
docker compose --env-file .env.prod exec -T garage /garage meta snapshot

restic backup --tag ariane --host ariane \
    "$STAGING/ariane.dump" "$GARAGE_META/snapshots" "$GARAGE_DATA" .env.prod

restic forget --tag ariane --keep-daily 7 --keep-weekly 5 --keep-monthly 12 --prune
restic check --read-data-subset=5%
