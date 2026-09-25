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
	if err := s.Save("job-p", StatusPending, nil); err != nil {
		t.Fatal(err)
	}
	job, ok := s.Load("job-p")
	if !ok {
		t.Fatal("job introuvable")
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

	if err := s.Save("old-job", StatusPending, nil); err != nil {
		t.Fatal(err)
	}
	if err := s.Save("new-job", StatusPending, nil); err != nil {
		t.Fatal(err)
	}

	old := time.Now().Add(-10 * time.Minute)
	if err := os.Chtimes(filepath.Join(s.Dir(), "old-job.json"), old, old); err != nil {
		t.Fatal(err)
	}

	s.Cleanup()

	if _, ok := s.Load("old-job"); ok {
		t.Error("le job de 10 minutes aurait dû être supprimé")
	}
	if _, ok := s.Load("new-job"); !ok {
		t.Error("le job récent ne doit pas être supprimé")
	}
}
