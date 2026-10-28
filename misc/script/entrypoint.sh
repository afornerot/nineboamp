#!/bin/sh
# Le container tourne sans privilèges avec l'utilisateur `apache` de l'image
# (UID 100, GID 101). Les répertoires montés depuis l'hôte doivent lui
# appartenir : sans cela, chaque écriture échoue en « Permission denied ».
#
# Ce contrôle existe parce que l'échec est silencieux : le crontab sortait en
# code 1 chaque minute (« Permission denied » sur var/log/cron.log) et amoxtli
# répondait « permission denied » sur les workspaces de l'onglet
# « Documents indexés », sans que le container ne le signale.
#
# Le container ne prend JAMAIS de privilèges pour réparer ça : c'est à l'hôte
# d'aligner les droits.
set -e

for dir in /app/var /app/var/log /app/uploads /app/public/uploads; do
    [ -d "$dir" ] || continue
    [ -w "$dir" ] && [ -x "$dir" ] && continue

    owner="$(stat -c '%u:%g' "$dir")"
    cat >&2 <<EOF

ERREUR : $dir appartient à $owner, le container tourne en UID $(id -u) (apache).

Les répertoires montés depuis l'hôte doivent appartenir à l'utilisateur
\`apache\` du container. Depuis l'hôte, à la racine du projet :

    sudo chown -R 100:101 var uploads public/uploads

Puis relancer :  docker compose up -d

(Ce message disparaît ensuite : les fichiers créés par l'application le sont
déjà avec le bon UID.)
EOF
    exit 1
done

exec "$@"
