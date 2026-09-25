package flow

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/bornholm/genai/llm"
	"nineboamp/agent-go/internal/prompts"
)

// fakeClient est un llm.ChatCompletionClient scripté pour les tests.
type fakeClient struct {
	queue []fakeCall
	calls []capturedCall
}

type fakeCall struct {
	resp llm.ChatCompletionResponse
	err  error
}

type capturedCall struct {
	messages       []llm.Message
	responseFormat llm.ResponseFormat
	toolCount      int
}

func (f *fakeClient) ChatCompletion(_ context.Context, opts ...llm.ChatCompletionOptionFunc) (llm.ChatCompletionResponse, error) {
	o := llm.NewChatCompletionOptions(opts...)
	f.calls = append(f.calls, capturedCall{
		messages:       o.Messages,
		responseFormat: o.ResponseFormat,
		toolCount:      len(o.Tools),
	})
	if len(f.queue) == 0 {
		return nil, errors.New("aucune réponse scriptée")
	}
	next := f.queue[0]
	f.queue = f.queue[1:]
	return next.resp, next.err
}

func assistant(content string) fakeCall {
	return fakeCall{resp: llm.NewChatCompletionResponse(llm.NewMessage(llm.RoleAssistant, content), nil)}
}

func withToolCalls(content string, calls ...llm.ToolCall) fakeCall {
	return fakeCall{resp: llm.NewChatCompletionResponse(llm.NewMessage(llm.RoleAssistant, content), nil, calls...)}
}

func failing(err error) fakeCall {
	return fakeCall{err: err}
}

