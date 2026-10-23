# Amoxtli — Indexation et chat IA RAG

[Amoxtli](https://github.com/Bornholm/amoxtli) (CLI `v0.17.x`) est utilisé pour **indexer les pièces jointes** des marchés et permettre le **chat IA** sur chaque marché.

## Vue d'ensemble

```
[upload / suppression]                             [requête chat]
        │                                                  │
        ▼                                                  ▼
[uploads/boamp/{id}/]  ──>  AmoxtliService::indexMarket()     [Agent Go :8000]
  pièces déposées,                │                              │
  jamais modifiées                ▼                              ├── amoxtli_search (tool)
                       [uploads/amoxtli/{id}/corpus/]            ├── list_documents (tool)
                         copies et conversions texte             │
                                  │                              ▼
                                  ▼                     [MarketChatController]
                       amoxtli sync corpus/  ──>  uploads/amoxtli/{id}/.amoxtli/
```

## Pipeline d'indexation

Déclenchée :

- automatiquement à chaque upload ou suppression d'une pièce d'un marché
  (listener `AmoxtliIndexListener`, domaine `boamp` uniquement → dispatch
  `IndexMarketMessage` sur le bus asynchrone, traité par le worker
  `messenger:consume async`) ;
- manuellement par `app:amoxtli:reindex` (voir plus bas).

Étapes de `AmoxtliService::indexMarket()`, sous un verrou
`amoxtli-market-{id}` (le worker et la commande peuvent viser le même marché) :

1. **Vérification** : sans dossier `uploads/boamp/{id}/`, le marché n'a pas
   de pièces. S'il a un index, celui-ci est vidé ; sinon rien n'est créé.
2. **Workspace** : `amoxtli init` dans `uploads/amoxtli/{id}/` s'il n'existe
   pas encore.
3. **Corpus** (`buildCorpus()`) : parcours récursif des pièces, en ignorant les
   fichiers cachés et les dossiers préfixés `_` (`_thumbs`), comme la liste des
   fichiers du marché. Chaque pièce donne une entrée dans
   `uploads/amoxtli/{id}/corpus/`, au même chemin relatif :
   - format lu par amoxtli → copie (extension mise en minuscules : amoxtli
     refuse `.TXT`, `.DOCX`…) ;
   - format à convertir → `<nom complet>.txt`, par exemple `CCTP.pdf.txt`.
     L'extension d'origine reste dans le nom : `devis.pdf` et `devis.xlsx` ne
     se marchent pas dessus.

   Une entrée porte la date de modification de sa pièce : elle n'est refaite
   que si la pièce a changé (remplacement sous le même nom compris). Les
   entrées dont la pièce a disparu sont supprimées.
4. **Sync** : `amoxtli sync corpus/ --base-dir corpus/`, sans `--no-wait` pour
   récupérer le résultat de chaque fichier. Un fichier qu'amoxtli ne sait pas
   lire (ex. `.docx` corrompu) est signalé sans faire échouer le marché.
5. **Rapport** : `uploads/amoxtli/{id}/corpus-report.json` (pièces converties,
   copiées, supprimées, non prises en charge, en échec). L'onglet « Documents
   indexés » de la page marché l'utilise pour afficher le nom d'origine des
   pièces et la liste des pièces non indexées avec leur raison.

Un passage sans changement prend environ 1 s.

## Formats pris en charge

| Format | Traitement |
|--------|------------|
| `.txt`, `.md`, `.docx`, `.odt`, `.rtf`, `.html`, `.rst`, `.epub`, `.tex` | lus par amoxtli (pandoc), copiés tels quels |
| `.pdf` | `pdftotext -layout`. Un PDF sans texte (ou de plus de 50 Ko avec moins de 100 caractères) est signalé « PDF image, OCR requis » |
| `.doc` | `catdoc`, ou `antiword` pour les `.doc` que catdoc refuse (ceux écrits par LibreOffice) |
| `.xlsx`, `.xls` | toutes les feuilles en CSV, chacune précédée de `## Feuille : <nom>` (openpyxl, xlrd). Les cellules donnent la valeur affichée : résultat des formules (celui enregistré par le tableur), nombres sans `.0`, dates en `AAAA-MM-JJ` |
| `.csv` | copié en `.csv.txt` (converti en UTF-8 s'il est en Windows-1252) |
| autres (`.pptx`, `.ods`, `.zip`, images…) | non indexés, signalés « format non pris en charge » |

Pour ajouter un format : une entrée dans `AmoxtliService::converters()`
(extension → fonction qui renvoie le texte), et l'outil dans
`misc/docker/Dockerfile`.

## Réindexation

```bash
docker compose exec nineboamp bin/console app:amoxtli:reindex <id>
docker compose exec nineboamp bin/console app:amoxtli:reindex --all
```

Supprime l'index et le corpus du marché, les reconstruit et affiche le rapport.
`--all` parcourt `uploads/boamp/*` et `uploads/amoxtli/*` : les marchés qui
ont des pièces sans workspace sont inclus, et l'index d'un marché dont le
dossier de pièces a disparu est supprimé.

### Migration depuis l'ancienne indexation

L'ancienne version écrivait les conversions (`X.txt` pour `X.pdf` ou
`X.xlsx`, à la racine du dossier du marché) dans le dossier des pièces. Ces
fichiers apparaissent dans la liste des fichiers du marché, et la nouvelle
indexation les indexe comme des pièces déposées, en double de leur pièce. À
faire juste après le déploiement, dans cet ordre :

```bash
# 1. Faire tourner le worker Messenger avec le nouveau code : l'ancien écrirait
#    encore des .txt à chaque upload (la boucle de misc/script/reconfigure.sh le relance)
docker compose exec nineboamp bin/console messenger:stop-workers
# 2. Relire la liste : un .txt déposé volontairement avec le même nom de base serait supprimé aussi
docker compose exec nineboamp bin/console app:amoxtli:reindex --all --purge-legacy-txt --dry-run
# 3. Supprimer ces .txt et réindexer : les doublons indexés entre-temps disparaissent
docker compose exec nineboamp bin/console app:amoxtli:reindex --all --purge-legacy-txt
```

## Chat IA

L'agent (Go, port 8000 — voir [IA / LLM](ai.md)) écoute sur `localhost:8000` et utilise le mechanism de **function calling** pour accéder aux documents indexés via amoxtli.

Endpoint : `POST /user/market/{id}/chat` (`app_market_chat`).

Workflow (`MarketChatController::chat()`) :

1. **Sauvegarde** message utilisateur → `MarketChatMessage`.
2. **Envoi à l'agent** via `AgentService` qui appelle l'agent sur `:8000`.
3. **L'agent** :
   - Reçoit les informations du marché (titre, acheteur, montant, deadline, score, produits, description)
   - Peut utiliser les outils `amoxtli_search` et `list_documents` pour rechercher dans les documents
   - Répond via l'API LLM avec function calling
4. **Sauvegarde** réponse → `MarketChatMessage`.

## Liens connexes

- [`crons.md`](crons.md) — le worker Messenger consomme les `IndexMarketMessage`.
- [`ai.md`](ai.md) — agent LLM utilisé par le chat.
- [`boamp.md`](boamp.md) — entités `Market`, `MarketChatMessage`.
