// Package tools implémente les 4 outils de l'agent (amoxtli + API produits
// PHP) sous forme d'outils genai (llm.NewFuncTool), avec les mêmes contrats
// que execute_tool en Python : les erreurs sont retournées comme JSON
// {"error": …} pour que le modèle puisse s'auto-corriger.
package tools

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/bornholm/genai/llm"
)

// Config regroupe les dépendances des outils.
type Config struct {
	MCPSecret   string
	APIBase     string // ex: http://127.0.0.1
	AmoxtliBin  string
	AmoxtliDir  string
	HTTPClient  *http.Client
	ExecTimeout time.Duration
}

// Tools expose les outils de l'agent.
type Tools struct {
	cfg Config
}

// New construit l'ensemble des outils.
func New(cfg Config) *Tools {
	if cfg.HTTPClient == nil {
		cfg.HTTPClient = &http.Client{Timeout: 10 * time.Second}
	}
	if cfg.ExecTimeout == 0 {
		cfg.ExecTimeout = 30 * time.Second
	}
	return &Tools{cfg: cfg}
}

// All retourne les 4 outils, avec les mêmes noms, descriptions et schémas
// que get_tools() en Python.
func (t *Tools) All() []llm.Tool {
	return []llm.Tool{
		llm.NewFuncTool(
			"amoxtli_search",
			"Recherche dans les documents indexés d'un marché via amoxtli. Retourne les sections pertinentes.",
			llm.NewJSONSchema().
				RequiredProperty("query", "Requête de recherche", "string").
				RequiredProperty("market_id", "ID du marché", "integer").
				Property("limit", "Nombre max de résultats", "integer"),
			t.amoxtliSearch,
		),
		llm.NewFuncTool(
			"list_documents",
			"Liste les documents indexés dans amoxtli pour un marché.",
			llm.NewJSONSchema().
				RequiredProperty("market_id", "ID du marché", "integer"),
			t.listDocuments,
		),
		llm.NewFuncTool(
			"search_products",
			"Recherche des produits par nom ou mots-clés. Retourne une liste de produits avec leur ID et description courte.",
			llm.NewJSONSchema().
				RequiredProperty("query", "Nom ou mot-clé à rechercher dans les produits", "string"),
			t.searchProducts,
		),
		llm.NewFuncTool(
			"get_product_info",
			"Retourne les informations complètes d'un produit (description, keywords, sectors, fiche technique).",
			llm.NewJSONSchema().
				RequiredProperty("product_id", "ID du produit", "integer"),
			t.getProductInfo,
		),
	}
}

func (t *Tools) amoxtliSearch(ctx context.Context, params map[string]any) (llm.ToolResult, error) {
	query, err := paramString(params, "query")
	if err != nil {
		return errorResult(err.Error()), nil
	}
	marketID, err := paramInt(params, "market_id")
	if err != nil {
		return errorResult(err.Error()), nil
	}
	limit := paramIntDefault(params, "limit", 5)

	workspace, err := t.workspace(marketID)
	if err != nil {
		return errorResult(err.Error()), nil
	}

	stdout, stderr, err := t.run(ctx, t.cfg.AmoxtliBin, "-C", workspace, "search", query, "--json", "-n", strconv.Itoa(limit))
	if err != nil {
		if stderr != "" {
			return errorResult(stderr), nil
		}
		return errorResult(err.Error()), nil
	}
	return llm.NewToolResult(limitSearchOutput(stdout)), nil
}

// Bornes de la sortie d'amoxtli_search transmise au LLM : une recherche
// renvoie toutes les sections des documents trouvés, et une section peut
// contenir un document entier (ex. tableur converti en CSV, plusieurs Mo),
// ce qui dépasse la fenêtre de contexte du modèle.
const (
	maxSearchChars  = 40000
	maxSectionChars = 4000
	truncatedMarker = "… [tronqué]"
)

var markerLen = utf8.RuneCountInString(truncatedMarker)

