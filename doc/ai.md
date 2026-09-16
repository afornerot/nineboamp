# IA / LLM

Un service de base (`App\Service\AiService`) est inclus pour interroger un LLM.
Tout provider expose un endpoint `/chat/completions` compatible OpenAI
(Gemini, Mistral, OpenAI, Ollama, Groq, etc.).

## Configuration

Variables à configurer dans `.env.local` :

| Variable | Description | Défaut |
|----------|-------------|--------|
| `AI_PROVIDER` | Nom du provider (informationnel) | `groq` |
| `AI_MODEL` | Identifiant du modèle | `openai/gpt-oss-120b` |
| `AI_API_KEY` | Clé API | `changeme` |
| `AI_BASE_URL` | URL de base (doit exposer `/chat/completions`) | `https://api.groq.com/openai/v1` |

## Utilisation

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

## Endpoints personnalisés

Le service expose aussi une méthode générique pour appeler n'importe quel
endpoint du provider (`/embeddings`, `/responses`, `/images/generations...`) :

```php
$data = $this->ai->request('/embeddings', ['input' => 'Hello world']);
```

## Limites et quotas

Le service applique automatiquement :

- **3 tentatives** sur erreur **HTTP 429** (rate limit), avec délai récupéré du
  header `Retry-After` ou du message d'erreur.
- **Timeout 60s** sur le client HTTP Symfony.
- Tronquage du contenu via `array_map()` côté scoring pour respecter la fenêtre
  de tokens du provider (Groq free tier = 8000 TPM par défaut).

Si un appel LLM échoue après les retries, `AiService::ask()` retourne `''` et
un log d'erreur est émis (`var/log/dev.log` → `app.ERROR: AiService: ...`).

## Prompts éditables en BDD

Les prompts utilisés par le scoring et le chat sont stockés en BDD dans la
table `scoring_prompt`. Ils sont éditables via l'interface admin
(`/admin/scoring-prompt/{slug}`).

Prompts disponibles :

| Source file | Rôle |
|-------------|------|
| `extract.system` | Prompt système pour l'extraction de mots-clés depuis les fiches produits |
| `extract.user` | Prompt utilisateur pour l'extraction |
| `scoring.role` | Rôle LLM pour le scoring (contexte entreprise + chapitre "ce que nous ne faisons pas") |
| `scoring.user` | Prompt de scoring marché par marché (JSON strict) |
| `chat.context` | Système prompt pour le chat IA par marché (RAG sur docs amoxtli) |

Les fichiers `.md` sources sont dans `src/DataFixtures/data/scoring/`. Au
boot, `PromptLoader` lit le fichier et insère/mets à jour la ligne BDD.
Les prompts supportent un frontmatter YAML minimal : `purpose`, `variant`,
`temperature`, `max_tokens`.

## Endpoints personnalisés

Le service expose aussi une méthode générique pour appeler n'importe quel
endpoint du provider (`/embeddings`, `/responses`, `/images/generations`...) :

```php
$data = $this->ai->request('/embeddings', ['input' => 'Hello world']);
```
