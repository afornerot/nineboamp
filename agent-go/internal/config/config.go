// Package config charge l'environnement (.env.local > .env) et le propage
// vers les variables d'environnement du SDK genai.
package config

import (
	"bufio"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// LoadEnvFiles charge .env.local puis .env, dans cet ordre, en n'écrasant
// jamais une variable déjà présente dans l'environnement — mêmes règles que
// l'agent Python (main.py).
func LoadEnvFiles(dir string) error {
	for _, name := range []string{".env.local", ".env"} {
		if err := loadEnvFile(filepath.Join(dir, name)); err != nil {
			return fmt.Errorf("%s: %w", name, err)
		}
	}
	return nil
}

func loadEnvFile(path string) error {
	f, err := os.Open(path)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}
	defer f.Close()

	scanner := bufio.NewScanner(f)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		key, value, found := strings.Cut(line, "=")
		if !found {
			continue
		}
		key = strings.TrimSpace(key)
		value = strings.Trim(strings.TrimSpace(value), `"'`)
		if _, exists := os.LookupEnv(key); !exists {
			os.Setenv(key, value)
		}
	}
	return scanner.Err()
}

// MapToGenAI propage la configuration AI_* (lue aussi par PHP) vers les
// variables du SDK genai. Le provider xolo expose une API compatible OpenAI :
// provider "openai" avec BASE_URL personnalisée.
func MapToGenAI() {
	setIfAbsent("GENAI_CHAT_COMPLETION_PROVIDER", "openai")
	setIfAbsent("GENAI_CHAT_COMPLETION_OPENAI_MODEL", os.Getenv("AI_MODEL"))
	setIfAbsent("GENAI_CHAT_COMPLETION_OPENAI_API_KEY", os.Getenv("AI_API_KEY"))
	setIfAbsent("GENAI_CHAT_COMPLETION_OPENAI_BASE_URL", os.Getenv("AI_BASE_URL"))
}

func setIfAbsent(key, value string) {
	if value == "" {
		return
	}
	if _, exists := os.LookupEnv(key); !exists {
		os.Setenv(key, value)
	}
}

// Get retourne la valeur d'une variable d'environnement.
func Get(key string) string {
	return os.Getenv(key)
}
