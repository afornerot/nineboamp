# IA / LLM

L'application utilise un **agent IA** qui orchestre les appels LLM via **function calling**. Il est écrit en **Go** avec la bibliothèque [genai](https://github.com/Bornholm/genai), tourne dans le container sur **`127.0.0.1:8000`** (le port que PHP appelle) — voir [Agent Go (port 8000)](#agent-go-port-8000).

> **Note** : l'agent a été porté depuis une implémentation Python "maison" (httpx, sans LlamaIndex), supprimée du dépôt — l'historique git fait foi. Contrat HTTP, prompts et outils sont strictement identiques.

## Configuration

Variables à configurer dans `.env.local` :

| Variable | Description | Défaut |
|----------|-------------|--------|
| `AI_PROVIDER` | Nom du provider (informationnel) | `groq` |
| `AI_MODEL` | Identifiant du modèle | `openai/gpt-oss-120b` |
| `AI_API_KEY` | Clé API | `changeme` |
| `AI_BASE_URL` | URL de base (doit exposer `/chat/completions`) | `https://api.groq.com/openai/v1` |

**Note** : Le fichier `.env.local` peut contenir plusieurs configurations (xolo, groq...) — la dernière déclaration de chaque variable prime.

## Agent IA (Chat conversationnel)

L'agent IA tourne dans le container Docker sur le port interne **8000** et utilise le mechanism de **function calling** d'OpenAI pour accéder à des outils :

### Outils disponibles

| Outil | Description |
|-------|-------------|
| `amoxtli_search` | Recherche dans les documents indexés d'un marché via amoxtli |
| `list_documents` | Liste les documents disponibles pour un marché |

### Architecture

```
[Utilisateur] → [MarketChatController] → [AgentService] → [Agent Go :8000]
                                                            │
                                                            ├── Lit le system prompt depuis src/DataFixtures/data/scoring/chat.context.md
                                                            │
                                                            ├── Utilise les outils amoxtli_search / list_documents
                                                            │
                                                            └── Appelle l'API LLM (OpenAI function calling)
```

### Stockage

**Historique des conversations** : En base de données (`market_chat_message`), récupéré à chaque requête pour reconstruire le contexte.

**Fichiers temporaires** : `/tmp/jobs-go/` (fichiers `.json` des jobs, nettoyés automatiquement après expiration).

### Endpoint

- `POST /user/market/{id}/chat` — Envoie un message et reçoit une réponse de l'agent
- `GET /user/market/{id}/chat/history` — Récupère l'historique des messages
- `DELETE /user/market/{id}/chat/{messageId}` — Supprime un message

## Agent Go (port 8000)

L'agent est écrit en **Go** avec la bibliothèque [`github.com/Bornholm/genai`](https://github.com/Bornholm/genai) et occupe le port interne **8000** — celui que PHP appelle : **aucune modification PHP n'a été nécessaire** lors de la migration.

| | Agent (genai) |
|---|---|
| Code | `agent-go/` |
| Port interne | `127.0.0.1:8000` (appelé par PHP) |
| Fichiers de jobs | `/tmp/jobs-go/` |
| Lancement (Docker) | `/usr/local/bin/nineagent` (binaire statique compilé en multi-stage `agentbuild`) |

Points clés :

- Même contrat HTTP : `GET /health`, `POST /chat|/score|/report → {job_id}`,
  `GET …/result/{id} → {status, result}`, `DELETE /chat/{id} → {deleted}`.
- Mêmes prompts (`src/DataFixtures/data/scoring/*.md`), relus à chaque requête,
  front-matter YAML retiré.
- Mêmes outils : `amoxtli_search`, `list_documents`, `search_products`,
  `get_product_info` (erreurs retournées en `{"error": …}` pour l'auto-correction
  du modèle).
- Scoring : tentative de sortie JSON structurée (`response_format`), repli sur un
  appel texte simple si le provider refuse, parsing tolérant (JSON entouré de
  texte, valeurs manquantes → défauts `score: 0`, `priority: C`).
- Boucle d'outils bornée à **10 itérations**.

### Développement

```bash
cd agent-go
go test ./...                 # tests unitaires (aucune clé API requise)
go vet ./... && gofmt -l .

# Service complet sur :8000 (le port appelé par PHP)
go run ./cmd/nineagent -env-dir .. -jobs-dir /tmp/jobs-go

# Vérification rapide de la chaîne LLM (provider + exécution d'outil)
go run ./cmd/poc -env-dir ..
```

Chargement de l'environnement : `.env.local` prime sur `.env`
(`set-if-absent`, comme en Python), puis les variables `AI_MODEL` / `AI_API_KEY` /
`AI_BASE_URL` sont converties en `GENAI_CHAT_COMPLETION_*` attendues par genai.

Dans Docker, `misc/script/reconfigure.sh` lance `nineagent` et le surveille avec
les autres services ; `misc/docker/Dockerfile` compile le binaire dans
la stage `agentbuild` (`golang:1.25-alpine`).

## AiService (Appels directs)

Dans un controller ou un service :

```php
// Injection par constructeur
public function __construct(private AiService $ai) {}

// Utilisation simple
$response = $this->ai->prompt("Bonjour");

// Utilisation avancée
$response = $this->ai->ask(
    prompt: "Décris ce projet",
    system: "Tu es un expert Symfony",
    temperature: 0.5,
    maxTokens: 2048,
);

// Conversation multi-tours
$messages = [
    ['role' => 'system', 'content' => 'Tu es un assistant'],
    ['role' => 'user', 'content' => 'Bonjour'],
];
$response = $this->ai->askMessages($messages, 0.7, 2048);
```

## Prompts en fichiers .md

Les prompts sont stockés dans `src/DataFixtures/data/scoring/*.md` (plus de BDD).

| Fichier | Rôle |
|---------|------|
| `chat.context.md` | System prompt pour le chat agenté (avec placeholders `{{metadata}}`, `{{description}}`, etc.) |
| `scoring.role.md` | Rôle LLM pour le scoring (contexte entreprise + chapitre "ce que nous ne faisons pas") |
| `scoring.user.md` | Prompt de scoring marché par marché (JSON strict) |

Les prompts supportent un frontmatter YAML minimal :

```yaml
---
purpose: Scoring des marchés
temperature: 0.3
max_tokens: 500
---
```

## Limites et quotas

Le service applique automatiquement :

- **3 tentatives** sur erreur **HTTP 429** (rate limit), avec délai récupéré du
  header `Retry-After` ou du message d'erreur.
- **Timeout 60s** sur le client HTTP Symfony.
- Tronquage du contenu via `array_map()` côté scoring pour respecter la fenêtre
  de tokens du provider.

Si un appel LLM échoue après les retries, `AiService::ask()` retourne `''` et
un log d'erreur est émis (`var/log/dev.log` → `app.ERROR: AiService: ...`).

## Dépannage

| Symptôme | Cause probable | Action |
|---|---|---|
| "Erreur de connexion" dans le chat | Agent non démarré ou timeout | Vérifier que le container est UP et que l'agent écoute sur `:8000` (`docker logs nineboamp`) |
| LLM répond avec les placeholders `{{...}}` non remplacés | Prompts non à jour | L'agent charge les prompts au démarrage ; redémarrer le container |
| Rate limit dépassé | Trop de requêtes simultanées | Patienter ou ajuster `AI_MODEL` / `AI_BASE_URL` |
