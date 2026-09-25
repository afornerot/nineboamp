package tools

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func execute(t *testing.T, tl *Tools, name string, params map[string]any) string {
	t.Helper()
	for _, tool := range tl.All() {
		if tool.Name() != name {
			continue
		}
		res, err := tool.Execute(context.Background(), params)
		if err != nil {
			t.Fatalf("exécution de %s: %v", name, err)
		}
		return res.Text()
	}
	t.Fatalf("outil %s introuvable", name)
	return ""
}

func writeScript(t *testing.T, dir, content string) string {
	t.Helper()
	path := filepath.Join(dir, "fake-amoxtli")
	if err := os.WriteFile(path, []byte(content), 0o755); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestAmoxtliSearch(t *testing.T) {
	dir := t.TempDir()
	bin := writeScript(t, dir, "#!/bin/sh\nprintf '%s' '{\"hits\": 1}'\n")

	workspaceRoot := filepath.Join(dir, "workspaces")
	marketDir := filepath.Join(workspaceRoot, "5")
	if err := os.MkdirAll(filepath.Join(marketDir, ".amoxtli"), 0o755); err != nil {
		t.Fatal(err)
	}

	tl := New(Config{AmoxtliBin: bin, AmoxtliDir: workspaceRoot})

	got := execute(t, tl, "amoxtli_search", map[string]any{"query": "groupe", "market_id": float64(5)})
	if got != `{"hits": 1}` {
		t.Errorf("amoxtli_search = %q", got)
	}
}

func TestAmoxtliSearchMissingWorkspace(t *testing.T) {
	dir := t.TempDir()
	tl := New(Config{AmoxtliBin: "/bin/false", AmoxtliDir: dir})

	got := execute(t, tl, "amoxtli_search", map[string]any{"query": "x", "market_id": float64(99)})

	var payload map[string]string
	if err := json.Unmarshal([]byte(got), &payload); err != nil {
		t.Fatalf("le résultat doit être du JSON: %q", got)
	}
	if !strings.Contains(payload["error"], "Pas de workspace amoxtli pour le marché 99") {
		t.Errorf("error = %q", payload["error"])
	}
}

func TestAmoxtliSearchFailureReturnsStderr(t *testing.T) {
	dir := t.TempDir()
	bin := writeScript(t, dir, "#!/bin/sh\necho 'boom' >&2\nexit 1\n")

	workspaceRoot := filepath.Join(dir, "ws")
	if err := os.MkdirAll(filepath.Join(workspaceRoot, "1", ".amoxtli"), 0o755); err != nil {
		t.Fatal(err)
	}
	tl := New(Config{AmoxtliBin: bin, AmoxtliDir: workspaceRoot})

	got := execute(t, tl, "amoxtli_search", map[string]any{"query": "x", "market_id": float64(1)})

	if !strings.Contains(got, "boom") {
		t.Errorf("stderr attendu dans l'erreur: %q", got)
	}
}

func TestListDocuments(t *testing.T) {
	dir := t.TempDir()
	bin := writeScript(t, dir, "#!/bin/sh\nprintf '%s' '[\"cctp.pdf\"]'\n")

	workspaceRoot := filepath.Join(dir, "ws")
	if err := os.MkdirAll(filepath.Join(workspaceRoot, "3", ".amoxtli"), 0o755); err != nil {
		t.Fatal(err)
	}
	tl := New(Config{AmoxtliBin: bin, AmoxtliDir: workspaceRoot})

	got := execute(t, tl, "list_documents", map[string]any{"market_id": float64(3)})
	if got != `["cctp.pdf"]` {
		t.Errorf("list_documents = %q", got)
	}
}

func TestSearchProducts(t *testing.T) {
	var gotAuth, gotQuery string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotAuth = r.Header.Get("Authorization")
		gotQuery = r.URL.Query().Get("q")
		w.Write([]byte(`[{"id": 85, "name": "Ninedad"}]`))
	}))
	defer srv.Close()

	tl := New(Config{APIBase: srv.URL, MCPSecret: "secret-123", HTTPClient: srv.Client()})

	got := execute(t, tl, "search_products", map[string]any{"query": "ninedad"})

	if gotAuth != "Bearer secret-123" {
		t.Errorf("Authorization = %q", gotAuth)
	}
	if gotQuery != "ninedad" {
		t.Errorf("q = %q", gotQuery)
	}
	if !strings.Contains(got, "Ninedad") {
		t.Errorf("corps non transmis: %q", got)
	}
}

