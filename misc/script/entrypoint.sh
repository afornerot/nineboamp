#!/bin/sh
# Démarre en root uniquement pour aligner les volumes montés sur le propriétaire
# du code, puis abandonne les privilèges.
#
# Le conteneur doit tourner avec l'UID de l'utilisateur hôte pour accéder aux
# montages (./var, ./uploads…). Cet UID ne peut pas venir de ${UID} dans
# compose.yaml : bash ne l'exporte pas, donc l'interpolation retombait sur 1000
# et tout `>> var/log/cron.log` échouait en « Permission denied » (app:cron
# sortait en code 1 à chaque minute). On le déduit du montage, ce qui suit l'hôte.
#
# Le même décalage casse les workspaces amoxtli (uploads/amoxtli/{id}/.amoxtli
# créés en 750 par un conteneur précédent) : `doc list` répond alors
# « permission denied » dans l'onglet « Documents indexés ». D'où le réalignement
# de tous les volumes, pas seulement de var/.
set -e

if [ "$(id -u)" != "0" ]; then
    exec "$@"
fi

TARGET_UID="$(stat -c %u /app/src 2>/dev/null || echo 0)"
if [ "$TARGET_UID" = "0" ]; then
    # Pas de bind mount du code (image seule) : rester sur l'utilisateur de l'image.
    TARGET_UID=100
fi
# GID 101 = apache dans l'image : httpd en a besoin pour /var/log/apache2.
TARGET_GID=101
TARGET="$TARGET_UID:$TARGET_GID"

# Réalignement idempotent : au démarrage suivant les fichiers sont déjà au bon
# propriétaire, chown ne fait que réécrire les mêmes métadonnées.
mkdir -p /app/var/log
chown -R "$TARGET" /app/var
chown -R "$TARGET" /app/uploads
chown -R "$TARGET" /app/public/uploads

# apache2.sh réécrit env.conf au démarrage s'il peut le toucher.
chown "$TARGET" /etc/apache2/conf.d /etc/apache2/conf.d/env.conf 2>/dev/null || true

exec su-exec "$TARGET" "$@"
