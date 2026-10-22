package jobs

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func newTestStore(t *testing.T, maxAge time.Duration) *Store {
	t.Helper()
	s, err := NewStore(t.TempDir(), maxAge)
	if err != nil {
		t.Fatal(err)
	}
	return s
}

func TestSaveLoadRoundtrip(t *testing.T) {
	s := newTestStore(t, time.Minute)

	if err := s.Save("job-1", StatusDone, map[string]any{"answer": "bonjour"}); err != nil {
		t.Fatalf("Save: %v", err)
	}

	job, ok := s.Load("job-1")
	if !ok {
		t.Fatal("Load: job introuvable")
	}
	if job.Status != StatusDone {
		t.Errorf("status = %q", job.Status)
	}
	result, ok := job.Result.(map[string]any)
	if !ok {
		t.Fatalf("result type = %T", job.Result)
	}
	if result["answer"] != "bonjour" {
		t.Errorf("answer = %v", result["answer"])
	}
}

func TestLoadPendingNilResult(t *testing.T) {
	s := newTestStore(t, time.Minute)
	if err := s.Start("job-p"); err != nil {
		t.Fatal(err)
	}
	job, ok := s.Load("job-p")
	if !ok {
		t.Fatal("job introuvable")
	}
	if job.Status != StatusPending {
		t.Errorf("status = %q", job.Status)
	}
	if job.Result != nil {
		t.Errorf("result = %v, attendu nil (→ JSON null)", job.Result)
	}
}

func TestLoadMissingOrInvalid(t *testing.T) {
	s := newTestStore(t, time.Minute)

	if _, ok := s.Load("absent"); ok {
		t.Error("un job absent doit renvoyer false (not_found)")
	}
	if _, ok := s.Load("../../etc/passwd"); ok {
		t.Error("un identifiant invalide doit renvoyer false")
	}

	// Fichier corrompu → not_found (comme JSONDecodeError en Python).
	if err := os.WriteFile(filepath.Join(s.Dir(), "corrupt.json"), []byte("{oops"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, ok := s.Load("corrupt"); ok {
		t.Error("un JSON corrompu doit renvoyer false")
	}
}

func TestDelete(t *testing.T) {
	s := newTestStore(t, time.Minute)
	if err := s.Save("job-d", StatusPending, nil); err != nil {
		t.Fatal(err)
	}
	if !s.Delete("job-d") {
		t.Error("Delete devrait renvoyer true")
	}
	if _, ok := s.Load("job-d"); ok {
		t.Error("le job devrait avoir été supprimé")
	}
	if s.Delete("jamais-la") {
		t.Error("Delete d'un job absent devrait renvoyer false")
	}
}

func TestCleanupRemovesOldJobsOnly(t *testing.T) {
	s := newTestStore(t, 300*time.Second)

	if err := s.Save("old-job", StatusDone, nil); err != nil {
		t.Fatal(err)
	}
	if err := s.Save("new-job", StatusDone, nil); err != nil {
		t.Fatal(err)
	}
	age(t, s, "old-job")

	s.Cleanup()

	if _, ok := s.Load("old-job"); ok {
		t.Error("le job de 10 minutes aurait dû être supprimé")
	}
	if _, ok := s.Load("new-job"); !ok {
		t.Error("le job récent ne doit pas être supprimé")
	}
}

func TestCleanupKeepsRunningJobs(t *testing.T) {
	s := newTestStore(t, 300*time.Second)

	// Un rapport peut durer jusqu'à job-timeout (600 s), au-delà de maxAge.
	if err := s.Start("long-job"); err != nil {
		t.Fatal(err)
	}
	age(t, s, "long-job")
	s.Cleanup()

	job, ok := s.Load("long-job")
	if !ok || job.Status != StatusPending {
		t.Fatalf("un job en cours ne doit pas être supprimé : %v, %v", job, ok)
	}

	// Fini, il expire comme les autres.
	if err := s.Finish("long-job", StatusDone, nil); err != nil {
		t.Fatal(err)
	}
	age(t, s, "long-job")
	s.Cleanup()

	if _, ok := s.Load("long-job"); ok {
		t.Error("un job fini plus vieux que maxAge aurait dû être supprimé")
	}
}

func TestOrphanPendingJob(t *testing.T) {
	previous := newTestStore(t, 300*time.Second)
	if err := previous.Start("orphan"); err != nil {
		t.Fatal(err)
	}

	// L'agent redémarre pendant le job : le nouveau processus n'exécute pas
	// ce job, qui ne passera jamais à un statut final.
	s, err := NewStore(previous.Dir(), 300*time.Second)
	if err != nil {
		t.Fatal(err)
	}

	job, ok := s.Load("orphan")
	if !ok || job.Status != StatusError {
		t.Fatalf("un job orphelin doit être rendu en erreur : %v, %v", job, ok)
	}
	if job.Result.(map[string]any)["error"] != OrphanError {
		t.Errorf("result = %v", job.Result)
	}

	age(t, s, "orphan")
	s.Cleanup()
	if _, ok := s.Load("orphan"); ok {
		t.Error("un job orphelin plus vieux que maxAge aurait dû être supprimé")
	}
}

// age vieillit le fichier du job de 10 minutes.
func age(t *testing.T, s *Store, jobID string) {
	t.Helper()
	old := time.Now().Add(-10 * time.Minute)
	if err := os.Chtimes(filepath.Join(s.Dir(), jobID+".json"), old, old); err != nil {
		t.Fatal(err)
	}
}
