# Amoxtli — Indexation et chat IA RAG

[Amoxtli](https://github.com/...) (CLI `v0.17.x`) est utilisé pour **indexer les pièces jointes** des marchés et permettre le **chat IA RAG** sur chaque marché.

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
                                                      [MarketChatController]
                                                                  │
                                                                  ▼
                                                     [AiService (LLM)]
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

## Chat IA RAG

Endpoint : `POST /user/market/{id}/chat` (`app_market_chat`).

Workflow (`MarketChatController::chat()`) :

1. **Sauvegarde** message utilisateur → `MarketChatMessage`.
2. **Recherche contexte** via `amoxtli search <query> --json -n 3` dans le
   workspace du marché. Top-3 résultats, tronqués à 500 caractères par extrait.
3. **Construction system prompt** :
   - Métadonnées marché (titre, acheteur, montant, deadline, score, priorité,
     statut, URL)
   - Description complète (tronquée à 800 caractères)
   - Top-3 produits scorés
   - Extraits documents (resultats de la recherche amoxtli)
   - Remplaçé dans `chat.context.md` via placeholders
     `{{metadata}}`, `{{description}}`, `{{products}}`, `{{documents}}`
4. **Appel LLM** (`AiService::askMessages()`) avec historique (5 derniers
   messages, tronqués à 1000 caractères).
5. **Sauvegarde** réponse + sources → `MarketChatMessage`.

### Limite de tokens (Groq tier gratuit)

Le tier gratuit de Groq impose **8000 TPM** (tokens par minute). Pour éviter
les dépassements, le controller tronque systématiquement :

| Élément | Plafond |
|---------|---------|
| Description marché | 800 caractères |
| Historique | 5 derniers messages, 1000 caractères chacun |
| Extraits documents | 3 résultats × 500 caractères |
| Pertinence produits | 200 caractères par produit |

Autres endpoints du chat :

- `GET /user/market/{id}/chat/history` — récupère les 50 derniers messages
  (rendu initial de la conversation côté client).
- `DELETE /user/market/{id}/chat/{messageId}` — supprime un message (avec
  confirmation JS côté UI).

## Onglet "Documents indexés"

Sur la page d'un marché, l'onglet `Documents indexés` (icône `fas
fa-database`) interroge en AJAX `GET /user/market/{id}/indexed-docs`
(`app_market_indexed_docs`) qui retourne :

```json
{
  "available": true,
  "initialized": true,
  "documents": [
    {
      "filename": "CCTP_LOT 1.txt",
      "extension": "txt",
      "size": 28476,
      "indexed_at": "2026-09-16T14:01:12.744166552Z",
      "source": "file:///CCTP_LOT%201.txt",
      "etag": "6aaaa127-6f3c"
    }
  ]
}
```

Si le workspace n'est pas initialisé ou vide, l'UI affiche un message
explicatif (pas de fichier uploadé → pas d'indexation).

## Dépannage

| Symptôme | Cause probable | Action |
|---|---|---|
| `amoxtli: command not found` | Binaire absent du container | Vérifier `Dockerfile` (`/usr/local/bin/amoxtli` doit être présent) |
| PDFs non indexés | `pdftotext` absent ou .pdf natif sans conversion .txt | `which pdftotext` dans le container ; Amoxtli doit voir le .txt généré |
| `.xlsx` ignorés | Format non supporté par Amoxtli v0.17 | Convertir en PDF ou extraire le texte en dehors |
| Erreur `workspace is already in use` | Process amoxtli fantôme (lock orphelin) | Supprimer tout fichier `.lock` dans `.amoxtli/` (rare) |
| Chat renvoie "Désolé, je n'ai pas pu traiter votre question" | Quota LLM dépassé (TPM) | Réduire la taille du prompt (tronquage déjà appliqué). Vérifier `var/log/dev.log` → `AiService: erreur API LLM` |

## Liens connexes

- [`crons.md`](crons.md) — le worker Messenger consomme les `IndexMarketMessage`.
- [`ai.md`](ai.md) — provider LLM utilisé par le chat.
- [`boamp.md`](boamp.md) — entités `Market`, `MarketChatMessage`.
