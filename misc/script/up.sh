#!/bin/sh
# Démarre la stack avec l'UID de l'utilisateur courant.
#
# `compose.yaml` déclare `user: "${LOCAL_UID}:101"`. On ne peut pas utiliser
# `${UID}` : bash ne l'exporte pas, l'interpolation retombait donc sur un défaut
# (1000) et le container tournait avec un autre UID que les bind mounts.
# Conséquences observées : `app:cron` sortait en code 1 sur « Permission denied »
# (var/log/cron.log), et amoxtli refusait de lire les workspaces de l'onglet
# « Documents indexés ».
#
# Aucun privilège n'est demandé : si les répertoires montés appartiennent à un
# autre compte, le container s'arrête avec la commande `chown` à lancer.
set -e

cd "$(dirname "$0")/../.."

export LOCAL_UID="$(id -u)"

exec docker compose "$@"
