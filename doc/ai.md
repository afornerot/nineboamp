# IA / LLM

Un service de base (`App\Service\AiService`) est inclus pour interroger un LLM.
Tout provider expose un endpoint `/chat/completions` compatible OpenAI
(Gemini, Mistral, OpenAI, Ollama, etc.).

## Configuration

Variables à configurer dans `.env.local` :

| Variable | Description | Défaut |
|----------|-------------|--------|
| `AI_PROVIDER` | Nom du provider (informationnel) | `gemini` |
| `AI_MODEL` | Identifiant du modèle | `gemini-2.0-flash` |
| `AI_API_KEY` | Clé API | `changeme` |
| `AI_BASE_URL` | URL de base (doit exposer `/chat/completions`) | `https://generativelanguage.googleapis.com/v1beta/openai` |

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
```

## Endpoints personnalisés

Le service expose aussi une méthode générique pour appeler n'importe quel
endpoint du provider (`/embeddings`, `/responses`, `/images/generations`...) :

```php
$data = $this->ai->request('/embeddings', ['input' => 'Hello world']);
```
