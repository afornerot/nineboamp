// Package jobs gère le stockage des jobs asynchrones en fichiers JSON,
// avec la même sémantique que l'agent Python : statuts pending/done/error,
// expiration par âge maximum (300 s par défaut → not_found).
package jobs

import (
	"encoding/json"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// Statuts de job (parité avec l'agent Python).
const (
	StatusPending  = "pending"
	StatusDone     = "done"
	StatusError    = "error"
	StatusNotFound = "not_found"
)

// Job est le contenu d'un fichier de job.
type Job struct {
	Status string `json:"status"`
	Result any    `json:"result"`
}

// Store stocke les jobs dans un dossier de fichiers JSON.
type Store struct {
	dir    string
	maxAge time.Duration
}

var jobIDPattern = regexp.MustCompile(`^[a-zA-Z0-9-]{1,64}$`)

// NewStore crée le dossier de jobs s'il n'existe pas.
func NewStore(dir string, maxAge time.Duration) (*Store, error) {
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return nil, err
	}
	return &Store{dir: dir, maxAge: maxAge}, nil
}

// Dir retourne le dossier des jobs.
func (s *Store) Dir() string {
	return s.dir
}

func (s *Store) path(jobID string) string {
	return filepath.Join(s.dir, jobID+".json")
}

// Save écrit le job de façon atomique (fichier temporaire + rename) pour
// qu'un lecteur ne voie jamais un JSON à moitié écrit.
func (s *Store) Save(jobID, status string, result any) error {
	if !jobIDPattern.MatchString(jobID) {
		return os.ErrInvalid
	}
	data, err := json.Marshal(Job{Status: status, Result: result})
	if err != nil {
		return err
	}
	target := s.path(jobID)
	tmp := target + ".tmp"
	if err := os.WriteFile(tmp, data, 0o644); err != nil {
		return err
	}
	return os.Rename(tmp, target)
}

// Load lit un job. Renvoie false si le fichier est absent ou illisible,
// ce que les endpoints traduisent en not_found (comme le Python).
func (s *Store) Load(jobID string) (Job, bool) {
	if !jobIDPattern.MatchString(jobID) {
		return Job{}, false
	}
	data, err := os.ReadFile(s.path(jobID))
	if err != nil {
		return Job{}, false
	}
	var job Job
	if err := json.Unmarshal(data, &job); err != nil {
		return Job{}, false
	}
	return job, true
}

// Delete supprime le fichier de job.
func (s *Store) Delete(jobID string) bool {
	if !jobIDPattern.MatchString(jobID) {
		return false
	}
	err := os.Remove(s.path(jobID))
	return err == nil
}

// Cleanup supprime les jobs plus vieux que maxAge (par défaut 300 s,
// identique à cleanup_old_jobs en Python).
func (s *Store) Cleanup() {
	entries, err := os.ReadDir(s.dir)
	if err != nil {
		return
	}
	cutoff := time.Now().Add(-s.maxAge)
	for _, entry := range entries {
		if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		info, err := entry.Info()
		if err != nil {
			continue
		}
		if info.ModTime().Before(cutoff) {
			os.Remove(filepath.Join(s.dir, entry.Name()))
		}
	}
}
