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

> **Note** : le container tourne sans privilèges, avec l'utilisateur
> `apache` de l'image (UID 100, GID 101). `misc/script/entrypoint.sh` vérifie
> au démarrage que les volumes montés lui appartiennent, et s'arrête sinon en
> affichant la commande `chown` à lancer.

## 4. Monter les containers

```bash
mkdir -p var uploads public/uploads
sudo chown -R 100:101 var uploads public/uploads
docker compose up -d --build
```

> **Pourquoi le `chown`** : le container tourne en UID 100 (`apache`), comme
> l'image le déclare. Les répertoires montés depuis l'hôte doivent lui
> appartenir, sinon chaque écriture échoue en « Permission denied » : `app:cron`
> sort en code 1 sur `var/log/cron.log` et amoxtli ne peut plus lire les
> workspaces de l'onglet « Documents indexés ». La commande est identique sur
> toutes les machines, l'UID du container ne dépendant pas de l'hôte.

> **Pourquoi `mkdir` avant le premier `up`** : si un répertoire monté
> n'existe pas encore sur l'hôte, Docker le crée en propriétaire
> `root:root` — ce qui casse les permissions. Créés à l'avance, le `chown`
> ci-dessus les aligne sur le container.

> **L'ordre des trois commandes est important** : `mkdir` crée les répertoires
> avec votre UID, `chown` les transfère à `apache`, `up` démarre le container.
> Un `up` lancé avant le `chown` s'arrête immédiatement (voir « Permissions »).

L'application est accessible sur http://localhost:8024

## Permissions

Le container ne prend jamais de privilèges. `misc/script/entrypoint.sh` vérifie
seulement que `var/`, `var/log`, `uploads/` et `public/uploads/` sont
inscriptibles par l'UID du process ; sinon il s'arrête avant de lancer quoi que
ce soit, en affichant la commande à passer sur l'hôte. C'est le seul cas où
une action manuelle est nécessaire :

```bash
sudo chown -R 100:101 var uploads public/uploads
```

Le `-R` est indispensable : les workspaces amoxtli (`uploads/amoxtli/<id>/`) sont
en `750` et leurs fichiers appartiennent au compte qui les a créés. Sans le
récursif, l'onglet « Documents indexés » reste en « permission denied ».

### Mise à jour d'une installation existante

Les versions antérieures à `35077cd` démarraient le container avec l'UID de
l'utilisateur hôte (`LOCAL_UID`) au lieu de `apache`. Les fichiers créés alors
appartiennent à cet UID hôte : après un `git pull`, il faut donc passer le
`chown` ci-dessus **avant** de relancer le container, sinon il refuse de
démarrer :

```bash
git pull
sudo chown -R 100:101 var uploads public/uploads
docker compose up -d --build
```

Le `chown` est sans effet sur une installation fraîche : il est idempotent.
