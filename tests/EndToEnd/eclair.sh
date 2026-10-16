#!/bin/sh
# Starts Eclair in Docker for the end-to-end tests, as CI does: Redis, then the Eclair image in `all`
# mode with SQLite. It creates a project with an API key, registers the `app` storage that points to
# the application of StorageTest, and prints the variables of the tests:
#
#   export $(tests/EndToEnd/eclair.sh up)
#   vendor/bin/phpunit --testsuite EndToEnd
#   tests/EndToEnd/eclair.sh down
#
# `logs` prints Eclair's logs. ECLAIR_IMAGE (ghcr.io/urbania-media/eclair:main), ECLAIR_E2E_PORT
# (18080, Eclair's API) and ECLAIR_E2E_APP_PORT (8099, the application) change the defaults.

set -eu

image=${ECLAIR_IMAGE:-ghcr.io/urbania-media/eclair:main}
port=${ECLAIR_E2E_PORT:-18080}
app_port=${ECLAIR_E2E_APP_PORT:-8099}
name=laravel-eclair-e2e

down() {
    docker rm --force "$name-eclair" "$name-redis" >/dev/null 2>&1 || true
    docker volume rm "$name-data" "$name-tmp" >/dev/null 2>&1 || true
    docker network rm "$name" >/dev/null 2>&1 || true
}

fail() {
    echo "eclair.sh: $*" >&2
    docker logs --tail 100 "$name-eclair" >&2 2>&1 || true
    exit 1
}

up() {
    down
    echo "Starting $image" >&2
    docker network create "$name" >/dev/null
    # BullMQ needs Redis to keep every key.
    docker run --detach --name "$name-redis" --network "$name" redis:8 \
        redis-server --maxmemory-policy noeviction >/dev/null

    env_file=$(mktemp)
    trap 'rm -f "$env_file"' EXIT
    cat >"$env_file" <<ENV
DATABASE_URL=sqlite:///var/lib/eclair/eclair.db
REDIS_URL=redis://$name-redis:6379
ECLAIR_SECRET_KEY=$(openssl rand -base64 32)
ECLAIR_ADMIN_TOKEN=$(openssl rand -hex 32)
ECLAIR_PUBLIC_URL=http://127.0.0.1:$port
ECLAIR_QUEUES=cpu:2,video:1
ECLAIR_LOG_LEVEL=warn
ENV

    # As in Eclair's compose file: a read-only image, with volumes for the data and the tasks.
    set -- --network "$name" --env-file "$env_file" \
        --volume "$name-data:/var/lib/eclair" --volume "$name-tmp:/tmp/eclair" \
        --read-only --cap-drop ALL --security-opt no-new-privileges:true
    docker run --rm "$@" "$image" eclair migrate >&2 || fail 'the migrations failed'
    # host.docker.internal is the machine that runs the tests, where the application listens.
    docker run --detach --name "$name-eclair" "$@" --publish "127.0.0.1:$port:8080" \
        --add-host host.docker.internal:host-gateway "$image" all >/dev/null

    attempt=0
    until curl --silent --fail --output /dev/null "http://127.0.0.1:$port/ready"; do
        attempt=$((attempt + 1))
        [ "$attempt" -lt 60 ] || fail 'Eclair is not ready'
        sleep 1
    done

    eclair() {
        docker exec "$name-eclair" eclair "$@"
    }
    eclair projects:create e2e >/dev/null || fail 'could not create the project'
    key=$(eclair keys:create e2e | tail -n 1)
    case "$key" in ek_*) ;; *) fail "no API key: $key" ;; esac
    secret=$(eclair projects:secret e2e | tail -n 1)
    eclair storages:set e2e app --kind http \
        --config "{\"url\":\"http://host.docker.internal:$app_port/eclair/storages/app\"}" >/dev/null ||
        fail 'could not register the app storage'

    echo "Eclair is ready at http://127.0.0.1:$port" >&2
    echo "ECLAIR_E2E_URL=http://127.0.0.1:$port"
    echo "ECLAIR_E2E_KEY=$key"
    echo "ECLAIR_E2E_WEBHOOK_SECRET=$secret"
    echo "ECLAIR_E2E_APP=0.0.0.0:$app_port"
}

case "${1:-}" in
    up) up ;;
    down) down ;;
    logs) docker logs "$name-eclair" ;;
    *)
        echo "Usage: $0 up|down|logs" >&2
        exit 2
        ;;
esac
