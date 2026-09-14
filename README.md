# Nineboamp

Base Symfony 7.4 pour applications internes.
Stack : PHP 8.4, MariaDB, Apache, Docker.

## Licence

Ce projet est distribué sous licence **[AGPL-3.0](LICENSE)** (GNU Affero General Public License).

## Démarrage rapide

```bash
# 1. Cloner
git clone <repo> && cd nineboamp

# 2. Configurer les secrets (voir doc/installation.md)
cp .env .env.local
# Éditer .env.local : APP_SECRET, APP_ENCRYPT_KEY, MCP_SECRET, DATABASE_URL
# Les valeurs par défaut (changeme) servent à l'exécution locale et
# DOIVENT être modifiées avant toute mise en production :
# voir la section « Secrets à modifier avant mise en production »
# de doc/installation.md.
# Créer compose.override.yaml : surcharger uniquement les passwords BDD

# 3. Démarrer (créer les répertoires partagés avant le premier up)
mkdir -p var uploads public/uploads
docker compose up -d --build
```

Application accessible sur http://localhost:8001 — compte admin créé
automatiquement (login = `APP_ADMIN`, mot de passe = `APP_SECRET`).

## Documentation

La documentation détaillée se trouve dans [doc/](doc/index.md) :

| Sujet | Documentation |
|-------|---------------|
| Installation détaillée (secrets, BDD, permissions) | [doc/installation.md](doc/installation.md) |
| Authentification (rôles, CAS/OIDC/SQL, MCP) | [doc/authentification.md](doc/authentification.md) |
| Variables d'environnement, mailer | [doc/configuration.md](doc/configuration.md) |
| Fixtures (données initiales, compte admin) | [doc/fixtures.md](doc/fixtures.md) |
| Crons (planificateur de tâches) | [doc/crons.md](doc/crons.md) |
| IA / LLM (service AiService) | [doc/ai.md](doc/ai.md) |
| API (Swagger, MCP) | [doc/api.md](doc/api.md) |

## Liens utiles

- Swagger : http://localhost:8001/v1/api/doc
- Admin crons : http://localhost:8001/admin/cron
- Endpoint MCP : http://localhost:8001/mcp