// limitSearchOutput laisse passer telle quelle une sortie sous le budget.
// Au-delà, chaque section est coupée à maxSectionChars, puis résultats et
// sections sont conservés dans l'ordre de pertinence tant que le JSON
// réencodé (clés, ids et échappement compris) tient dans maxSearchChars.
// "truncated" n'est ajouté que si un contenu a été coupé ou écarté.
func limitSearchOutput(out string) string {
	if utf8.RuneCountInString(out) <= maxSearchChars {
		return out
	}

	var payload map[string]any
	if err := json.Unmarshal([]byte(out), &payload); err != nil {
		return truncateRunes(out, maxSearchChars)
	}
	results, _ := payload["results"].([]any)

	payload["results"] = []any{}
	payload["truncated"] = true
	budget := maxSearchChars - encodedLen(payload)
	cut := false

	kept := make([]any, 0, len(results))
	for _, r := range results {
		result, ok := r.(map[string]any)
		if !ok {
			cut = true
			continue
		}
		sections, _ := result["sections"].([]any)
		if sections != nil {
			result["sections"] = []any{}
		}
		cost := encodedLen(result) + 1 // +1 : virgule entre éléments
		if cost > budget {
			cut = true
			continue
		}
		budget -= cost

		keptSections := make([]any, 0, len(sections))
		for _, s := range sections {
			section, ok := s.(map[string]any)
			if !ok {
				cut = true
				continue
			}
			original, _ := section["content"].(string)
			cost := fitSection(section, budget)
			if cost == 0 {
				cut = true
				continue
			}
			if section["content"] != original {
				cut = true
			}
			budget -= cost
			keptSections = append(keptSections, section)
		}
		if sections != nil {
			result["sections"] = keptSections
		}
		kept = append(kept, result)
	}
	payload["results"] = kept
	if !cut {
		delete(payload, "truncated")
	}

	encoded, err := encodeJSON(payload)
	if err != nil {
		return truncateRunes(out, maxSearchChars)
	}
	return encoded
}

// fitSection tronque le contenu de section pour que son encodage JSON tienne
// dans budget, et renvoie ce coût (0 si la section ne tient pas du tout).
func fitSection(section map[string]any, budget int) int {
	content, _ := section["content"].(string)
	content = truncateRunes(content, maxSectionChars)
	for {
		section["content"] = content
		cost := encodedLen(section) + 1
		if cost <= budget {
			return cost
		}
		// Chaque caractère retiré réduit l'encodage d'au moins un caractère.
		n := utf8.RuneCountInString(content) - (cost - budget)
		if n <= markerLen {
			return 0
		}
		content = truncateRunes(content, n)
	}
}

func encodeJSON(v any) (string, error) {
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	if err := enc.Encode(v); err != nil {
		return "", err
	}
	return strings.TrimSuffix(buf.String(), "\n"), nil
}

func encodedLen(v any) int {
	encoded, err := encodeJSON(v)
	if err != nil {
		return maxSearchChars + 1
	}
	return utf8.RuneCountInString(encoded)
}

// truncateRunes coupe s à n caractères (et non octets), marqueur de coupe
// compris.
func truncateRunes(s string, n int) string {
	runes := []rune(s)
	if len(runes) <= n {
		return s
	}
	return string(runes[:max(n-markerLen, 0)]) + truncatedMarker
}

func (t *Tools) listDocuments(ctx context.Context, params map[string]any) (llm.ToolResult, error) {
	marketID, err := paramInt(params, "market_id")
	if err != nil {
		return errorResult(err.Error()), nil
	}
	workspace, err := t.workspace(marketID)
	if err != nil {
		return errorResult(err.Error()), nil
	}

	stdout, stderr, err := t.run(ctx, t.cfg.AmoxtliBin, "-C", workspace, "doc", "list", "--json")
	if err != nil {
		if stderr != "" {
			return errorResult(stderr), nil
		}
		return errorResult(err.Error()), nil
	}
	return llm.NewToolResult(stdout), nil
}

func (t *Tools) searchProducts(ctx context.Context, params map[string]any) (llm.ToolResult, error) {
	query, err := paramString(params, "query")
	if err != nil {
		return errorResult(err.Error()), nil
	}
	endpoint := t.cfg.APIBase + "/api/products/search?q=" + url.QueryEscape(query)

	body, status, err := t.get(ctx, endpoint)
	if err != nil {
		return errorResult(err.Error()), nil
	}
	if status == http.StatusUnauthorized {
		return errorResult("Non autorisé - MCP_SECRET manquant ou invalide"), nil
	}
	return llm.NewToolResult(body), nil
}

