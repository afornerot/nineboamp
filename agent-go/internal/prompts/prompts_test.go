package prompts

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func writePrompt(t *testing.T, dir, name, content string) {
	t.Helper()
	if err := os.WriteFile(filepath.Join(dir, name), []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestLoadStripsFrontMatter(t *testing.T) {
	dir := t.TempDir()
	writePrompt(t, dir, "chat.context.md", `---
purpose: system
temperature: 0.3
---

Tu es un assistant expert.

{{metadata}}
`)

	p := New(dir)
	got := p.ChatSystem()

	if strings.HasPrefix(got, "---") {
		t.Errorf("le front-matter n'a pas été retiré: %q", got[:20])
	}
	if strings.Contains(got, "temperature") {
		t.Errorf("le contenu du front-matter reste présent: %q", got)
	}
	if !strings.Contains(got, "Tu es un assistant expert.") {
		t.Errorf("corps du prompt manquant: %q", got)
	}
	if strings.TrimSpace(got) != got {
		t.Errorf("le prompt n'est pas trimé: %q", got)
	}
}

func TestLoadFallbackWhenMissing(t *testing.T) {
	p := New(t.TempDir())

	if got := p.ChatSystem(); got != "Tu es un assistant expert en analyse de marchés publics." {
		t.Errorf("fallback chat = %q", got)
	}
	if got := p.ScoringRole(); got != "Tu es un expert en analyse de marchés publics pour Cadoles." {
		t.Errorf("fallback scoring = %q", got)
	}
	if got := p.ReportSystem(); got != "Tu es un consultant senior en marchés publics pour Cadoles." {
		t.Errorf("fallback report = %q", got)
	}
	if got := p.ScoringUser(); got != "" {
		t.Errorf("fallback scoring user = %q, attendu vide", got)
	}
}

func TestStripFrontMatterWithoutFrontMatter(t *testing.T) {
	got := stripFrontMatter("Prompt brut.\nAvec lignes.\n")
	if got != "Prompt brut.\nAvec lignes." {
		t.Errorf("stripFrontMatter = %q", got)
	}
}

func TestResolveDir(t *testing.T) {
	flagDir := t.TempDir()
	if got := ResolveDir(flagDir); got != flagDir {
		t.Errorf("ResolveDir(flag) = %q, attendu %q", got, flagDir)
	}

	// Dossier inexistant : on retombe sur un candidat par défaut, jamais panique.
	got := ResolveDir(filepath.Join(t.TempDir(), "nope"))
	if got == "" {
		t.Error("ResolveDir ne doit jamais renvoyer vide")
	}
}
