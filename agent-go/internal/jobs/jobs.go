// Package jobs gère le stockage des jobs asynchrones en fichiers JSON,
// avec la même sémantique que l'agent Python : statuts pending/done/error,
// expiration par âge maximum (300 s par défaut → not_found).
//
// Un job pending est un job que ce processus exécute : il n'expire pas tant
// qu'il tourne. Un seul processus doit donc utiliser un dossier de jobs.
package jobs

import (
	"encoding/json"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"
)

// Statuts de job (parité avec l'agent Python).
const (
	StatusPending  = "pending"
	StatusDone     = "done"
	StatusError    = "error"
	StatusNotFound = "not_found"
)

// OrphanError est l'erreur d'un job pending que plus aucun processus n'exécute.
const OrphanError = "job interrompu : l'agent a redémarré pendant son exécution"

// Job est le contenu d'un fichier de job.
type Job struct {
	Status string `json:"status"`
	Result any    `json:"result"`
}

// Store stocke les jobs dans un dossier de fichiers JSON.
type Store struct {
	dir    string
	maxAge time.Duration

	mu sync.Mutex
	// running contient les jobs démarrés par ce processus et pas encore finis.
	running map[string]struct{}
}

var jobIDPattern = regexp.MustCompile(`^[a-zA-Z0-9-]{1,64}$`)

// NewStore crée le dossier de jobs s'il n'existe pas.
func NewStore(dir string, maxAge time.Duration) (*Store, error) {
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return nil, err
	}
	return &Store{dir: dir, maxAge: maxAge, running: map[string]struct{}{}}, nil
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

// Start enregistre un job pending que ce processus exécute jusqu'à Finish :
// Cleanup ne le supprime pas, même au-delà de maxAge.
func (s *Store) Start(jobID string) error {
	s.setRunning(jobID, true)
	if err := s.Save(jobID, StatusPending, nil); err != nil {
		s.setRunning(jobID, false)
		return err
	}
	return nil
}

// Finish écrit le statut final d'un job démarré par Start. Le job redevient
// alors soumis à l'expiration.
func (s *Store) Finish(jobID, status string, result any) error {
	// Écrire avant de retirer le job des jobs en cours : entre les deux, Load
	// le prendrait pour un orphelin.
	defer s.setRunning(jobID, false)
	return s.Save(jobID, status, result)
}

func (s *Store) setRunning(jobID string, running bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if running {
		s.running[jobID] = struct{}{}
	} else {
		delete(s.running, jobID)
	}
}

func (s *Store) isRunning(jobID string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	_, ok := s.running[jobID]
	return ok
}

// Load lit un job. Renvoie false si le fichier est absent ou illisible,
// ce que les endpoints traduisent en not_found (comme le Python).
//
// Un job pending que ce processus n'exécute pas a été interrompu (agent
// redémarré pendant le job) : il est rendu en erreur, sinon le client
// attendrait un statut final qui ne viendra jamais.
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
	if job.Status == StatusPending && !s.isRunning(jobID) {
		return Job{Status: StatusError, Result: map[string]any{"error": OrphanError}}, true
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
// identique à cleanup_old_jobs en Python), sauf ceux en cours : un job peut
// durer plus que maxAge, et son client le croirait perdu.
func (s *Store) Cleanup() {
	entries, err := os.ReadDir(s.dir)
	if err != nil {
		return
	}
	cutoff := time.Now().Add(-s.maxAge)
	for _, entry := range entries {
		jobID, isJob := strings.CutSuffix(entry.Name(), ".json")
		if entry.IsDir() || !isJob || s.isRunning(jobID) {
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
