package flow

import (
	"context"
	"fmt"
	"strings"
	"time"

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

	// 1. Tentative structurée : schéma JSON imposé au provider. Bornée plus
	// court que l'appel texte : certains providers restent bloqués sur ce mode.
	start := time.Now()
	res, err := s.complete(ctx, s.cfg.ScoreStructuredTimeout, messages, nil, llm.WithJSONResponse(scoreResponseSchema()))
	if err != nil {
		// err.Error() : le TextHandler formate une erreur avec %+v, ce qui
		// déroulerait les piles pkg/errors que genai attache à ses erreurs.
		s.cfg.Logger.Warn("scoring : échec de l'appel structuré, repli sur l'appel texte",
			"market_id", req.MarketID, "duration", time.Since(start).Round(time.Millisecond), "error", err.Error())
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
			Code:        ScoreErrorParse,
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

// buildScoreUserPrompt rend le prompt utilisateur du scoring : le template
// scoring.user.md porte tous les placeholders (marché + catalogue).
func buildScoreUserPrompt(template string, req ScoreRequest) string {
	description := "Non disponible"
	if req.Description != "" {
		description = truncateRunes(req.Description, 2000)
	}

	return strings.NewReplacer(
		"{{idweb}}", req.Idweb,
		"{{title}}", req.Title,
		"{{buyer}}", orDefault(req.Buyer, "Information non disponible"),
		"{{description}}", description,
		"{{amount}}", orDefault(req.Amount, "Information non disponible"),
		"{{deadline}}", orDefault(req.Deadline, "Non précisée"),
		"{{catalogue}}", formatProducts(req.Products, "Aucun produit disponible."),
	).Replace(template)
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
			out.Explanation = truncateSentence(str, maxExplanationRunes)
		}
	}
	if v, ok := obj["products"]; ok {
		if arr, ok := v.([]any); ok {
			for _, item := range arr {
				if m, ok := item.(map[string]any); ok {
					out.Products = append(out.Products, normalizeScoredProduct(m))
				}
			}
		}
	}

	return out, nil
}

// Bornes des champs texte du scoring. Le modèle déverse régulièrement son
// analyse dans "scoreDetails" (10 993 caractères observés sur le marché 3655) :
// le prompt demande des longueurs courtes, ces bornes les garantissent.
const (
	maxExplanationRunes  = 400
	maxRelevanceRunes    = 300
	maxScoreDetailsRunes = 200
)

// truncateSentence coupe à la dernière fin de phrase avant limit, pour ne pas
// laisser une explication tronquée en pleine phrase ; sans ponctuation
// exploitable, elle retombe sur la coupe sèche.
func truncateSentence(s string, limit int) string {
	s = strings.TrimSpace(s)
	runes := []rune(s)
	if len(runes) <= limit {
		return s
	}

	for i := limit - 1; i > limit/2; i-- {
		if runes[i] == '.' || runes[i] == '!' || runes[i] == '?' {
			return string(runes[:i+1])
		}
	}

	return string(runes[:limit])
}

// normalizeScoredProduct borne les champs libres d'un produit et retire le
// texte des produits non pertinents, qui n'apportent rien à la fiche.
func normalizeScoredProduct(p map[string]any) map[string]any {
	if score, ok := atoiFlexible(p["score"]); ok && score == 0 {
		return map[string]any{
			"id":       p["id"],
			"name":     p["name"],
			"score":    score,
			"priority": "C",
		}
	}

	out := make(map[string]any, len(p))
	for k, v := range p {
		out[k] = v
	}
	if str, ok := out["relevance"].(string); ok {
		out["relevance"] = truncateSentence(str, maxRelevanceRunes)
	}
	// Les sous-scores forment une ligne de chiffres : une coupe sèche suffit.
	if str, ok := out["scoreDetails"].(string); ok {
		out["scoreDetails"] = truncateRunes(strings.TrimSpace(str), maxScoreDetailsRunes)
	}

	return out
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
				"description": "3 phrases maximum (400 caractères) : justification du score global, avec des éléments factuels du marché",
			},
			"products": map[string]any{
				"type": "array",
				"items": map[string]any{
					"type": "object",
					"properties": map[string]any{
						"id":       map[string]any{"type": "integer", "description": "ID du produit repris tel quel du catalogue"},
						"name":     map[string]any{"type": "string", "description": "Nom du produit repris tel quel du catalogue"},
						"score":    map[string]any{"type": "integer", "description": "Note 0-100 pour ce produit"},
						"priority": map[string]any{"type": "string", "description": "A si score>=80, B si >=60, C sinon"},
						"relevance": map[string]any{
							"type":        "string",
							"description": "2 phrases maximum (300 caractères) : pourquoi ce produit correspond (ou non) au marché",
						},
						"scoreDetails": map[string]any{
							"type":        "string",
							"description": "Une seule ligne de cinq sous-scores, ex: fonctionnel 40/40, métier 19/20, technique 18/20, commercial 8/10, complexité 9/10 — sans commentaire",
						},
					},
					"required": []string{"id", "name", "score", "priority", "relevance"},
				},
			},
		},
		"required": []string{"score", "priority", "explanation", "products"},
	}
	return llm.NewResponseSchema("market_score", "Évaluation du marché public", schema).WithStrict(false)
}
