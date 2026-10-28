#!/bin/sh
# Garde-fou au démarrage : les volumes montés doivent appartenir à l'UID du
# process. Le container ne prend JAMAIS de privilèges pour réparer ça : c'est à
# l'hôte d'aligner les droits.
#
# Sans ce contrôle, un décalage d'UID dégradait en silence — le crontab sortait
# en code 1 chaque minute (« Permission denied » sur var/log/cron.log) et
# amoxtli répondait « permission denied » sur les workspaces de l'onglet
# « Documents indexés ».
set -e

uid="$(id -u)"

for dir in /app/var /app/uploads /app/public/uploads; do
    [ -d "$dir" ] || continue
    [ -w "$dir" ] && [ -x "$dir" ] && continue

    owner="$(stat -c '%u:%g' "$dir")"
    cat >&2 <<EOF

ERREUR : $dir appartient à $owner, le container tourne en UID $uid.

Les répertoires montés depuis l'hôte doivent appartenir à l'UID du container.
Depuis l'hôte, à la racine du projet :

    sudo chown -R $uid:101 var uploads public/uploads

Puis relancer :  docker compose up -d

(Ce message disparaît ensuite : les fichiers créés par l'application le sont
déjà avec le bon UID.)
EOF
    exit 1
done

exec "$@"