func TestSearchProductsUnauthorized(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
	}))
	defer srv.Close()

	tl := New(Config{APIBase: srv.URL, HTTPClient: srv.Client()})

	got := execute(t, tl, "search_products", map[string]any{"query": "x"})
	if !strings.Contains(got, "MCP_SECRET") {
		t.Errorf("erreur 401 attendue: %q", got)
	}
}

func TestGetProductInfo(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/product/85" {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		w.Write([]byte(`{"id": 85, "name": "Ninedad"}`))
	}))
	defer srv.Close()

	tl := New(Config{APIBase: srv.URL, HTTPClient: srv.Client()})

	got := execute(t, tl, "get_product_info", map[string]any{"product_id": float64(85)})
	if !strings.Contains(got, "Ninedad") {
		t.Errorf("get_product_info = %q", got)
	}

	// 404 → message lisible pour le modèle.
	got = execute(t, tl, "get_product_info", map[string]any{"product_id": float64(999)})
	if !strings.Contains(got, "non trouvé") {
		t.Errorf("404 = %q", got)
	}
}

func TestToolSchemasHaveNamesAndDescriptions(t *testing.T) {
	tl := New(Config{})
	tools := tl.All()
	if len(tools) != 4 {
		t.Fatalf("nombre d'outils = %d, attendu 4", len(tools))
	}
	want := []string{"amoxtli_search", "list_documents", "search_products", "get_product_info"}
	for i, tool := range tools {
		if tool.Name() != want[i] {
			t.Errorf("outil %d = %q, attendu %q", i, tool.Name(), want[i])
		}
		if tool.Description() == "" {
			t.Errorf("outil %s sans description", tool.Name())
		}
		if len(tool.Parameters()) == 0 {
			t.Errorf("outil %s sans schéma", tool.Name())
		}
	}
}

func TestParamIntTolerance(t *testing.T) {
	if got, err := paramInt(map[string]any{"market_id": float64(42)}, "market_id"); err != nil || got != 42 {
		t.Errorf("float64 → %d, %v", got, err)
	}
	if got, err := paramInt(map[string]any{"market_id": "42"}, "market_id"); err != nil || got != 42 {
		t.Errorf("string → %d, %v", got, err)
	}
	if _, err := paramInt(map[string]any{}, "market_id"); err == nil {
		t.Error("paramètre manquant doit être une erreur")
	}
	if got := paramIntDefault(map[string]any{}, "limit", 5); got != 5 {
		t.Errorf("défaut = %d", got)
	}
}

func TestExecTimeout(t *testing.T) {
	dir := t.TempDir()
	bin := writeScript(t, dir, "#!/bin/sh\nsleep 5\n")

	workspaceRoot := filepath.Join(dir, "ws")
	if err := os.MkdirAll(filepath.Join(workspaceRoot, "1", ".amoxtli"), 0o755); err != nil {
		t.Fatal(err)
	}
	tl := New(Config{AmoxtliBin: bin, AmoxtliDir: workspaceRoot, ExecTimeout: 100 * time.Millisecond})

	got := execute(t, tl, "list_documents", map[string]any{"market_id": float64(1)})
	if !strings.Contains(got, "error") {
		t.Errorf("timeout attendu comme erreur JSON: %q", got)
	}
}
