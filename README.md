# Nineboamp

Application Symfony 7.4 de **qualification automatique des marchés publics français (BOAMP)** par IA, avec **indexation des pièces jointes** (Amoxtli), **chat IA contextualisé** (RAG) et **export PDF** des dossiers marchés.

Stack : PHP 8.5, MariaDB, Alpine Linux / Apache, Docker, Dompdf, Amoxtli 0.17, agent IA en Go ([genai](https://github.com/Bornholm/genai)).

## Licence

Ce projet est distribué sous licence **[AGPL-3.0](LICENSE)** (GNU Affero General Public License).

## Comment ça marche

1. Chaque **produit** (fiche markdown dans `src/DataFixtures/data/products/*.md`) reçoit des **mots-clés métier** générés par l'IA à sa création.
2. Une fois par jour, `app:boamp:search` interroge l'API BOAMP (OpenDataSoft) avec ces mots-clés et garde les marchés dont la date limite est à plus de 30 jours.
3. L'IA **note chaque marché** (score 0-100, priorité A/B/C) par rapport à nos produits. Les marchés notés apparaissent dans `/user/market`.

> **L'IA est indispensable dès le premier démarrage.** Si elle ne répond pas
> quand les produits sont créés, leurs mots-clés se réduisent à leur nom
> (`aura`, `xolo`…) et la recherche ne trouve quasiment rien. Voir
> [Dépannage](#dépannage).

## Lancer le projet

### Prérequis

- Docker et Docker Compose v2
- Une clé d'accès à un LLM exposant une API compatible OpenAI (`/chat/completions`), par exemple la passerelle Xolo

### 1. Configurer `.env.local`

```bash
git clone <repo> && cd nineboamp
cp .env .env.local
```

Éditer `.env.local` (jamais `.env`) :

| Variable | Rôle |
|----------|------|
| `APP_SECRET` | Sessions et CSRF, **et mot de passe initial du compte admin** (`openssl rand -hex 16`) |
| `APP_ENCRYPT_KEY` | Chiffrement des données (`openssl rand -hex 16`) |
| `MCP_SECRET` | Authentification des clients MCP |
| `APP_ADMIN` / `APP_ADMIN_EMAIL` | Login et email du compte admin créé au démarrage |
| `AI_BASE_URL` / `AI_MODEL` / `AI_API_KEY` | Accès au LLM (voir ci-dessous) |

Exemple avec la passerelle Xolo :

```env
AI_PROVIDER=xolo
AI_BASE_URL=https://xolo.cadoles.com/api/v1
AI_MODEL=cadoles/deepseek-v4.1-flash
AI_API_KEY=<votre clé>
```

- L'API Xolo est sous **`/api/v1`**. `https://xolo.cadoles.com/v1` est l'interface web et redirige vers la page de connexion.
- Pour lister les modèles disponibles :
  `curl -H "Authorization: Bearer <votre clé>" https://xolo.cadoles.com/api/v1/models`
- `AI_PROVIDER` est purement informatif (logs).

Les valeurs `changeme` conviennent pour un poste local. Avant toute mise en production, voir [doc/installation.md](doc/installation.md) (secrets, mot de passe MariaDB via `compose.override.yaml`).

### 2. Démarrer

```bash
mkdir -p var uploads public/uploads    # avant le premier up, sinon Docker les crée en root
docker compose up -d --build
docker compose logs -f nineboamp       # attendre la ligne « START HTTPD »
```

Au démarrage, le conteneur attend que MariaDB soit prête, met à jour le schéma, puis lance `app:init`. Si la base ne contient encore aucun compte, cette commande charge les fixtures : compte admin, crons et produits, avec appel à l'IA pour chaque produit (environ une minute). Aux démarrages suivants, elle ne touche à rien.

`app:init --force` recharge les fixtures sur une base existante : les produits et crons supprimés depuis l'interface sont recréés, et les comptes `APP_ADMIN` retrouvent le rôle admin et l'e-mail de `APP_ADMIN_EMAIL`.

> Ne lancez aucune commande `bin/console` avant « START HTTPD ». Un `app:init`
> lancé en parallèle de celui du démarrage provoque des erreurs
> `Deadlock` / `SAVEPOINT does not exist`.

### 3. Se connecter

http://localhost:8024, avec le login `APP_ADMIN` (par défaut `admin`) et le mot de passe égal à la valeur de `APP_SECRET`.

Le mot de passe est fixé **à la création du compte**. Modifier `APP_SECRET` ensuite ne le change pas.

### 4. Vérifier que tout fonctionne

```bash
# L'IA répond : doit afficher des mots-clés et des secteurs
docker compose exec nineboamp bin/console app:dev:probe-metadata

# L'API BOAMP répond (sans IA) : mots-clés libres
docker compose exec nineboamp bin/console app:boamp:probe logiciel "gestion documentaire"

# Première recherche complète (plusieurs minutes : jusqu'à 30 marchés notés par l'IA)
docker compose exec nineboamp bin/console app:boamp:search
```

Ensuite :

- `/user/product` : chaque produit doit avoir de vrais mots-clés, pas seulement son nom.
- `/user/market` : les marchés notés.
- La recherche se relance ensuite une fois par jour via le planificateur interne (`/admin/cron`).

### Modifier la configuration

Après toute modification de `.env.local`, redémarrer le conteneur :

```bash
docker compose restart nineboamp
```

L'agent IA ne lit sa configuration qu'au démarrage. Par ailleurs, `.env.local` est monté comme fichier unique : si votre éditeur remplace le fichier à l'enregistrement, le conteneur continue de voir l'ancienne version jusqu'au redémarrage.

Les prompts (`src/DataFixtures/data/scoring/*.md`) sont relus à chaque appel et ne nécessitent pas de redémarrage.

## Dépannage

Les erreurs IA sont dans les logs :

```bash
docker compose exec nineboamp grep AiService var/log/dev.log | tail
```

| Symptôme | Cause probable | Solution |
|----------|----------------|----------|
| Login refusé | Compte créé avec un autre `APP_SECRET`, ou pas créé | Vérifier : `docker compose exec mariadb mariadb -unineboamp -pchangeme nineboamp -e 'SELECT username FROM user'`. Pour recréer le compte avec l'`APP_SECRET` actuel : supprimer la ligne puis `docker compose exec nineboamp bin/console app:init --force` |
| `Aucun candidat retenu`, mots-clés = noms des produits | L'IA ne répondait pas à la création des produits | Corriger la config IA, puis dans `/user/product` : modifier le produit, **vider** les champs « Mots-clés » et « Secteurs cibles », enregistrer. L'IA les régénère |
| Recherche qui ramène beaucoup de marchés hors sujet | Mots-clés trop génériques (« gestion », « projet »…) | Les retirer à la main dans `/user/product`. L'IA ne remplace jamais des mots-clés saisis |
| Log : redirection HTTP 307 vers `/usage` ou une page de login | Mauvaise `AI_BASE_URL` | Pour Xolo : `https://xolo.cadoles.com/api/v1` |
| Log : `Please pass a valid API key` / HTTP 401 | Clé invalide ou d'un autre fournisseur | Vérifier `AI_API_KEY` et `AI_BASE_URL` |
| Log : `quota` / HTTP 429 | Quota du fournisseur épuisé (ex. Gemini gratuit : 20 requêtes/jour/modèle) | Changer de modèle ou de fournisseur. L'app réessaie 3 fois puis abandonne |
| Log : `réponse IA vide ou inattendue` avec `reasoning_content` | Modèle « à raisonnement » à court de tokens | Augmenter `max_tokens` dans l'en-tête du prompt concerné (`src/DataFixtures/data/scoring/*.md`) |
| `Deadlock` / `SAVEPOINT does not exist` sur `app:init` | Lancé pendant l'init du démarrage | Attendre « START HTTPD » et relancer |

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
- Produits : http://localhost:8024/user/product
- Rapports BOAMP : http://localhost:8024/user/report
- Swagger : http://localhost:8024/v1/api/doc
- Admin crons : http://localhost:8024/admin/cron
