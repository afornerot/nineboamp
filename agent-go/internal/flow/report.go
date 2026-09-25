package flow

import (
	"context"
	"fmt"
	"strings"

	"github.com/bornholm/genai/llm"
)

// Report exécute le workflow de génération de rapport : boucle d'outils
// bornée sur le template report.user.md avec substitution des placeholders.
func (s *Service) Report(ctx context.Context, req ReportRequest) (status string, result any) {
	defer func() {
		if r := recover(); r != nil {
			status = StatusError
			result = ReportResult{Error: fmt.Sprintf("Erreur inattendue: %v", r)}
		}
	}()

	messages := []llm.Message{
		llm.NewMessage(llm.RoleSystem, s.prompts.ReportSystem()),
		llm.NewMessage(llm.RoleUser, buildReportUserPrompt(s.prompts.ReportUser(), req)),
	}

	toolsUsed := []string{}

	for i := 0; i < s.cfg.MaxIterations; i++ {
		res, err := s.complete(ctx, s.cfg.ReportCallTimeout, messages, s.tools)
		if err != nil {
			prefix := "Erreur de connexion à l'API"
			if i > 0 {
				prefix = "Erreur lors de l'exécution des outils"
			}
			return StatusError, ReportResult{
				Error:     fmt.Sprintf("%s: %s", prefix, err),
				ToolsUsed: toolsUsed,
			}
		}

		toolCalls := res.ToolCalls()
		if len(toolCalls) == 0 {
			markdown := strings.TrimSpace(res.Message().Content())
			if markdown == "" {
				return StatusError, ReportResult{
					Error:     "Réponse vide de l'agent",
					ToolsUsed: toolsUsed,
				}
			}
			return StatusDone, ReportResult{
				Markdown:  markdown,
				ToolsUsed: toolsUsed,
			}
		}

		messages = append(messages, llm.NewToolCallsMessageWithContent(res.Message().Content(), toolCalls...))
		for _, tc := range toolCalls {
			toolsUsed = append(toolsUsed, tc.Name())
			msg, err := llm.ExecuteToolCall(ctx, tc, s.tools...)
			if err != nil {
				msg = llm.NewToolMessage(tc.ID(), llm.NewToolResult(fmt.Sprintf("Tool execution error: %s", err.Error())))
			}
			messages = append(messages, msg)
		}
	}

	return StatusError, ReportResult{
		Error:     fmt.Sprintf("Erreur lors de l'exécution des outils: nombre maximal d'itérations atteint (%d)", s.cfg.MaxIterations),
		ToolsUsed: toolsUsed,
	}
}

// buildReportUserPrompt substitue les placeholders du template report.user.md,
// à l'identique de process_report (agent/main.py).
func buildReportUserPrompt(template string, req ReportRequest) string {
	metadata := fmt.Sprintf(
		"- ID: %d\n- Titre: %s\n- Acheteur: %s\n- Montant: %s\n- Deadline: %s",
		req.MarketID,
		req.Title,
		orDefault(req.Buyer, "N/A"),
		orDefault(req.Amount, "N/A"),
		orDefault(req.Deadline, "Non précisée"),
	)

	description := "Non disponible"
	if req.Description != "" {
		description = truncateRunes(req.Description, 4000)
	}

	products := formatProducts(req.Products, "Aucun produit identifié par le scoring.")
	catalogue := orDefault(req.Catalogue, "Catalogue non fourni.")

	var history strings.Builder
	if len(req.ChatHistory) > 0 {
		for _, m := range req.ChatHistory {
			label := "Assistant"
			if m.Role == "user" || m.Role == "" {
				label = "Utilisateur"
			}
			history.WriteString("- **" + label + "**: " + truncateRunes(m.Content, 1000) + "\n")
		}
	} else {
		history.WriteString("Aucun message marqué comme important.")
	}

	replacer := strings.NewReplacer(
		"{{metadata}}", metadata,
		"{{description}}", description,
		"{{products}}", products,
		"{{catalogue}}", catalogue,
		"{{chatHistory}}", history.String(),
	)
	return replacer.Replace(template)
}
