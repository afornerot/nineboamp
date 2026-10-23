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
  (listener `AmoxtliIndexListener`, domaine `boamp` et id numérique
  uniquement → dispatch `IndexMarketMessage` sur le transport `amoxtli`,
  traité par son propre worker `messenger:consume amoxtli` : une longue
  indexation ne retarde pas les emails du transport `async`) ;
- manuellement par `app:amoxtli:reindex` (voir plus bas).

Étapes de `AmoxtliService::indexMarket()`, sous un verrou
`amoxtli-market-{id}` (le worker et la commande peuvent viser le même marché) :

1. **Vérification** : sans dossier `uploads/boamp/{id}/`, le marché n'a pas
   de pièces. S'il a un index, celui-ci est vidé ; sinon rien n'est créé.
2. **Workspace** : `amoxtli init` dans `uploads/amoxtli/{id}/` s'il n'existe
   pas encore. La page du marché le crée aussi, sauf pendant une indexation
   du marché (verrou déjà pris).
3. **Corpus** (`buildCorpus()`) : parcours récursif des pièces, en ignorant les
   fichiers cachés et les dossiers préfixés `_` (`_thumbs`), comme la liste des
   fichiers du marché. Chaque pièce donne une entrée dans
   `uploads/amoxtli/{id}/corpus/`, au même chemin relatif :
   - format lu par amoxtli → copie (extension mise en minuscules : amoxtli
     refuse `.TXT`, `.DOCX`…) ;
   - format à convertir → `<nom complet>.txt`, par exemple `CCTP.pdf.txt`.
     L'extension d'origine reste dans le nom : `devis.pdf` et `devis.xlsx` ne
     se marchent pas dessus.

   Le rapport garde la date et la taille de chaque pièce : une entrée n'est
   refaite que si sa pièce a changé (remplacement sous le même nom compris,
   ou pièce indexée pendant que l'upload l'écrivait encore). Une pièce refusée
   pour son contenu (PDF image, aucun texte, fichier refusé par son
   convertisseur ou celui d'amoxtli) n'est retentée que si elle change, ou à
   la réindexation. Les autres échecs ne disent rien de la pièce (délai
   dépassé, outil absent, processus tué, erreur d'embeddings) : elle est
   retentée au passage suivant. Les entrées dont la pièce a disparu sont
   supprimées.
4. **Indexation** : amoxtli n'admet qu'un processus à la fois par workspace
   et refuse aussitôt les autres (« workspace is already in use »). Les
   entrées nouvelles ou modifiées sont donc indexées par lots (`amoxtli add`,
   au plus 1 Mo ou 50 fichiers, quelques secondes chacun), avec une pause de
   0,5 s entre deux lots pour laisser passer les recherches. Puis
   `amoxtli sync corpus/ --base-dir corpus/` retire de l'index les entrées
   sorties du corpus. Chaque lot prolonge le verrou du marché.

   Ni `add` ni `sync` ne passent `--no-wait` : amoxtli rendrait la main en
   laissant l'essentiel des fichiers en attente, sans le résultat de chacun.
   amoxtli abandonne un fichier au bout de 5 min. Un fichier qu'il
   ne sait pas lire (ex. `.docx` corrompu) est signalé sans faire échouer le
   marché, et retiré du corpus puis de l'index : amoxtli y garderait sinon sa
   version précédente.

   Les recherches et listes de documents (chat PHP, onglet du marché, outils
   de l'agent Go) relancent la commande tant que le workspace est pris, au
   plus 20 s ; l'indexation attend de même une recherche en cours, au plus
   60 s.
5. **Rapport** : `uploads/amoxtli/{id}/corpus-report.json` (pièces converties,
   copiées, supprimées, non prises en charge, en échec, et version de chaque
   pièce). L'onglet « Documents indexés » de la page marché et les sources du
   chat l'utilisent pour afficher le nom d'origine des pièces ; l'onglet y
   prend aussi la liste des pièces non indexées avec leur raison.

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
# 1. Reconstruire et redémarrer le conteneur : l'agent Go est dans l'image, le
#    worker du transport amoxtli est lancé au démarrage (misc/script/reconfigure.sh),
#    et l'ancien worker écrirait encore des .txt à chaque upload
docker compose up -d --build nineboamp
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
