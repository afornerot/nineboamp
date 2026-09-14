#!/bin/bash
set -eo pipefail

# Se positionner sur la racine du projet
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
cd ${DIR}
cd ../..
DIR=$(pwd)

mkdir -p var/log

bin/console cache:clear
bin/console d:s:u --force
bin/console app:init

supercronic -quiet -no-reap /crontab &
SUPERCRONIC_PID=$!

"$@" &
APACHE_PID=$!

graceful_stop() {
    kill -TERM "$SUPERCRONIC_PID" "$APACHE_PID" 2>/dev/null || true
    wait
    exit 0
}
trap graceful_stop TERM INT

while true; do
    if ! kill -0 "$SUPERCRONIC_PID" 2>/dev/null; then
        echo "$(date '+%F %T') STOP SUPERCRONIC" >> var/log/startup.log
        break
    fi
    if ! kill -0 "$APACHE_PID" 2>/dev/null; then
        echo "$(date '+%F %T') STOP APACHE" >> var/log/startup.log
        break
    fi
    sleep 2
done

kill -TERM "$SUPERCRONIC_PID" "$APACHE_PID" 2>/dev/null || true
wait
