// Package prompts charge les prompts markdown de l'agent, en retirant le
// front-matter YAML — port fidèle de load_prompt_from_file (agent/main.py).
package prompts

import (
	"os"
	"path/filepath"
	"strings"
)

// Prompts accède aux fichiers de prompts d'un dossier donné.
type Prompts struct {
	dir string
}

// New construit un accesseur de prompts pour le dossier dir.
func New(dir string) *Prompts {
	return &Prompts{dir: dir}
}

// Dir retourne le dossier résolu.
func (p *Prompts) Dir() string {
	return p.dir
}

// ResolveDir choisit le dossier des prompts : valeur de drapeau si fournie,
// puis PROMPTS_DIR, puis le chemin conteneur (/app/…), puis le chemin local.
// Renvoie le dernier candidat même s'il n'existe pas (erreur explicite plus tard).
func ResolveDir(flagValue string) string {
	var candidates []string
	if flagValue != "" {
		candidates = append(candidates, flagValue)
	}
	if env := os.Getenv("PROMPTS_DIR"); env != "" {
		candidates = append(candidates, env)
	}
	candidates = append(candidates,
		"/app/src/DataFixtures/data/scoring",
		"src/DataFixtures/data/scoring",
	)
	for _, c := range candidates {
		if st, err := os.Stat(c); err == nil && st.IsDir() {
			return c
		}
	}
	return candidates[len(candidates)-1]
}

// load lit un prompt et retire le front-matter YAML (--- … ---) s'il est
// présent. Retourne fallback si le fichier n'existe pas.
func (p *Prompts) load(name, fallback string) string {
	content, err := os.ReadFile(filepath.Join(p.dir, name))
	if err != nil {
		return fallback
	}
	return stripFrontMatter(string(content))
}

func stripFrontMatter(content string) string {
	if strings.HasPrefix(content, "---") {
		if end := strings.Index(content[3:], "\n---"); end != -1 {
			content = content[end+3+4:]
		}
	}
	return strings.TrimSpace(content)
}

// ChatSystem charge chat.context.md (prompt système du chat).
func (p *Prompts) ChatSystem() string {
	return p.load("chat.context.md", "Tu es un assistant expert en analyse de marchés publics.")
}

// ScoringRole charge scoring.role.md (prompt système du scoring).
func (p *Prompts) ScoringRole() string {
	return p.load("scoring.role.md", "Tu es un expert en analyse de marchés publics pour Cadoles.")
}

// ScoringUser charge scoring.user.md (template utilisateur du scoring).
func (p *Prompts) ScoringUser() string {
	return p.load("scoring.user.md", "")
}

// ReportSystem charge report.system.md (prompt système du rapport).
func (p *Prompts) ReportSystem() string {
	return p.load("report.system.md", "Tu es un consultant senior en marchés publics pour Cadoles.")
}

// ReportUser charge report.user.md (template utilisateur du rapport).
func (p *Prompts) ReportUser() string {
	return p.load("report.user.md", "")
}
