# Nineboamp

Application Symfony 7.4 de **qualification automatique des marchés publics français (BOAMP)** par IA, avec **indexation des pièces jointes** (Amoxtli), **chat IA contextualisé** (RAG) et **export PDF** des dossiers marchés.

Stack : PHP 8.5, MariaDB, Alpine Linux / Apache, Docker, Dompdf, Amoxtli 0.17.

## Licence

Ce projet est distribué sous licence **[AGPL-3.0](LICENSE)** (GNU Affero General Public License).

## Démarrage rapide

```bash
# 1. Cloner
git clone <repo> && cd nineboamp

# 2. Configurer les secrets (voir doc/installation.md)
cp .env .env.local
# Éditer .env.local : APP_SECRET, APP_ENCRYPT_KEY, MCP_SECRET, DATABASE_URL, AI_*
# Les valeurs par défaut (changeme) servent à l'exécution locale et
# DOIVENT être modifiées avant toute mise en production.

# 3. Démarrer (créer les répertoires partagés avant le premier up)
mkdir -p var uploads public/uploads
docker compose up -d --build
```

Application accessible sur http://localhost:8024 — compte admin créé
automatiquement (login = `APP_ADMIN`, mot de passe = `APP_SECRET`).

## Fonctionnalités principales

| Fonctionnalité | Description | Doc |
|-------|-------------|-----|
| **Détection BOAMP** | Cron quotidien qui scrape BOAMP (OpenDataSoft) et détecte les marchés correspondants à vos produits | [doc/boamp.md](doc/boamp.md) |
| **Scoring IA** | Évaluation automatique 0-100 + priorité A/B/C pour chaque marché, exécutée par un LLM | [doc/ai.md](doc/ai.md) |
| **Prompts en fichiers .md** | Prompts LLM stockés dans `src/DataFixtures/data/scoring/*.md` (éditables sans redéploiement) | [doc/ai.md](doc/ai.md) |
| **Requalification manuelle** | Bouton "Requalifier" sur la page marché pour re-scoring individuel sans recrawler | [doc/boamp.md](doc/boamp.md#requalification-individuelle) |
| **Indexation Amoxtli** | PDF/DOCX/TXT des pièces jointes indexés via Amoxtli pour RAG | [doc/amoxtli.md](doc/amoxtli.md) |
| **Chat IA agenté** | Assistant conversationnel utilisant un agent LLM avec function calling (accès aux documents + base de données) | [doc/ai.md](doc/ai.md) |
| **Export PDF** | Génération PDF propre du dossier marché (informations + produits + chat) | — |
| **Filtres cumulés** | Filtres priorité + statut sur la liste, sauvegardés en localStorage | — |
| **Gestion fichiers** | Upload / suppression de pièces jointes par marché (bundle BnineFiles) | — |

## Documentation

La documentation détaillée se trouve dans [doc/](doc/index.md) :

| Sujet | Documentation |
|-------|---------------|
| Installation détaillée (secrets, BDD, permissions) | [doc/installation.md](doc/installation.md) |
| Authentification (rôles, CAS/OIDC/SQL, MCP) | [doc/authentification.md](doc/authentification.md) |
| Variables d'environnement, mailer | [doc/configuration.md](doc/configuration.md) |
| Fixtures (données initiales, compte admin) | [doc/fixtures.md](doc/fixtures.md) |
| Crons (planificateur de tâches) | [doc/crons.md](doc/crons.md) |
| BOAMP Finder (détection, scoring) | [doc/boamp.md](doc/boamp.md) |
| Amoxtli (indexation, RAG, chat) | [doc/amoxtli.md](doc/amoxtli.md) |
| IA / LLM (service AiService) | [doc/ai.md](doc/ai.md) |
| API (Swagger, MCP) | [doc/api.md](doc/api.md) |

## Liens utiles

- Interface marchés : http://localhost:8024/user/market
- Rapports BOAMP : http://localhost:8024/user/report
- Swagger : http://localhost:8024/v1/api/doc
- Admin crons : http://localhost:8024/admin/cron
