#!/bin/sh
# One-time Garage provisioning (run from the host, in the project directory):
#   ./docker/garage/init.sh
# Creates the layout, imports the access key from .env.prod and the two private buckets.
set -eu

set -a; . ./.env.prod; set +a
garage() { docker compose --env-file .env.prod exec -T garage /garage "$@"; }

NODE_ID=$(garage node id -q | cut -d@ -f1)
garage layout assign -z dc1 -c "${GARAGE_CAPACITY:-400G}" "$NODE_ID"
CURRENT=$(garage layout show | sed -n 's/.*[Cc]urrent cluster layout version: \([0-9]*\).*/\1/p')
garage layout apply --version "$(( ${CURRENT:-0} + 1 ))"

garage key import --yes -n ariane "$S3_KEY" "$S3_SECRET"
for bucket in "$S3_BUCKET_QUARANTINE" "$S3_BUCKET_PUBLISHED"; do
    garage bucket create "$bucket"
    garage bucket allow --read --write --owner "$bucket" --key ariane
done
garage bucket list
