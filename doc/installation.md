# Installation

## Prérequis

- Docker + Docker Compose
- PHP 8.2+ (outils locaux : composer, phpstan, php-cs-fixer)

## 1. Cloner le projet

```bash
git clone <repo> && cd nineboamp
```

## 2. Configurer les secrets

Créer `.env.local` pour surcharger les variables sensibles (ce fichier n'est pas commité) :

```bash
cp .env .env.local
```

Éditer `.env.local` et renseigner au minimum :

```env
APP_SECRET=<générer : php -r "echo bin2hex(random_bytes(16));">
APP_ENCRYPT_KEY=<générer>
MCP_SECRET=<générer>
DATABASE_URL="mysql://nineboamp:mot_de_passe@mariadb:3306/nineboamp"
```

> **Règle** : ne jamais modifier `.env` directement. Toutes les surcharges vont
> dans `.env.local`.

### Secrets à modifier avant mise en production

Les valeurs `changeme` présentes dans `.env` sont prévues pour un usage local
uniquement. Il n'existe pas de contrôle bloquant à l'exécution : la modification
des secrets est **la responsabilité de l'exploitant**. Avant toute mise en
production, remplacer impérativement :

| Secret | Où le modifier | Rôle |
|--------|----------------|------|
| `APP_SECRET` | `.env.local` | sessions, CSRF — générer via `php -r "echo bin2hex(random_bytes(16));"` |
| `APP_ENCRYPT_KEY` | `.env.local` | chiffrement des données |
| `MCP_SECRET` | `.env.local` | authentification des clients MCP (voir [authentification.md](authentification.md)) |
| `DATABASE_URL` | `.env.local` | mot de passe de la base |
| `MYSQL_ROOT_PASSWORD` / `MYSQL_PASSWORD` | `compose.override.yaml` | base MariaDB |
| `AI_API_KEY` | `.env.local` | accès LLM (voir [ai.md](ai.md)) |
| `OIDC_CLIENTSECRET` | `.env.local` | flow OIDC (si `MODE_AUTH=OIDC`) |

## 3. Configurer les credentials base de données

Le `compose.yaml` contient des valeurs par défaut (`changeme`). Créer un
`compose.override.yaml` pour les surcharger (ce fichier n'est pas commité) :

```yaml
# compose.override.yaml
services:
  mariadb:
    environment:
      MYSQL_ROOT_PASSWORD: un_vrai_mdp
      MYSQL_USER: mon_user
      MYSQL_PASSWORD: un_autre_mdp
  nineboamp:
    environment:
      DATABASE_URL: "mysql://nineboamp:un_autre_mdp@mariadb:3306/nineboamp"
```

> **Note** : le container tourne sans privilèges, avec l'UID de l'utilisateur
> hôte (`misc/script/up.sh` le transmet dans `LOCAL_UID`) et le GID 101
> (`apache`). `misc/script/entrypoint.sh` vérifie au démarrage que les volumes
> montés appartiennent bien à cet UID, et s'arrête sinon en affichant la commande
> `chown` à lancer.

## 4. Monter les containers

```bash
mkdir -p var uploads public/uploads
./misc/script/up.sh up -d --build
```

> **Pourquoi `up.sh` et pas `docker compose` directement** : `compose.yaml`
> exige `LOCAL_UID`, que le script remplit avec `id -u`. On ne peut pas lire
> `${UID}` dans le compose : bash ne l'exporte pas, donc la valeur retombait sur
> un défaut (1000) et le container tournait avec un autre UID que les volumes —
> `app:cron` échouait alors en « Permission denied » et amoxtli ne pouvait plus
> lire les workspaces de l'onglet « Documents indexés ».

> **Pourquoi `mkdir` avant le premier `up`** : si un répertoire monté
> n'existe pas encore sur l'hôte, Docker le crée en propriétaire
> `root:root` — ce qui casse les permissions. Créés à l'avance, ils
> appartiennent à votre utilisateur et tout fonctionne.

L'application est accessible sur http://localhost:8024

## Permissions

Le container ne prend jamais de privilèges. `misc/script/entrypoint.sh` vérifie
seulement que `var/`, `uploads/` et `public/uploads/` sont inscriptibles par
l'UID du process ; sinon il s'arrête avant de lancer quoi que ce soit, en
affichant la commande à passer sur l'hôte. C'est le seul cas où une action
manuelle est nécessaire :

```bash
sudo chown -R "$(id -u)":101 var uploads public/uploads
```

Ce cas survient quand la stack a été démarrée par un container tournant avec un
autre UID (ancienne configuration, ou `docker compose` lancé sans `up.sh`) : les
fichiers créés alors appartiennent à cet UID, y compris les workspaces amoxtli
en `750` que le nouveau process ne peut plus lire.
