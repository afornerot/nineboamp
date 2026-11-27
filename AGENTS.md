# AGENTS.md

Guide pour les agents de code qui travaillent sur Nineboamp. Le [README](README.md) et [doc/](doc/index.md) restent la référence fonctionnelle ; ce fichier ne garde que ce qu'il faut savoir avant de modifier le code.

## Le projet

Application Symfony 7.4 (PHP 8.5, MariaDB) qui détecte les marchés publics du BOAMP, les note par IA au regard du catalogue produits de Cadoles, indexe leurs pièces jointes (Amoxtli) et propose un chat et un rapport de positionnement par marché.

Deux processus tournent dans le même conteneur `nineboamp` :

| Processus | Code | Rôle |
|---|---|---|
| Symfony / Apache (port 80, exposé en 8024) | `src/`, `templates/`, `config/` | Interface, base de données, commandes, appels LLM simples |
| Agent Go `nineagent` (port 8000, interne) | `agent-go/` | Jobs asynchrones avec outils : chat, scoring, rapport |

PHP appelle l'agent par `AgentJobClient` (`http://127.0.0.1:8000`). L'agent rappelle PHP pour ses outils (`/api/products/search`, `/api/product/{id}`).

## Repères dans le code

| Sujet | Où |
|---|---|
| Entités | `src/Entity/` (`Product`, `Market`, `MarketProduct`, `MarketReport`, `MarketChatMessage`, `Cron`, `User`, `Groupe`) |
| Catalogue produits | `Product` (`name`, `description`, `keywords`, `sectors`, `fiche` en Markdown), `ProductController`, `ProductType`, `templates/product/` |
| Compétences de l'entreprise | `Competence`, `CompetenceController` (`/admin/competence`), `CompanyContextBuilder`, `CompetenceSeeder` |
| Fiches produits initiales | `src/DataFixtures/data/products/*.md` |
| Prompts LLM | `src/DataFixtures/data/scoring/*.md` (en-tête YAML : `temperature`, `max_tokens`…) |
| Chargement des prompts | PHP : `PromptLoader` ; Go : `agent-go/internal/prompts/prompts.go` |
| Appel LLM direct (synchrone) | `AiService::ask()` / `askMessages()` |
| Extraction mots-clés / secteurs / description | `ProductMetadataExtractor` |
| Détection et scoring BOAMP | `BoampFinderService`, `ScoringAgentService`, `agent-go/internal/flow/score.go` |
| Rapport de positionnement | `MarketReportService`, `agent-go/internal/flow/report.go` |
| Chat | `MarketChatController`, `AgentService`, `agent-go/internal/flow/chat.go` |
| Consultation des prompts (lecture seule) | `ScoringPromptController`, `/admin/scoring-prompt` |
| Traits de contrôleur | `src/Controller/Trait/` (`LayoutRenderTrait`, `CrudDeleteTrait`) |

Routes : `/user/...` pour les utilisateurs connectés, `/admin/...` pour les administrateurs.

## Comment les prompts sont assemblés

Les prompts sont des fichiers relus à chaque appel, pas des données en base.

| Fichier | Lu par | Placeholders |
|---|---|---|
| `scoring.role.md` | agent Go (système du scoring) | `{{competences}}` |
| `scoring.user.md` | agent Go | `{{idweb}}`, `{{title}}`, `{{buyer}}`, `{{description}}`, `{{amount}}`, `{{deadline}}`, `{{catalogue}}` |
| `report.system.md` | agent Go (système du rapport) | `{{competences}}` |
| `report.user.md` | agent Go | `{{metadata}}`, `{{description}}`, `{{products}}`, `{{catalogue}}`, `{{chatHistory}}` |
| `chat.context.md` | PHP (`MarketChatController`) et agent Go | `{{competences}}`, `{{metadata}}`, `{{description}}`, `{{products}}`, `{{documents}}` |
| `competence.suggest.system.md`, `competence.suggest.user.md` | PHP (`CompetenceSuggester`) | `{{competences}}`, `{{catalogue}}`, `{{max}}` |
| `extract.system.md`, `extract.user.md` | PHP (`ProductMetadataExtractor`) | `{{tasks}}`, `{{schema}}`, `{{fiche}}` |

À savoir :

