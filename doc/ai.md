# IA / LLM

L'application utilise un **agent Python** qui orchestre les appels LLM via **function calling** OpenAI.

> **Note** : L'agent est une implémentation "maison" utilisant httpx pour les appels directs à l'API OpenAI. Ce n'est **pas** LlamaIndex.

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
[Utilisateur] → [MarketChatController] → [AgentService] → [Agent Python :8000]
                                                            │
                                                            ├── Lit le system prompt depuis src/DataFixtures/data/scoring/chat.context.md
                                                            │
                                                            ├── Utilise les outils amoxtli_search / list_documents
                                                            │
                                                            └── Appelle l'API LLM (OpenAI function calling)
```

### Stockage

**Historique des conversations** : En base de données (`market_chat_message`), récupéré à chaque requête pour reconstruire le contexte.

**Fichiers temporaires** : `/tmp/jobs/` (fichiers `.json` et `.log` des jobs, nettoyés automatiquement après expiration).

### Endpoint

- `POST /user/market/{id}/chat` — Envoie un message et reçoit une réponse de l'agent
- `GET /user/market/{id}/chat/history` — Récupère l'historique des messages
- `DELETE /user/market/{id}/chat/{messageId}` — Supprime un message

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
| "Erreur de connexion" dans le chat | Agent Python non démarré ou timeout | Vérifier que le container est UP et que l'agent écoute sur `:8000` |
| LLM répond avec les placeholders `{{...}}` non remplacés | Prompts non à jour | L'agent charge les prompts au démarrage ; redémarrer le container |
| Rate limit dépassé | Trop de requêtes simultanées | Patienter ou ajuster `AI_MODEL` / `AI_BASE_URL` |
