// Package flow implémente les 3 workflows de l'agent (chat, scoring,
// rapport) sur le SDK genai : boucle d'outils bornée, appel structuré pour
// le scoring, et reconstitution à l'identique des prompts de l'agent Python.
package flow

import (
	"context"
	"strconv"
	"strings"
	"time"

	"github.com/bornholm/genai/llm"
	"nineboamp/agent-go/internal/prompts"
)

// Statuts de résultat (parité avec l'agent Python).
const (
	StatusDone  = "done"
	StatusError = "error"
)

// HistoryMessage est un message d'historique envoyé par PHP.
type HistoryMessage struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

// ChatRequest est le corps de POST /chat.
type ChatRequest struct {
	MarketID     int              `json:"market_id"`
	Message      string           `json:"message"`
	History      []HistoryMessage `json:"history"`
	ResetSession bool             `json:"reset_session"`
}

// ScoreRequest est le corps de POST /score.
type ScoreRequest struct {
	MarketID    int              `json:"market_id"`
	Title       string           `json:"title"`
	Buyer       string           `json:"buyer"`
	Description string           `json:"description"`
	Amount      string           `json:"amount"`
	Deadline    string           `json:"deadline"`
	Products    []map[string]any `json:"products"`
}

// ReportRequest est le corps de POST /report.
type ReportRequest struct {
	MarketID    int              `json:"market_id"`
	Title       string           `json:"title"`
	Buyer       string           `json:"buyer"`
	Description string           `json:"description"`
	Amount      string           `json:"amount"`
	Deadline    string           `json:"deadline"`
	Products    []map[string]any `json:"products"`
	Catalogue   string           `json:"catalogue"`
	ChatHistory []HistoryMessage `json:"chat_history"`
}

// ChatResult est le payload result d'un job chat.
type ChatResult struct {
	Answer    string   `json:"answer"`
	Sources   []string `json:"sources"`
	ToolsUsed []string `json:"tools_used"`
	Error     string   `json:"error,omitempty"`
}

// ScoreSuccess est le payload result d'un scoring réussi.
type ScoreSuccess struct {
	Score       int              `json:"score"`
	Priority    string           `json:"priority"`
	Explanation string           `json:"explanation"`
	Products    []map[string]any `json:"products"`
	ToolsUsed   []string         `json:"tools_used"`
}

// ScoreError est le payload result d'un scoring échoué.
type ScoreError struct {
	Error       string   `json:"error"`
	RawResponse string   `json:"raw_response,omitempty"`
	ToolsUsed   []string `json:"tools_used,omitempty"`
}

// ReportResult est le payload result d'un job rapport.
type ReportResult struct {
	Markdown  string   `json:"markdown,omitempty"`
	ToolsUsed []string `json:"tools_used,omitempty"`
	Error     string   `json:"error,omitempty"`
}

// Config règle la boucle d'outils et les timeouts d'appel LLM.
type Config struct {
	MaxIterations     int
	CallTimeout       time.Duration
	ReportCallTimeout time.Duration
}

// Service exécute les workflows de l'agent.
type Service struct {
	client  llm.ChatCompletionClient
	prompts *prompts.Prompts
	tools   []llm.Tool
	cfg     Config
}

// New construit le service.
func New(client llm.ChatCompletionClient, p *prompts.Prompts, tools []llm.Tool, cfg Config) *Service {
	if cfg.MaxIterations <= 0 {
		cfg.MaxIterations = 10
	}
	if cfg.CallTimeout <= 0 {
		cfg.CallTimeout = 120 * time.Second
	}
	if cfg.ReportCallTimeout <= 0 {
		cfg.ReportCallTimeout = 180 * time.Second
	}
	return &Service{client: client, prompts: p, tools: tools, cfg: cfg}
}

// complete envoie un appel chat/completions avec timeout par appel,
// en n'ajoutant des outils que s'il y en a (le scoring n'en a pas).
func (s *Service) complete(
	ctx context.Context,
	timeout time.Duration,
	messages []llm.Message,
	tools []llm.Tool,
	extra ...llm.ChatCompletionOptionFunc,
) (llm.ChatCompletionResponse, error) {
	cctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	opts := []llm.ChatCompletionOptionFunc{llm.WithMessages(messages...)}
	if len(tools) > 0 {
		opts = append(opts, llm.WithTools(tools...), llm.WithToolChoice(llm.ToolChoiceAuto))
	}
	opts = append(opts, extra...)
	return s.client.ChatCompletion(cctx, opts...)
}

// truncateRunes coupe à n caractères (et non octets), comme le slicing Python.
func truncateRunes(s string, n int) string {
	runes := []rune(s)
	if len(runes) <= n {
		return s
	}
	return string(runes[:n])
}

// orDefault retourne def si v est vide (équivalent de `x or "défaut"` en Python).
func orDefault(v, def string) string {
	if v == "" {
		return def
	}
	return v
}

// formatProducts construit la liste markdown des produits (parité exacte
// avec le formatage Python de process_score/process_report).
func formatProducts(products []map[string]any, emptyFallback string) string {
	if len(products) == 0 {
		return emptyFallback
	}
	var b strings.Builder
	for _, p := range products {
		name, _ := p["name"].(string)
		if name == "" {
			name = "Unknown"
		}
		desc, _ := p["description"].(string)
		keywords, _ := p["keywords"].(string)
		sectors, _ := p["sectors"].(string)

		b.WriteString("- **" + name + "**")
		if desc != "" {
			b.WriteString("\n  Description: " + truncateRunes(desc, 200))
		}
		if keywords != "" {
			b.WriteString("\n  Mots-clés: " + keywords)
		}
		if sectors != "" {
			b.WriteString("\n  Secteurs: " + sectors)
		}
		b.WriteString("\n")
	}
	return b.String()
}

// atoiFlexible convertit une valeur JSON en entier (nombre ou chaîne).
func atoiFlexible(v any) (int, bool) {
	switch n := v.(type) {
	case float64:
		return int(n), true
	case string:
		if i, err := strconv.Atoi(n); err == nil {
			return i, true
		}
	}
	return 0, false
}
