package flow

import (
	"context"
	"fmt"

	"github.com/bornholm/genai/llm"
)

// Score exécute le workflow de scoring : un seul appel LLM sans outil,
// en privilégiant la sortie structurée (response_format + schéma), avec
// repli sur l'appel texte + extraction JSON de l'agent Python.
func (s *Service) Score(ctx context.Context, req ScoreRequest) (status string, result any) {
	defer func() {
		if r := recover(); r != nil {
			status = StatusError
			result = ScoreError{Error: fmt.Sprintf("Erreur inattendue: %v", r)}
		}
	}()

	messages := []llm.Message{
		llm.NewMessage(llm.RoleSystem, s.prompts.ScoringRole()),
		llm.NewMessage(llm.RoleUser, buildScoreUserPrompt(s.prompts.ScoringUser(), req)),
	}

	// 1. Tentative structurée : schéma JSON imposé au provider.
	res, err := s.complete(ctx, s.cfg.CallTimeout, messages, nil, llm.WithJSONResponse(scoreResponseSchema()))
	if err != nil {
		// 2. Repli : appel texte simple, comme l'agent Python.
		res, err = s.complete(ctx, s.cfg.CallTimeout, messages, nil)
		if err != nil {
			return StatusError, ScoreError{Error: fmt.Sprintf("Erreur de connexion à l'API: %s", err)}
		}
	}

	content := res.Message().Content()
	parsed, parseErr := parseScoreJSON(content)
	if parseErr != nil {
		return StatusError, ScoreError{
			Error:       "Impossible de parser la réponse JSON",
			RawResponse: truncateRunes(content, 500),
			ToolsUsed:   []string{},
		}
	}

	return StatusDone, ScoreSuccess{
		Score:       parsed.Score,
		Priority:    parsed.Priority,
		Explanation: parsed.Explanation,
		Products:    parsed.Products,
		ToolsUsed:   []string{},
	}
}

// buildScoreUserPrompt reconstruit le prompt utilisateur du scoring,
// à l'identique de process_score (agent/main.py).
func buildScoreUserPrompt(template string, req ScoreRequest) string {
	deadline := orDefault(req.Deadline, "Non précisée")

	description := "Non disponible"
	if req.Description != "" {
		description = truncateRunes(req.Description, 2000)
	}

	products := formatProducts(req.Products, "Aucun produit disponible.")

	return template +
		"\n\n## Marché à évaluer\n\n" +
		"- **Titre**: " + req.Title + "\n" +
		"- **Acheteur**: " + req.Buyer + "\n" +
		"- **Montant**: " + req.Amount + "\n" +
		"- **Deadline**: " + deadline + "\n" +
		"- **Description**: " + description + "\n\n" +
		"## Produits Cadoles disponibles\n\n" +
		products +
		"\n"
}

// parsedScore est le résultat de l'extraction JSON du scoring.
type parsedScore struct {
	Score       int
	Priority    string
	Explanation string
	Products    []map[string]any
}

// parseScoreJSON extrait le JSON de la réponse, avec réparation (json-repair)
// et recherche des blocs ```json — plus tolérant que raw_decode en Python.
func parseScoreJSON(content string) (parsedScore, error) {
	out := parsedScore{Products: []map[string]any{}}

	items, err := llm.ParseJSON[map[string]any](llm.NewMessage(llm.RoleAssistant, content))
	if err != nil || len(items) == 0 {
		return out, fmt.Errorf("aucun objet JSON trouvé dans la réponse")
	}

	// Premier objet portant les champs attendus (cf. doc de ParseJSON).
	obj := items[0]
	for _, it := range items {
		if _, ok := it["score"]; ok {
			obj = it
			break
		}
		if _, ok := it["priority"]; ok {
			obj = it
			break
		}
		if _, ok := it["explanation"]; ok {
			obj = it
			break
		}
		if _, ok := it["products"]; ok {
			obj = it
			break
		}
	}

	if v, ok := obj["score"]; ok {
		if n, ok := atoiFlexible(v); ok {
			out.Score = n
		}
	}
	if v, ok := obj["priority"]; ok {
		if str, ok := v.(string); ok {
			out.Priority = str
		}
	} else {
		out.Priority = "C"
	}
	if v, ok := obj["explanation"]; ok {
		if str, ok := v.(string); ok {
			out.Explanation = str
		}
	}
	if v, ok := obj["products"]; ok {
		if arr, ok := v.([]any); ok {
			for _, item := range arr {
				if m, ok := item.(map[string]any); ok {
					out.Products = append(out.Products, m)
				}
			}
		}
	}

	return out, nil
}

// scoreResponseSchema décrit le JSON attendu pour le scoring (détail des
// sous-scores aligné sur scoring.user.md). Strict désactivé pour laisser le
// modèle enrichir librement les fiches produits.
func scoreResponseSchema() llm.ResponseSchema {
	schema := map[string]any{
		"type": "object",
		"properties": map[string]any{
			"score": map[string]any{
				"type":        "integer",
				"description": "Note globale 0-100",
			},
			"priority": map[string]any{
				"type":        "string",
				"description": "A si score>=80, B si >=60, C sinon",
			},
			"explanation": map[string]any{
				"type":        "string",
				"description": "3-5 phrases expliquant le score global, en citant des éléments factuels du marché",
			},
			"products": map[string]any{
				"type": "array",
				"items": map[string]any{
					"type": "object",
					"properties": map[string]any{
						"name":         map[string]any{"type": "string"},
						"score":        map[string]any{"type": "integer"},
						"priority":     map[string]any{"type": "string"},
						"relevance":    map[string]any{"type": "string"},
						"scoreDetails": map[string]any{"type": "string"},
					},
				},
			},
		},
		"required": []string{"score", "priority", "explanation", "products"},
	}
	return llm.NewResponseSchema("market_score", "Évaluation du marché public", schema).WithStrict(false)
}
