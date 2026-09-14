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

> **Note** : le `user: "${UID:-1000}:${GID:-1000}"` est déjà dans le
> `compose.yaml` de base — le process tourne avec l'UID de l'utilisateur
> hôte, les volumes montés sont donc lisibles/écritures des deux côtés
> sans aucun `chmod`.

## 4. Monter les containers

```bash
mkdir -p var uploads public/uploads
docker compose up -d --build
```

> **Pourquoi `mkdir` avant le premier `up`** : si un répertoire monté
> n'existe pas encore sur l'hôte, Docker le crée en propriétaire
> `root:root` — ce qui casse les permissions. Créés à l'avance, ils
> appartiennent à votre utilisateur et tout fonctionne.

L'application est accessible sur http://localhost:8001

## Permissions — plateau

Le `compose.yaml` de base gère tout : `user:` aligne le process sur l'UID
hôte, et les répertoires `uploads/`, `public/uploads/`, `var/` sont
créés avant le premier `up`. Rien à faire de plus sur la plupart des
postes Linux (premier user = 1000).

Si votre UID/GID diffère, deux options :

```bash
# Option A : variables d'environnement au moment du up
UID=1015 GID=1015 docker compose up -d

# Option B : plan B sur un montage exotique
chmod -R 777 ./uploads ./public/uploads
```

En production, le Dockerfile gère automatiquement les permissions avec
l'utilisateur `apache`.
