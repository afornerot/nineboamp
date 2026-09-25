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

bin/console messenger:consume async --time-limit=3600 --memory-limit=128M &
MESSENGER_PID=$!

# Start LlamaIndex agent on 8001 (PHP keeps calling 8000 = Go agent)
cd /app && python3 -m uvicorn agent.main:app --host 127.0.0.1 --port 8001 &
AGENT_PID=$!

# Start Go agent (genai) on 8000, same HTTP contract as the Python agent
/usr/local/bin/nineagent \
    -addr 127.0.0.1:8000 \
    -env-dir /app \
    -jobs-dir /tmp/jobs-go &
AGENT_GO_PID=$!

"$@" &
APACHE_PID=$!

graceful_stop() {
    kill -TERM "$SUPERCRONIC_PID" "$APACHE_PID" "$MESSENGER_PID" "$AGENT_PID" "$AGENT_GO_PID" 2>/dev/null || true
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
    if ! kill -0 "$MESSENGER_PID" 2>/dev/null; then
        echo "$(date '+%F %T') STOP MESSENGER" >> var/log/startup.log
        break
    fi
    if ! kill -0 "$AGENT_PID" 2>/dev/null; then
        echo "$(date '+%F %T') STOP AGENT" >> var/log/startup.log
        break
    fi
    if ! kill -0 "$AGENT_GO_PID" 2>/dev/null; then
        echo "$(date '+%F %T') STOP AGENT_GO" >> var/log/startup.log
        break
    fi
    sleep 2
done

kill -TERM "$SUPERCRONIC_PID" "$APACHE_PID" "$MESSENGER_PID" "$AGENT_PID" "$AGENT_GO_PID" 2>/dev/null || true
wait