func (t *Tools) getProductInfo(ctx context.Context, params map[string]any) (llm.ToolResult, error) {
	productID, err := paramInt(params, "product_id")
	if err != nil {
		return errorResult(err.Error()), nil
	}
	endpoint := fmt.Sprintf("%s/api/product/%d", t.cfg.APIBase, productID)

	body, status, err := t.get(ctx, endpoint)
	if err != nil {
		return errorResult(err.Error()), nil
	}
	switch status {
	case http.StatusNotFound:
		return errorResult(fmt.Sprintf("Produit %d non trouvé", productID)), nil
	case http.StatusUnauthorized:
		return errorResult("Non autorisé - MCP_SECRET manquant ou invalide"), nil
	}
	return llm.NewToolResult(body), nil
}

// workspace retourne le dossier amoxtli d'un marché, en vérifiant la présence
// du dossier .amoxtli (comme en Python).
func (t *Tools) workspace(marketID int) (string, error) {
	workspace := filepath.Join(t.cfg.AmoxtliDir, strconv.Itoa(marketID))
	if _, err := os.Stat(filepath.Join(workspace, ".amoxtli")); err != nil {
		return "", fmt.Errorf("Pas de workspace amoxtli pour le marché %d", marketID)
	}
	return workspace, nil
}

// run exécute un binaire avec un timeout, en séparant stdout et stderr.
func (t *Tools) run(ctx context.Context, name string, args ...string) (stdout, stderr string, err error) {
	cctx, cancel := context.WithTimeout(ctx, t.cfg.ExecTimeout)
	defer cancel()

	var outBuf, errBuf bytes.Buffer
	cmd := exec.CommandContext(cctx, name, args...)
	cmd.Stdout = &outBuf
	cmd.Stderr = &errBuf
	err = cmd.Run()
	return outBuf.String(), errBuf.String(), err
}

func (t *Tools) get(ctx context.Context, endpoint string) (body string, status int, err error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return "", 0, err
	}
	req.Header.Set("Authorization", "Bearer "+t.cfg.MCPSecret)

	resp, err := t.cfg.HTTPClient.Do(req)
	if err != nil {
		return "", 0, err
	}
	defer resp.Body.Close()

	data, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", resp.StatusCode, err
	}
	return string(data), resp.StatusCode, nil
}

func errorResult(message string) llm.ToolResult {
	payload, err := json.Marshal(map[string]string{"error": message})
	if err != nil {
		return llm.NewToolResult(`{"error": "erreur d'encodage"}`)
	}
	return llm.NewToolResult(string(payload))
}

// paramString lit un paramètre chaîne (le modèle peut parfois envoyer un
// nombre, on convertit).
func paramString(params map[string]any, name string) (string, error) {
	raw, exists := params[name]
	if !exists {
		return "", fmt.Errorf("paramètre manquant: %s", name)
	}
	switch v := raw.(type) {
	case string:
		return v, nil
	case float64:
		return strconv.FormatFloat(v, 'f', -1, 64), nil
	default:
		return fmt.Sprint(v), nil
	}
}

// paramInt lit un paramètre entier, tolérant float64 (JSON) et chaîne.
func paramInt(params map[string]any, name string) (int, error) {
	raw, exists := params[name]
	if !exists {
		return 0, fmt.Errorf("paramètre manquant: %s", name)
	}
	switch v := raw.(type) {
	case float64:
		return int(v), nil
	case int:
		return v, nil
	case string:
		n, err := strconv.Atoi(v)
		if err != nil {
			return 0, fmt.Errorf("paramètre invalide %s: %q", name, v)
		}
		return n, nil
	default:
		return 0, fmt.Errorf("paramètre invalide %s: %v", name, raw)
	}
}

func paramIntDefault(params map[string]any, name string, def int) int {
	n, err := paramInt(params, name)
	if err != nil {
		return def
	}
	return n
}
