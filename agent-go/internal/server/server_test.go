package server

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"nineboamp/agent-go/internal/flow"
	"nineboamp/agent-go/internal/jobs"
)

// stubRunner renvoie des résultats immédiats, contrôlables par test.
type stubRunner struct {
	chatResult any
	chatStatus string
	// chatBlock, si non nul, retarde la fin du chat : permet de tester des
	// états intermédiaires sans course avec la goroutine du serveur.
	chatBlock chan struct{}
}

func (s *stubRunner) Chat(_ context.Context, _ flow.ChatRequest) (string, any) {
	if s.chatBlock != nil {
		<-s.chatBlock
	}
	return s.chatStatus, s.chatResult
}

func (s *stubRunner) Score(context.Context, flow.ScoreRequest) (string, any) {
	return flow.StatusDone, flow.ScoreSuccess{Score: 10, Priority: "C", Products: []map[string]any{}, ToolsUsed: []string{}}
}

func (s *stubRunner) Report(context.Context, flow.ReportRequest) (string, any) {
	return flow.StatusDone, flow.ReportResult{Markdown: "# Rapport", ToolsUsed: []string{}}
}

func newTestServer(t *testing.T, runner Runner) *httptest.Server {
	t.Helper()
	store, err := jobs.NewStore(t.TempDir(), 300*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	srv := httptest.NewServer(New(Config{Jobs: store, Runner: runner}))
	t.Cleanup(srv.Close)
	return srv
}

func get(t *testing.T, url string) (int, map[string]any) {
	t.Helper()
	resp, err := http.Get(url)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	return decode(t, resp)
}

func post(t *testing.T, url, body string) (int, map[string]any) {
	t.Helper()
	resp, err := http.Post(url, "application/json", strings.NewReader(body))
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	return decode(t, resp)
}

func decode(t *testing.T, resp *http.Response) (int, map[string]any) {
	t.Helper()
	data, err := io.ReadAll(resp.Body)
	if err != nil {
		t.Fatal(err)
	}
	var payload map[string]any
	if err := json.Unmarshal(data, &payload); err != nil {
		t.Fatalf("réponse non JSON (%d): %s", resp.StatusCode, data)
	}
	return resp.StatusCode, payload
}

func TestHealth(t *testing.T) {
	srv := newTestServer(t, &stubRunner{})

	status, body := get(t, srv.URL+"/health")
	if status != http.StatusOK {
		t.Fatalf("code = %d", status)
	}
	if body["status"] != "ok" || body["service"] != "agent" {
		t.Errorf("body = %v", body)
	}
}

func TestChatJobLifecycle(t *testing.T) {
	runner := &stubRunner{chatStatus: flow.StatusDone, chatResult: flow.ChatResult{
		Answer: "réponse", Sources: []string{}, ToolsUsed: []string{"search_products"},
	}}
	srv := newTestServer(t, runner)

	status, body := post(t, srv.URL+"/chat", `{"market_id": 1, "message": "salut", "history": [{"role":"user","content":"hi"}]}`)
	if status != http.StatusOK {
		t.Fatalf("POST code = %d", status)
	}
	jobID, _ := body["job_id"].(string)
	if jobID == "" {
		t.Fatalf("job_id manquant: %v", body)
	}

	// Le résultat apparaît dès que la goroutine a fini (poll comme le PHP).
	deadline := time.Now().Add(2 * time.Second)
	for {
		status, job := get(t, srv.URL+"/chat/result/"+jobID)
		if status != http.StatusOK {
			t.Fatalf("GET code = %d", status)
		}
		if job["status"] == "done" {
			result := job["result"].(map[string]any)
			if result["answer"] != "réponse" {
				t.Errorf("answer = %v", result["answer"])
			}
			if _, ok := result["error"]; ok {
				t.Errorf("error ne doit pas figurer sur un résultat done: %v", result)
			}
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("job jamais terminé: %v", job)
		}
		time.Sleep(10 * time.Millisecond)
	}
}

func TestResultNotFound(t *testing.T) {
	srv := newTestServer(t, &stubRunner{})

	status, body := get(t, srv.URL+"/chat/result/inexistant")
	if status != http.StatusOK {
		t.Fatalf("code = %d (le Python répond 200 + not_found)", status)
	}
	if body["status"] != "not_found" || body["result"] != nil {
		t.Errorf("body = %v", body)
	}
}

func TestResultRejectsPathTraversal(t *testing.T) {
	srv := newTestServer(t, &stubRunner{})

	_, body := get(t, srv.URL+"/score/result/..%2F..%2Fetc%2Fpasswd")
	if body["status"] != "not_found" {
		t.Errorf("body = %v", body)
	}
}

func TestScoreAndReportEndpoints(t *testing.T) {
	srv := newTestServer(t, &stubRunner{})

	_, body := post(t, srv.URL+"/score", `{"market_id": 1, "title": "t", "buyer": "b", "description": "d", "amount": "1000"}`)
	jobID := body["job_id"].(string)
	waitForStatus(t, srv.URL+"/score/result/"+jobID, "done")

	_, body = post(t, srv.URL+"/report", `{"market_id": 1, "title": "t", "buyer": "b", "description": "d", "amount": "1000", "catalogue": "c"}`)
	jobID = body["job_id"].(string)
	waitForStatus(t, srv.URL+"/report/result/"+jobID, "done")
}

func waitForStatus(t *testing.T, url, want string) {
	t.Helper()
	deadline := time.Now().Add(2 * time.Second)
	for {
		_, body := get(t, url)
		if body["status"] == want {
			return
		}
		if body["status"] == "error" {
			t.Fatalf("statut error inattendu: %v", body)
		}
		if time.Now().After(deadline) {
			t.Fatalf("statut = %v, attendu %q", body["status"], want)
		}
		time.Sleep(10 * time.Millisecond)
	}
}

func TestInvalidJSONBody(t *testing.T) {
	srv := newTestServer(t, &stubRunner{})

	status, _ := post(t, srv.URL+"/chat", `ce n'est pas du JSON`)
	if status != http.StatusUnprocessableEntity {
		t.Errorf("code = %d, attendu 422 (parité FastAPI)", status)
	}
}

func TestDeleteJob(t *testing.T) {
	runner := &stubRunner{chatStatus: jobs.StatusPending, chatResult: nil, chatBlock: make(chan struct{})}
	srv := newTestServer(t, runner)
	defer close(runner.chatBlock)

	_, body := post(t, srv.URL+"/chat", `{"market_id": 1, "message": "x"}`)
	jobID := body["job_id"].(string)

	resp, err := http.NewRequest(http.MethodDelete, srv.URL+"/chat/"+jobID, nil)
	if err != nil {
		t.Fatal(err)
	}
	res, err := http.DefaultClient.Do(resp)
	if err != nil {
		t.Fatal(err)
	}
	res.Body.Close()
	if res.StatusCode != http.StatusOK {
		t.Fatalf("DELETE code = %d", res.StatusCode)
	}

	_, body = get(t, srv.URL+"/chat/result/"+jobID)
	if body["status"] != "not_found" {
		t.Errorf("après suppression = %v", body)
	}
}

func TestMethodNotAllowed(t *testing.T) {
	srv := newTestServer(t, &stubRunner{})

	resp, err := http.Get(srv.URL + "/chat")
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if resp.StatusCode != http.StatusMethodNotAllowed {
		t.Errorf("GET /chat = %d, attendu 405", resp.StatusCode)
	}
}

func TestCORSHeaders(t *testing.T) {
	srv := newTestServer(t, &stubRunner{})

	resp, err := http.Get(srv.URL + "/health")
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if resp.Header.Get("Access-Control-Allow-Origin") != "*" {
		t.Errorf("CORS manquant: %v", resp.Header)
	}
}

func TestErrorStatusFromRunner(t *testing.T) {
	runner := &stubRunner{
		chatStatus: flow.StatusError,
		chatResult: flow.ChatResult{Answer: "Erreur…", Sources: []string{}, Error: "boom"},
	}
	srv := newTestServer(t, runner)

	_, body := post(t, srv.URL+"/chat", `{"market_id": 1, "message": "x"}`)
	jobID := body["job_id"].(string)
	waitForStatus(t, srv.URL+"/chat/result/"+jobID, "error")

	_, job := get(t, srv.URL+"/chat/result/"+jobID)
	result := job["result"].(map[string]any)
	if result["error"] != "boom" {
		t.Errorf("result = %v", result)
	}
}
