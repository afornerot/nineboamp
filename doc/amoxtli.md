# Amoxtli — Indexation et chat IA RAG

[Amoxtli](https://github.com/Bornholm/amoxtli) (CLI `v0.17.x`) est utilisé pour **indexer les pièces jointes** des marchés et permettre le **chat IA** sur chaque marché.

## Vue d'ensemble

```
[upload PDF/DOCX/TXT]                              [requête chat]
        │                                                  │
        ▼                                                  ▼
[boamp/{id}/ files] ──> AmoxtliService::indexMarket() ──> Amoxtli workspace
        │                                                       │
        ▼                                                       ▼
[pdftotext -layout]                                       ┌────────────┐
        │                                                  │  amoxtli   │
        ▼                                                  │  search    │
[uploads/amoxtli/{id}/] ←──────────────  amoxtli sync     └─────┬──────┘
                                                                   │
                                                                   ▼
                                                       [Agent Python :8000]
                                                                   │
                                                                   ├── amoxtli_search (tool)
                                                                   ├── list_documents (tool)
                                                                   │
                                                                   ▼
                                                       [MarketChatController]
                                                                   │
                                                                   ▼
                                                     [MarketChatMessage (BDD)]
```

## Pipeline d'indexation

Déclenchée :

- Automatiquement à chaque upload / suppression de fichier sur le marché
  (listener `AmoxtliIndexListener` → dispatch `IndexMarketMessage` sur le bus
  asynchrone).
- Manuellement via `php bin/console messenger:consume async` (worker).

Étapes dans `AmoxtliService::indexMarket()` :

1. **Vérification** : existence du répertoire `uploads/boamp/{id}/`. Sinon,
   no-op.
2. **Initialisation workspace** : `amoxtli init` dans `uploads/amoxtli/{id}/`
   (un sous-répertoire par marché). Skip si déjà initialisé.
3. **Conversion PDF** : pour chaque `.pdf` non encore converti, `pdftotext
   -layout` produit un `.txt` à côté (l'indexeur texte ignore les PDFs natifs).
4. **Sync** : `amoxtli sync uploads/boamp/{id}/ --base-dir uploads/boamp/{id}/
   --no-wait` (le `--no-wait` évite de bloquer les uploads).

Les fichiers Excel (`.xlsx`) ne sont **pas** indexés par Amoxtli (format non
supporté en v0.17). Seuls `.txt`, `.md`, `.docx`, `.pdf` (via `.txt`)
remontent dans la recherche.

## Chat IA

L'agent Python écoute sur `localhost:8000` et utilise le mechanism de **function calling** pour accéder aux documents indexés via amoxtli.

Endpoint : `POST /user/market/{id}/chat` (`app_market_chat`).

Workflow (`MarketChatController::chat()`) :

1. **Sauvegarde** message utilisateur → `MarketChatMessage`.
2. **Envoi à l'agent** via `AgentService` qui appelle l'agent Python sur `:8000`.
3. **L'agent** :
   - Reçoit les informations du marché (titre, acheteur, montant, deadline, score, produits, description)
   - Peut utiliser les outils `amoxtli_search` et `list_documents` pour rechercher dans les documents
   - Répond via l'API LLM avec function calling
4. **Sauvegarde** réponse → `MarketChatMessage`.

## Liens connexes

- [`crons.md`](crons.md) — le worker Messenger consomme les `IndexMarketMessage`.
- [`ai.md`](ai.md) — agent LLM utilisé par le chat.
- [`boamp.md`](boamp.md) — entités `Market`, `MarketChatMessage`.