- Le contexte entreprise (présentation, savoir-faire, offre, ce que nous ne faisons pas) vit en base (`Competence`) et non dans les prompts. `CompanyContextBuilder` le rend en Markdown ; PHP l'envoie à l'agent dans le champ `company_context` de chaque requête, et l'agent le substitue à `{{competences}}`.
- Quand la table `competence` est vide, `app:init` recrée les valeurs par défaut de `CompetenceSeeder::DEFAULTS` à chaque démarrage.
- Sur le chemin de l'agent Go, `chat.context.md` n'est substitué que pour `{{competences}}` : ses autres placeholders (`{{metadata}}`…) partent tels quels, les informations du marché étant ajoutées au message utilisateur par PHP.
- Les placeholders sont remplacés par simple substitution de chaîne. Un placeholder inconnu du code reste tel quel dans le prompt envoyé au LLM.
- `ProductMetadataExtractor` ne lit que les 4 000 premiers caractères de la fiche ; le catalogue du rapport tronque la description à 150 caractères.
- L'IA ne remplace jamais un champ produit déjà rempli (mots-clés, secteurs, description) : il faut le vider pour qu'elle le régénère.

## Lancer et vérifier

```bash
docker compose up -d --build
docker compose logs -f nineboamp      # attendre « START HTTPD »
```

- Ne lancer aucune commande `bin/console` avant « START HTTPD » (interblocage avec `app:init`).
- Le schéma est mis à jour au démarrage à partir des entités ; `migrations/` est vide. Une nouvelle entité ou colonne n'a donc pas besoin de migration, mais demande un redémarrage du conteneur.
- `src/`, `templates/`, `config/` et `misc/` sont montés dans le conteneur : une modification PHP ou Twig est active tout de suite.
- `AgentJobClient` refuse de démarrer un job si `GET /health` de l'agent n'annonce pas la capacité `company_context` : après un `git pull` qui touche l'agent, sans reconstruction, le scoring et le rapport échouent avec un message explicite.
- **`agent-go/` n'est pas monté** : l'agent tourne depuis le binaire de l'image. Toute modification Go demande `docker compose up -d --build nineboamp`. Sans cela, le nouveau PHP dialogue avec l'ancien agent.
- Après modification de `.env.local` : `docker compose restart nineboamp`.

### Tests et qualité

Il n'y a pas de `vendor/` utilisable sur l'hôte, et `tests/` n'est pas monté dans le conteneur. Pour lancer un test PHP modifié :

```bash
docker exec nineboamp mkdir -p /tmp/t
docker cp tests/Service/XxxTest.php nineboamp:/tmp/t/
docker exec -w /app nineboamp php vendor/bin/phpunit /tmp/t/XxxTest.php
docker exec nineboamp rm -rf /tmp/t
```

| Outil | Commande (dans le conteneur, depuis `/app`) |
|---|---|
| PHPUnit | `php vendor/bin/phpunit` |
| PHPStan (niveau 8) | `php vendor/bin/phpstan analyse` |
| PHP-CS-Fixer (règles `@Symfony`) | `php vendor/bin/php-cs-fixer fix` |
| Tests Go | `go test ./...` depuis `agent-go/` (Go 1.25) |

Vérifier l'IA de bout en bout : `docker compose exec nineboamp bin/console app:dev:probe-metadata`.

## Conventions

- Code, commentaires, libellés et messages de commit en français.
- Commits au format `type(portée): description` (`feat(rapport): …`, `fix(doctrine): …`).
- Une branche par sujet, `feat/…` ou `fix/…`, sans mélanger les sujets ; les PR visent `main`.
- Fixtures idempotentes : elles sont rechargées avec `--append` et ne doivent ni dupliquer ni écraser l'existant (voir [doc/fixtures.md](doc/fixtures.md)).
- Tout fichier `.md` posé dans `src/DataFixtures/data/products/` devient un produit au chargement des fixtures : ne pas y mettre de gabarit ni de note.
- Tout fichier `.md` posé dans `src/DataFixtures/data/scoring/` apparaît dans `/admin/scoring-prompt`.
- Un service qui appelle le LLM se teste avec `AiService` simulé (modèle : `tests/Service/ProductMetadataExtractorTest.php`).
- Mettre à jour [doc/ai.md](doc/ai.md) quand un prompt ou un placeholder change.

## À ne pas toucher

- `.env.local` contient des secrets : ne pas le lire dans une sortie, ne pas le versionner.
- `volume/` (données MariaDB) et `uploads/` (pièces des marchés, espaces Amoxtli) sont des données d'exécution appartenant à l'UID 100 du conteneur.
