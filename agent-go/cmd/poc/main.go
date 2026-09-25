package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"os"
	"time"

	"github.com/bornholm/genai/llm"
	"github.com/bornholm/genai/llm/provider"
	_ "github.com/bornholm/genai/llm/provider/all"
	"github.com/bornholm/genai/llm/provider/env"
	"github.com/bornholm/genai/llm/retry"
	"nineboamp/agent-go/internal/config"
)

// POC Phase 0 : valide la chaîne genai -> xolo (provider OpenAI-compatible)
//   - une exécution d'outil en boucle. Sert aussi de diagnostic rapide :
//     go run ./cmd/poc -env-dir ..
func main() {
	envDir := flag.String("env-dir", ".", "dossier contenant .env.local puis .env")
	flag.Parse()

	// Priorité .env.local > .env, set-if-absent (mêmes règles que l'agent Python),
	// puis mappage AI_* -> GENAI_CHAT_COMPLETION_*.
	if err := config.LoadEnvFiles(*envDir); err != nil {
		log.Fatalf("chargement de l'environnement: %+v", err)
	}
	config.MapToGenAI()

	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()

	client, err := provider.Create(ctx, env.With("GENAI_"))
	if err != nil {
		log.Fatalf("provider.Create: %+v", err)
	}
	client = retry.NewClient(client, time.Second, 3)

	fmt.Printf("[POC] model=%s base_url=%s api_key=%s…\n",
		os.Getenv("GENAI_CHAT_COMPLETION_OPENAI_MODEL"),
		os.Getenv("GENAI_CHAT_COMPLETION_OPENAI_BASE_URL"),
		truncate(os.Getenv("GENAI_CHAT_COMPLETION_OPENAI_API_KEY"), 6),
	)

	clock := llm.NewFuncTool(
		"get_current_time",
		"Retourne l'heure actuelle dans le fuseau horaire demandé",
		llm.NewJSONSchema().RequiredProperty("timezone", "Fuseau horaire IANA, ex: Europe/Paris", "string"),
		func(ctx context.Context, params map[string]any) (llm.ToolResult, error) {
			tz, err := llm.ToolParam[string](params, "timezone")
			if err != nil {
				return nil, err
			}
			loc, err := time.LoadLocation(tz)
			if err != nil {
				loc = time.UTC
			}
			return llm.NewToolResult(time.Now().In(loc).Format(time.RFC1123)), nil
		},
	)

	messages := []llm.Message{
		llm.NewMessage(llm.RoleSystem, "Tu es un assistant utile. Utilise systématiquement l'outil get_current_time pour répondre aux questions sur l'heure."),
		llm.NewMessage(llm.RoleUser, "Quelle heure est-il à Paris ? Utilise ton outil."),
	}

	// Boucle d'outils (pattern ChatSession du sample genai), bornée à 5.
	toolsUsed := []string{}
	for iteration := 0; iteration < 5; iteration++ {
		res, err := client.ChatCompletion(ctx,
			llm.WithMessages(messages...),
			llm.WithTools(clock),
			llm.WithToolChoice(llm.ToolChoiceAuto),
		)
		if err != nil {
			log.Fatalf("ChatCompletion (itération %d): %+v", iteration, err)
		}

		toolCalls := res.ToolCalls()
		if len(toolCalls) == 0 {
			fmt.Printf("[POC] RÉPONSE FINALE: %s\n", res.Message().Content())
			fmt.Printf("[POC] outils utilisés: %v\n", toolsUsed)
			fmt.Println("[POC] SUCCÈS")
			return
		}

		messages = append(messages, llm.NewToolCallsMessageWithContent(res.Message().Content(), toolCalls...))
		for _, tc := range toolCalls {
			toolsUsed = append(toolsUsed, tc.Name())
			msg, err := llm.ExecuteToolCall(ctx, tc, clock)
			if err != nil {
				msg = llm.NewToolMessage(tc.ID(), llm.NewToolResult(fmt.Sprintf("Tool execution error: %s", err.Error())))
			}
			messages = append(messages, msg)
			fmt.Printf("[POC] tool call: %s -> %s\n", tc.Name(), msg.Content())
		}
	}

	log.Fatal("[POC] ÉCHEC: boucle d'outils non bornée atteinte (5 itérations)")
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}