func writePrompt(t *testing.T, dir, name, content string) {
	t.Helper()
	if err := os.WriteFile(filepath.Join(dir, name), []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

func testPrompts(t *testing.T) *prompts.Prompts {
	t.Helper()
	dir := t.TempDir()
	writePrompt(t, dir, "chat.context.md", "---\ntemperature: 0.3\n---\n\nSYSTEME CHAT\n")
	writePrompt(t, dir, "scoring.role.md", "SYSTEME SCORING\n")
	writePrompt(t, dir, "scoring.user.md", "TEMPLATE SCORING\n")
	writePrompt(t, dir, "report.system.md", "SYSTEME RAPPORT\n")
	writePrompt(t, dir, "report.user.md", "META:{{metadata}}|DESC:{{description}}|PROD:{{products}}|CAT:{{catalogue}}|HIST:{{chatHistory}}\n")
	return prompts.New(dir)
}

func clockTool() llm.Tool {
	return llm.NewFuncTool(
		"get_time",
		"heure",
		llm.NewJSONSchema().RequiredProperty("timezone", "fuseau", "string"),
		func(context.Context, map[string]any) (llm.ToolResult, error) {
			return llm.NewToolResult("12:00"), nil
		},
	)
}

func newService(t *testing.T, client *fakeClient, tools []llm.Tool, cfg Config) *Service {
	t.Helper()
	return New(client, testPrompts(t), tools, cfg)
}

func TestChatSuccessWithToolCall(t *testing.T) {
	client := &fakeClient{queue: []fakeCall{
		withToolCalls("", llm.NewToolCall("tc-1", "get_time", `{"timezone":"Europe/Paris"}`)),
		assistant("Il est midi."),
	}}
	svc := newService(t, client, []llm.Tool{clockTool()}, Config{})

	status, result := svc.Chat(context.Background(), ChatRequest{
		MarketID: 1,
		Message:  "Quelle heure ?",
		History:  []HistoryMessage{{Role: "user", Content: "Bonjour"}, {Role: "assistant", Content: "Salut"}},
	})

	if status != StatusDone {
		t.Fatalf("status = %q, result = %+v", status, result)
	}
	chat := result.(ChatResult)
	if chat.Answer != "Il est midi." {
		t.Errorf("answer = %q", chat.Answer)
	}
	if len(chat.ToolsUsed) != 1 || chat.ToolsUsed[0] != "get_time" {
		t.Errorf("tools_used = %v", chat.ToolsUsed)
	}
	if chat.Sources == nil {
		t.Error("sources doit être un tableau vide, pas null")
	}
	if len(client.calls) != 2 {
		t.Fatalf("appels LLM = %d, attendu 2", len(client.calls))
	}
	// Premier appel : system + historique + message.
	if len(client.calls[0].messages) != 4 {
		t.Errorf("messages au 1er appel = %d, attendu 4", len(client.calls[0].messages))
	}
	// Second appel : le message tool doit être présent pour que le modèle
	// voie le résultat de son outil.
	last := client.calls[1].messages[len(client.calls[1].messages)-1]
	if last.Role() != llm.RoleTool || last.Content() != "12:00" {
		t.Errorf("dernier message = role %q content %q", last.Role(), last.Content())
	}
}

func TestChatAPIErrorFirstCall(t *testing.T) {
	client := &fakeClient{queue: []fakeCall{failing(errors.New("connection refused"))}}
	svc := newService(t, client, nil, Config{})

	status, result := svc.Chat(context.Background(), ChatRequest{Message: "salut"})

	if status != StatusError {
		t.Fatalf("status = %q", status)
	}
	chat := result.(ChatResult)
	if !strings.HasPrefix(chat.Answer, "Erreur de connexion à l'API:") {
		t.Errorf("answer = %q", chat.Answer)
	}
	if chat.Error == "" {
		t.Error("error ne doit pas être vide pour un statut error")
	}
}

func TestChatMaxIterations(t *testing.T) {
	client := &fakeClient{queue: []fakeCall{
		withToolCalls("", llm.NewToolCall("tc-1", "get_time", `{"timezone":"Paris"}`)),
	}}
	svc := newService(t, client, []llm.Tool{clockTool()}, Config{MaxIterations: 1})

	status, result := svc.Chat(context.Background(), ChatRequest{Message: "go"})

	if status != StatusError {
		t.Fatalf("status = %q", status)
	}
	chat := result.(ChatResult)
	if !strings.Contains(chat.Error, "itérations") {
		t.Errorf("error = %q", chat.Error)
	}
	if len(chat.ToolsUsed) != 1 {
		t.Errorf("tools_used = %v", chat.ToolsUsed)
	}
}

func TestChatHistoryRolesPassedThrough(t *testing.T) {
	client := &fakeClient{queue: []fakeCall{assistant("ok")}}
	svc := newService(t, client, nil, Config{})

	svc.Chat(context.Background(), ChatRequest{
		Message: "et maintenant ?",
		History: []HistoryMessage{{Role: "user", Content: "avant"}, {Role: "assistant", Content: "suite"}},
	})

	msgs := client.calls[0].messages
	if msgs[0].Role() != llm.RoleSystem {
		t.Errorf("1er message = %q, attendu system", msgs[0].Role())
	}
	if msgs[1].Role() != llm.RoleUser || msgs[2].Role() != llm.RoleAssistant || msgs[3].Role() != llm.RoleUser {
		t.Errorf("ordre des rôles = %q %q %q %q", msgs[0].Role(), msgs[1].Role(), msgs[2].Role(), msgs[3].Role())
	}
}

func TestScoreStructuredSuccess(t *testing.T) {
	client := &fakeClient{queue: []fakeCall{
		assistant(`{"score": 85, "priority": "A", "explanation": "Bon marché.", "products": [{"name": "Ninedad", "score": 85}]}`),
	}}
	svc := newService(t, client, nil, Config{})

	status, result := svc.Score(context.Background(), ScoreRequest{Title: "Marché", Products: []map[string]any{}})

	if status != StatusDone {
		t.Fatalf("status = %q, result = %+v", status, result)
	}
	score := result.(ScoreSuccess)
	if score.Score != 85 || score.Priority != "A" || score.Explanation != "Bon marché." {
		t.Errorf("score = %+v", score)
	}
	if len(score.Products) != 1 {
		t.Errorf("products = %v", score.Products)
	}
	if client.calls[0].responseFormat != llm.ResponseFormatJSON {
		t.Errorf("le 1er appel doit demander une sortie JSON, got %q", client.calls[0].responseFormat)
	}
	if client.calls[0].toolCount != 0 {
		t.Error("le scoring ne doit pas proposer d'outils")
	}
}

func TestScoreFallbackToPlainCall(t *testing.T) {
	client := &fakeClient{queue: []fakeCall{
		failing(errors.New("response_format non supporté")),
		assistant("```json\n{\"score\": 60, \"priority\": \"B\", \"explanation\": \"OK\", \"products\": []}\n```"),
	}}
	svc := newService(t, client, nil, Config{})

	status, result := svc.Score(context.Background(), ScoreRequest{})

	if status != StatusDone {
		t.Fatalf("status = %q, result = %+v", status, result)
	}
	if result.(ScoreSuccess).Score != 60 {
		t.Errorf("score = %+v", result)
	}
	if len(client.calls) != 2 {
		t.Fatalf("appels = %d, attendu 2 (structuré + repli)", len(client.calls))
	}
	if client.calls[1].responseFormat != llm.ResponseFormatDefault {
		t.Errorf("le repli doit être un appel texte simple, got %q", client.calls[1].responseFormat)
	}
}

func TestScoreDefaultsAndProseJSON(t *testing.T) {
	client := &fakeClient{queue: []fakeCall{
		assistant("Voici mon évaluation :\n{\"products\": []}\nFin."),
	}}
	svc := newService(t, client, nil, Config{})

	status, result := svc.Score(context.Background(), ScoreRequest{})

	if status != StatusDone {
		t.Fatalf("status = %q, result = %+v", status, result)
	}
	score := result.(ScoreSuccess)
	if score.Score != 0 {
		t.Errorf("score par défaut = %d", score.Score)
	}
	if score.Priority != "C" {
		t.Errorf("priority par défaut = %q, attendu C", score.Priority)
	}
	if score.Products == nil {
		t.Error("products doit être un tableau vide, pas null")
	}
}

func TestScoreParseFailure(t *testing.T) {
	client := &fakeClient{queue: []fakeCall{
		failing(errors.New("refusé")),
		assistant("désolé je ne peux pas"),
	}}
	svc := newService(t, client, nil, Config{})

	status, result := svc.Score(context.Background(), ScoreRequest{})

	if status != StatusError {
		t.Fatalf("status = %q", status)
	}
	scoreErr := result.(ScoreError)
	if scoreErr.Error != "Impossible de parser la réponse JSON" {
		t.Errorf("error = %q", scoreErr.Error)
	}
	if scoreErr.RawResponse != "désolé je ne peux pas" {
		t.Errorf("raw_response = %q", scoreErr.RawResponse)
	}
}

func TestScoreAPIErrorBothCalls(t *testing.T) {
	client := &fakeClient{queue: []fakeCall{
		failing(errors.New("boom structuré")),
		failing(errors.New("boom texte")),
	}}
	svc := newService(t, client, nil, Config{})

	status, result := svc.Score(context.Background(), ScoreRequest{})

	if status != StatusError {
		t.Fatalf("status = %q", status)
	}
	if !strings.Contains(result.(ScoreError).Error, "boom texte") {
		t.Errorf("error = %q", result.(ScoreError).Error)
	}
}

func TestReportSubstitutionAndTrim(t *testing.T) {
	client := &fakeClient{queue: []fakeCall{
		withToolCalls("", llm.NewToolCall("tc-1", "get_time", `{"timezone":"Paris"}`)),
		assistant("  # Rapport de positionnement  "),
	}}
	svc := newService(t, client, []llm.Tool{clockTool()}, Config{})

	status, result := svc.Report(context.Background(), ReportRequest{
		MarketID:    7,
		Title:       "Titre marche",
		Buyer:       "Ville de Dijon",
		Description: "Une description",
		Catalogue:   "Ninedad",
		Products:    []map[string]any{{"name": "Ninedad", "description": "kit GPS"}},
	})

	if status != StatusDone {
		t.Fatalf("status = %q, result = %+v", status, result)
	}
	report := result.(ReportResult)
	if report.Markdown != "# Rapport de positionnement" {
		t.Errorf("markdown = %q (trim attendu)", report.Markdown)
	}
	if len(report.ToolsUsed) != 1 {
		t.Errorf("tools_used = %v", report.ToolsUsed)
	}

	userMsg := client.calls[0].messages[1].Content()
	if strings.Contains(userMsg, "{{") {
		t.Errorf("placeholder non substitué: %q", userMsg)
	}
	for _, want := range []string{"- ID: 7", "Titre marche", "Ville de Dijon", "Ninedad", "kit GPS"} {
		if !strings.Contains(userMsg, want) {
			t.Errorf("contenu manquant dans le prompt: %q", want)
		}
	}
}

func TestReportEmptyResponse(t *testing.T) {
	client := &fakeClient{queue: []fakeCall{assistant("   ")}}
	svc := newService(t, client, nil, Config{})

	status, result := svc.Report(context.Background(), ReportRequest{})

	if status != StatusError {
		t.Fatalf("status = %q", status)
	}
	if result.(ReportResult).Error != "Réponse vide de l'agent" {
		t.Errorf("error = %q", result.(ReportResult).Error)
	}
}

func TestReportAPIError(t *testing.T) {
	client := &fakeClient{queue: []fakeCall{failing(errors.New("timeout"))}}
	svc := newService(t, client, nil, Config{})

	status, result := svc.Report(context.Background(), ReportRequest{})

	if status != StatusError {
		t.Fatalf("status = %q", status)
	}
	if !strings.HasPrefix(result.(ReportResult).Error, "Erreur de connexion à l'API:") {
		t.Errorf("error = %q", result.(ReportResult).Error)
	}
}

func TestCompleteUsesCallTimeout(t *testing.T) {
	client := &fakeClient{queue: []fakeCall{assistant("ok")}}
	svc := newService(t, client, nil, Config{CallTimeout: 50 * time.Millisecond})

	if _, err := svc.complete(context.Background(), svc.cfg.CallTimeout, []llm.Message{llm.NewMessage(llm.RoleUser, "hi")}, nil); err != nil {
		t.Errorf("complete: %v", err)
	}
}
