package tools

import (
	"context"
	"encoding/json"
	"fmt"
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

// writeReport écrit le rapport du corpus d'un workspace, comme l'indexation PHP.
func writeReport(t *testing.T, workspace string, sources map[string]string) {
	t.Helper()
	data, err := json.Marshal(map[string]any{"sources": sources})
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(workspace, "corpus-report.json"), data, 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestAmoxtliSearchGivesPieceNames(t *testing.T) {
	dir := t.TempDir()
	bin := writeScript(t, dir, `#!/bin/sh
cat <<'JSON'
{
  "results": [
    {"source": "file:///Cahier%20des%20charges.pdf.txt", "score": 0.5, "sections": [{"id": "s1", "content": "R&D <b>"}]},
    {"source": "file:///annexes/notes.txt", "score": 0.25}
  ],
  "next_cursor": ""
}
JSON
`)

	workspace := filepath.Join(dir, "ws", "1")
	if err := os.MkdirAll(filepath.Join(workspace, ".amoxtli"), 0o755); err != nil {
		t.Fatal(err)
	}
	writeReport(t, workspace, map[string]string{"Cahier des charges.pdf.txt": "Cahier des charges.pdf", "annexes/notes.txt": "annexes/NOTES.TXT"})
	tl := New(Config{AmoxtliBin: bin, AmoxtliDir: filepath.Join(dir, "ws")})

	got := execute(t, tl, "amoxtli_search", map[string]any{"query": "x", "market_id": float64(1)})
	want := `{"next_cursor":"","results":[{"score":0.5,"sections":[{"id":"s1","content":"R&D <b>"}],"source":"Cahier des charges.pdf"},{"score":0.25,"source":"annexes/NOTES.TXT"}]}`
	if got != want {
		t.Errorf("amoxtli_search = %s\nattendu       %s", got, want)
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

func TestLimitSearchOutputUnderBudgetUnchanged(t *testing.T) {
	out := `{"results": [{"source": "file:///cctp.txt", "sections": [{"id": "a", "content": "court"}]}]}`
	if got := limitSearchOutput(out); got != out {
		t.Errorf("sortie modifiée sous le budget: %q", got)
	}
}

func TestLimitSearchOutputTruncatesHugeSections(t *testing.T) {
	// Reproduit un tableur converti : une section unique de plusieurs Mo,
	// suivie d'un document normal.
	huge := strings.Repeat(`"",`, 1_000_000)
	payload := map[string]any{"results": []any{
		map[string]any{"source": "file:///bpu.txt", "sections": []any{map[string]any{"id": "x", "content": huge}}},
		map[string]any{"source": "file:///cctp.txt", "sections": []any{map[string]any{"id": "y", "content": "Article 1 <délai>"}}},
	}}
	raw, err := json.Marshal(payload)
	if err != nil {
		t.Fatal(err)
	}

	got := limitSearchOutput(string(raw))

	decoded := decodeLimited(t, got)
	if !decoded.Truncated || len(decoded.Results) != 2 {
		t.Fatalf("truncated=%v, %d résultat(s)", decoded.Truncated, len(decoded.Results))
	}
	if c := decoded.Results[0].Sections[0].Content; !strings.HasSuffix(c, truncatedMarker) {
		t.Errorf("section énorme non signalée comme tronquée: …%q", c[len(c)-20:])
	}
	if c := decoded.Results[1].Sections[0].Content; c != "Article 1 <délai>" {
		t.Errorf("section courte altérée: %q", c)
	}
}

func TestLimitSearchOutputCountsJSONEscaping(t *testing.T) {
	// Contenu CSV : chaque guillemet et chaque saut de ligne double à
	// l'encodage, le budget doit porter sur le JSON produit.
	csv := strings.Repeat(`"Lot 1","12",""`+"\n", 400)
	results := make([]any, 0, 5)
	for d := range 5 {
		sections := make([]any, 0, 4)
		for s := range 4 {
			sections = append(sections, map[string]any{"id": fmt.Sprintf("d%d-s%d", d, s), "content": csv})
		}
		results = append(results, map[string]any{"source": fmt.Sprintf("file:///bpu%d.txt", d), "sections": sections})
	}
	raw, err := json.Marshal(map[string]any{"results": results})
	if err != nil {
		t.Fatal(err)
	}

	decoded := decodeLimited(t, limitSearchOutput(string(raw)))
	if !decoded.Truncated {
		t.Error("truncated attendu")
	}
}

func TestLimitSearchOutputCountsEmptySections(t *testing.T) {
	sections := make([]any, 0, 3000)
	for i := range 3000 {
		sections = append(sections, map[string]any{"id": fmt.Sprintf("section-%04d", i), "content": ""})
	}
	raw, err := json.Marshal(map[string]any{"results": []any{
		map[string]any{"source": "file:///vide.txt", "sections": sections},
	}})
	if err != nil {
		t.Fatal(err)
	}

	decoded := decodeLimited(t, limitSearchOutput(string(raw)))
	if !decoded.Truncated {
		t.Error("truncated attendu")
	}
}

func TestLimitSearchOutputReencodesWithoutCutting(t *testing.T) {
	// Sortie indentée au-delà du budget mais contenu sous le budget : rien
	// n'est coupé, le LLM ne doit pas lire "truncated".
	sections := make([]any, 0, 30)
	for i := range 30 {
		sections = append(sections, map[string]any{"id": fmt.Sprintf("s%d", i), "content": strings.Repeat("a", 1000)})
	}
	raw, err := json.MarshalIndent(map[string]any{"results": []any{
		map[string]any{"source": "file:///cctp.txt", "sections": sections},
	}}, "", strings.Repeat(" ", 200))
	if err != nil {
		t.Fatal(err)
	}
	if len(raw) <= maxSearchChars {
		t.Fatalf("jeu de test trop petit: %d caractères", len(raw))
	}

	decoded := decodeLimited(t, limitSearchOutput(string(raw)))
	if decoded.Truncated {
		t.Error("truncated alors que rien n'a été coupé")
	}
	if n := len(decoded.Results[0].Sections); n != 30 {
		t.Errorf("%d section(s) gardée(s), attendu 30", n)
	}
}

type limitedOutput struct {
	Truncated bool `json:"truncated"`
	Results   []struct {
		Source   string `json:"source"`
		Sections []struct {
			Content string `json:"content"`
		} `json:"sections"`
	} `json:"results"`
}

// decodeLimited vérifie qu'une sortie bornée tient dans maxSearchChars et
// reste du JSON valide.
func decodeLimited(t *testing.T, got string) limitedOutput {
	t.Helper()
	if n := len([]rune(got)); n > maxSearchChars {
		t.Fatalf("sortie trop longue: %d caractères", n)
	}
	var decoded limitedOutput
	if err := json.Unmarshal([]byte(got), &decoded); err != nil {
		t.Fatalf("la sortie bornée doit rester du JSON valide: %v", err)
	}
	return decoded
}

func TestListDocuments(t *testing.T) {
	dir := t.TempDir()
	bin := writeScript(t, dir, "#!/bin/sh\nprintf '%s' '{\"documents\": [{\"source\": \"file:///CCTP.pdf.txt\"}], \"total\": 1}'\n")

	workspaceRoot := filepath.Join(dir, "ws")
	if err := os.MkdirAll(filepath.Join(workspaceRoot, "3", ".amoxtli"), 0o755); err != nil {
		t.Fatal(err)
	}
	tl := New(Config{AmoxtliBin: bin, AmoxtliDir: workspaceRoot})

	got := execute(t, tl, "list_documents", map[string]any{"market_id": float64(3)})
	if got != `{"documents":["CCTP.pdf.txt"],"total":1}` {
		t.Errorf("list_documents sans rapport du corpus = %q", got)
	}

	writeReport(t, filepath.Join(workspaceRoot, "3"), map[string]string{"CCTP.pdf.txt": "CCTP.pdf"})
	got = execute(t, tl, "list_documents", map[string]any{"market_id": float64(3)})
	if got != `{"documents":["CCTP.pdf"],"total":1}` {
		t.Errorf("list_documents = %q", got)
	}
}

func TestListDocumentsReadsEveryPage(t *testing.T) {
	pageSize := docListPageSize
	docListPageSize = 2
	t.Cleanup(func() { docListPageSize = pageSize })

	// 5 documents, servis par pages de --limit.
	dir := t.TempDir()
	bin := writeScript(t, dir, `#!/bin/sh
while [ $# -gt 0 ]; do
  case "$1" in
    --limit) limit=$2 ;;
    --page) page=$2 ;;
  esac
  shift
done
docs=""
i=$(( (page - 1) * limit + 1 ))
last=$(( page * limit ))
while [ $i -le $last ] && [ $i -le 5 ]; do
  docs="$docs${docs:+,}{\"source\": \"file:///piece-$i.txt\"}"
  i=$(( i + 1 ))
done
printf '{"documents": [%s], "total": 5}' "$docs"
`)

	workspaceRoot := filepath.Join(dir, "ws")
	if err := os.MkdirAll(filepath.Join(workspaceRoot, "3", ".amoxtli"), 0o755); err != nil {
		t.Fatal(err)
	}
	tl := New(Config{AmoxtliBin: bin, AmoxtliDir: workspaceRoot})

	got := execute(t, tl, "list_documents", map[string]any{"market_id": float64(3)})
	if got != `{"documents":["piece-1.txt","piece-2.txt","piece-3.txt","piece-4.txt","piece-5.txt"],"total":5}` {
		t.Errorf("list_documents = %q", got)
	}

	listed := maxListedDocuments
	maxListedDocuments = 3
	t.Cleanup(func() { maxListedDocuments = listed })

	got = execute(t, tl, "list_documents", map[string]any{"market_id": float64(3)})
	if got != `{"documents":["piece-1.txt","piece-2.txt","piece-3.txt"],"total":5,"truncated":true}` {
		t.Errorf("list_documents bornée = %q", got)
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

// busyScript simule un workspace tenu par une indexation : amoxtli refuse
// les refusals premiers appels, puis répond.
func busyScript(t *testing.T, dir string, refusals int) string {
	t.Helper()
	counter := filepath.Join(dir, "calls")
	return writeScript(t, dir, fmt.Sprintf(`#!/bin/sh
n=$(cat %[1]q 2>/dev/null || echo 0)
echo $((n + 1)) > %[1]q
if [ "$n" -lt %[2]d ]; then
	echo 'error: workspace is already in use by another amoxtli process (sync, pid 1); only one process can use it at a time' >&2
	exit 1
fi
printf '%%s' '{"hits": 1}'
`, counter, refusals))
}

func TestAmoxtliSearchWaitsForTheWorkspace(t *testing.T) {
	dir := t.TempDir()
	bin := busyScript(t, dir, 2)

	workspaceRoot := filepath.Join(dir, "ws")
	if err := os.MkdirAll(filepath.Join(workspaceRoot, "1", ".amoxtli"), 0o755); err != nil {
		t.Fatal(err)
	}
	tl := New(Config{AmoxtliBin: bin, AmoxtliDir: workspaceRoot})

	got := execute(t, tl, "amoxtli_search", map[string]any{"query": "x", "market_id": float64(1)})
	if got != `{"hits": 1}` {
		t.Errorf("amoxtli_search = %q", got)
	}
}

func TestAmoxtliBusyWaitIsBounded(t *testing.T) {
	wait, retry := amoxtliBusyWait, amoxtliBusyRetry
	amoxtliBusyWait, amoxtliBusyRetry = 300*time.Millisecond, 10*time.Millisecond
	defer func() { amoxtliBusyWait, amoxtliBusyRetry = wait, retry }()

	dir := t.TempDir()
	bin := busyScript(t, dir, 1_000_000)

	workspaceRoot := filepath.Join(dir, "ws")
	if err := os.MkdirAll(filepath.Join(workspaceRoot, "1", ".amoxtli"), 0o755); err != nil {
		t.Fatal(err)
	}
	tl := New(Config{AmoxtliBin: bin, AmoxtliDir: workspaceRoot})

	start := time.Now()
	got := execute(t, tl, "list_documents", map[string]any{"market_id": float64(1)})
	if !strings.Contains(got, "workspace is already in use") {
		t.Errorf("l'erreur d'amoxtli doit remonter: %q", got)
	}
	if elapsed := time.Since(start); elapsed > 5*time.Second {
		t.Errorf("attente de %s, bornée à %s attendue", elapsed, amoxtliBusyWait)
	}
}
