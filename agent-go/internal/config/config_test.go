package config

import (
	"os"
	"path/filepath"
	"testing"
)

func TestLoadEnvFilesPrecedence(t *testing.T) {
	dir := t.TempDir()

	write := func(name, content string) {
		t.Helper()
		if err := os.WriteFile(filepath.Join(dir, name), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	write(".env.local", "NINE_TEST_A=from-local\nNINE_TEST_B=from-local\n")
	write(".env", "NINE_TEST_A=from-env\nNINE_TEST_C=from-env\n")

	// Une variable déjà présente ne doit pas être écrasée.
	t.Setenv("NINE_TEST_B", "already-set")

	for _, key := range []string{"NINE_TEST_A", "NINE_TEST_C"} {
		os.Unsetenv(key)
		t.Cleanup(func() { os.Unsetenv(key) })
	}

	if err := LoadEnvFiles(dir); err != nil {
		t.Fatalf("LoadEnvFiles: %v", err)
	}

	if got := os.Getenv("NINE_TEST_A"); got != "from-local" {
		t.Errorf("NINE_TEST_A = %q, attendu from-local (.env.local gagne)", got)
	}
	if got := os.Getenv("NINE_TEST_B"); got != "already-set" {
		t.Errorf("NINE_TEST_B = %q, attendu already-set (env existante préservée)", got)
	}
	if got := os.Getenv("NINE_TEST_C"); got != "from-env" {
		t.Errorf("NINE_TEST_C = %q, attendu from-env", got)
	}
}

func TestLoadEnvFilesMissingDirIsNoop(t *testing.T) {
	if err := LoadEnvFiles(filepath.Join(t.TempDir(), "does-not-exist")); err != nil {
		t.Errorf("un dossier absent ne doit pas être une erreur: %v", err)
	}
}

func TestMapToGenAI(t *testing.T) {
	t.Setenv("AI_MODEL", "mon-modele")
	t.Setenv("AI_API_KEY", "ma-cle")
	t.Setenv("AI_BASE_URL", "https://xolo.example/api/v1")
	for _, key := range []string{
		"GENAI_CHAT_COMPLETION_PROVIDER",
		"GENAI_CHAT_COMPLETION_OPENAI_MODEL",
		"GENAI_CHAT_COMPLETION_OPENAI_API_KEY",
		"GENAI_CHAT_COMPLETION_OPENAI_BASE_URL",
	} {
		os.Unsetenv(key)
		t.Cleanup(func() { os.Unsetenv(key) })
	}

	MapToGenAI()

	expected := map[string]string{
		"GENAI_CHAT_COMPLETION_PROVIDER":        "openai",
		"GENAI_CHAT_COMPLETION_OPENAI_MODEL":    "mon-modele",
		"GENAI_CHAT_COMPLETION_OPENAI_API_KEY":  "ma-cle",
		"GENAI_CHAT_COMPLETION_OPENAI_BASE_URL": "https://xolo.example/api/v1",
	}
	for key, want := range expected {
		if got := os.Getenv(key); got != want {
			t.Errorf("%s = %q, attendu %q", key, got, want)
		}
	}
}
