#!/bin/sh
# Restoration drill: restores the latest snapshot into a scratch directory and
# loads the database dump into a throwaway PostgreSQL container. Run monthly.
set -eu
cd "$(dirname "$0")/../.."
set -a; . ./.env.prod; set +a

TARGET=$(mktemp -d /var/tmp/ariane-restore.XXXXXX)
trap 'docker rm -f ariane-restore-db >/dev/null 2>&1 || true; rm -rf "$TARGET"' EXIT

restic restore latest --tag ariane --target "$TARGET"
DUMP=$(find "$TARGET" -name ariane.dump | head -n1)

docker run -d --name ariane-restore-db -e POSTGRES_PASSWORD=restore -v "$DUMP:/dump:ro" postgres:16-alpine >/dev/null
until docker exec ariane-restore-db pg_isready -U postgres >/dev/null 2>&1; do sleep 1; done
docker exec ariane-restore-db createdb -U postgres ariane
docker exec ariane-restore-db pg_restore -U postgres -d ariane --no-owner /dump
DOCS=$(docker exec ariane-restore-db psql -U postgres -d ariane -tAc "select count(*) from document")
BLOCKS=$(find "$TARGET" -path '*garage_data*' -type f | wc -l)

echo "Restore OK: $DOCS documents in the database, $BLOCKS Garage data files."
