package flow

import (
	"context"
	"fmt"

	"github.com/bornholm/genai/llm"
)

// Chat exécute le workflow chat : historique + message, boucle d'outils
// bornée, réponse finale. Retourne (statut, payload).
func (s *Service) Chat(ctx context.Context, req ChatRequest) (status string, result any) {
	defer func() {
		if r := recover(); r != nil {
			status = StatusError
			result = ChatResult{
				Answer:    fmt.Sprintf("Erreur inattendue: %v", r),
				Sources:   []string{},
				ToolsUsed: []string{},
				Error:     fmt.Sprint(r),
			}
		}
	}()

	messages := []llm.Message{llm.NewMessage(llm.RoleSystem, s.prompts.ChatSystem())}
	for _, m := range req.History {
		role := m.Role
		if role == "" {
			role = "user"
		}
		messages = append(messages, llm.NewMessage(llm.Role(role), m.Content))
	}
	messages = append(messages, llm.NewMessage(llm.RoleUser, req.Message))

	toolsUsed := []string{}

	for i := 0; i < s.cfg.MaxIterations; i++ {
		res, err := s.complete(ctx, s.cfg.CallTimeout, messages, s.tools)
		if err != nil {
			prefix := "Erreur de connexion à l'API"
			if i > 0 {
				prefix = "Erreur lors de l'exécution des outils"
			}
			return StatusError, ChatResult{
				Answer:    fmt.Sprintf("%s: %s", prefix, err),
				Sources:   []string{},
				ToolsUsed: toolsUsed,
				Error:     err.Error(),
			}
		}

		toolCalls := res.ToolCalls()
		if len(toolCalls) == 0 {
			return StatusDone, ChatResult{
				Answer:    res.Message().Content(),
				Sources:   []string{},
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

	return StatusError, ChatResult{
		Answer:    fmt.Sprintf("Erreur lors de l'exécution des outils: %s", fmt.Errorf("nombre maximal d'itérations atteint (%d)", s.cfg.MaxIterations)),
		Sources:   []string{},
		ToolsUsed: toolsUsed,
		Error:     "nombre maximal d'itérations atteint",
	}
}
