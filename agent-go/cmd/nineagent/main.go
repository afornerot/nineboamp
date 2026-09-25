// nineagent est le service d'agent IA de nineboamp, construit sur la
// bibliothèque github.com/Bornholm/genai. Il écoute sur 127.0.0.1:8000 —
// le port historiquement utilisé par l'agent Python, qui est désormais relégué
// sur 127.0.0.1:8001 — avec le même contrat HTTP.
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/bornholm/genai/llm/provider"
	_ "github.com/bornholm/genai/llm/provider/all"
	"github.com/bornholm/genai/llm/provider/env"
	"github.com/bornholm/genai/llm/retry"
	"nineboamp/agent-go/internal/config"
	"nineboamp/agent-go/internal/flow"
	"nineboamp/agent-go/internal/jobs"
	"nineboamp/agent-go/internal/prompts"
	"nineboamp/agent-go/internal/server"
	"nineboamp/agent-go/internal/tools"
)

func main() {
	var (
		addr              = flag.String("addr", "127.0.0.1:8000", "adresse d'écoute HTTP")
		envDir            = flag.String("env-dir", ".", "dossier contenant .env.local puis .env")
		promptsDir        = flag.String("prompts-dir", "", "dossier des prompts markdown (défaut: auto)")
		jobsDir           = flag.String("jobs-dir", "/tmp/jobs-go", "dossier des fichiers de job")
		jobMaxAge         = flag.Duration("job-max-age", 300*time.Second, "âge maximal d'un job")
		jobTimeout        = flag.Duration("job-timeout", 10*time.Minute, "timeout global d'un job")
		apiBase           = flag.String("api-base", "http://127.0.0.1", "base URL de l'API PHP (outils produits)")
		amoxtliBin        = flag.String("amoxtli-bin", "/usr/local/bin/amoxtli", "binaire amoxtli")
		amoxtliDir        = flag.String("amoxtli-dir", "/app/uploads/amoxtli", "dossier des workspaces amoxtli")
		maxIterations     = flag.Int("max-iterations", 10, "itérations max de la boucle d'outils")
		callTimeout       = flag.Duration("call-timeout", 120*time.Second, "timeout d'un appel LLM (chat/score)")
		reportCallTimeout = flag.Duration("report-call-timeout", 180*time.Second, "timeout d'un appel LLM (rapport)")
		debug             = flag.Bool("debug", false, "logs debug")
	)
	flag.Parse()

	level := slog.LevelInfo
	if *debug {
		level = slog.LevelDebug
	}
	logger := slog.New(slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{Level: level}))
	slog.SetDefault(logger)

	// Environnement : .env.local > .env, puis mappage AI_* -> GENAI_*.
	if err := config.LoadEnvFiles(*envDir); err != nil {
		logger.Error("chargement de l'environnement impossible", "error", err)
		os.Exit(1)
	}
	config.MapToGenAI()

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()

	// Client LLM genai (provider OpenAI-compatible = xolo).
	client, err := provider.Create(ctx, env.With("GENAI_"))
	if err != nil {
		logger.Error("création du client LLM impossible", "error", err)
		os.Exit(1)
	}
	client = retry.NewClient(client, time.Second, 3)

	p := prompts.New(prompts.ResolveDir(*promptsDir))
	toolSet := tools.New(tools.Config{
		MCPSecret:  config.Get("MCP_SECRET"),
		APIBase:    *apiBase,
		AmoxtliBin: *amoxtliBin,
		AmoxtliDir: *amoxtliDir,
	})

	svc := flow.New(client, p, toolSet.All(), flow.Config{
		MaxIterations:     *maxIterations,
		CallTimeout:       *callTimeout,
		ReportCallTimeout: *reportCallTimeout,
	})

	store, err := jobs.NewStore(*jobsDir, *jobMaxAge)
	if err != nil {
		logger.Error("création du dossier de jobs impossible", "dir", *jobsDir, "error", err)
		os.Exit(1)
	}

	httpServer := &http.Server{
		Addr: *addr,
		Handler: server.New(server.Config{
			Jobs:       store,
			Runner:     svc,
			JobTimeout: *jobTimeout,
			Logger:     logger,
		}),
		ReadHeaderTimeout: 10 * time.Second,
	}

	go func() {
		<-ctx.Done()
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		httpServer.Shutdown(shutdownCtx)
	}()

	logger.Info("nineagent démarré",
		"addr", *addr,
		"model", config.Get("GENAI_CHAT_COMPLETION_OPENAI_MODEL"),
		"base_url", config.Get("GENAI_CHAT_COMPLETION_OPENAI_BASE_URL"),
		"prompts", p.Dir(),
		"jobs", store.Dir(),
	)

	if err := httpServer.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	logger.Info("nineagent arrêté")
}
