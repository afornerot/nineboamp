// Package server expose le contrat HTTP de l'agent (parité avec FastAPI) :
// POST /chat|/score|/report → {job_id}, GET …/result/{id} → {status, result}.
package server

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"time"

	"github.com/google/uuid"
	"nineboamp/agent-go/internal/flow"
	"nineboamp/agent-go/internal/jobs"
)

// Runner est l'exécuteur des workflows (implémenté par flow.Service).
type Runner interface {
	Chat(ctx context.Context, req flow.ChatRequest) (string, any)
	Score(ctx context.Context, req flow.ScoreRequest) (string, any)
	Report(ctx context.Context, req flow.ReportRequest) (string, any)
}

// Config configure le serveur.
type Config struct {
	Jobs       *jobs.Store
	Runner     Runner
	JobTimeout time.Duration
	Logger     *slog.Logger
}

type handler struct {
	jobs       *jobs.Store
	runner     Runner
	jobTimeout time.Duration
	logger     *slog.Logger
}

// New construit le handler HTTP complet (routes + CORS + recovery).
func New(cfg Config) http.Handler {
	logger := cfg.Logger
	if logger == nil {
		logger = slog.Default()
	}
	jobTimeout := cfg.JobTimeout
	if jobTimeout == 0 {
		jobTimeout = 10 * time.Minute
	}
	h := &handler{
		jobs:       cfg.Jobs,
		runner:     cfg.Runner,
		jobTimeout: jobTimeout,
		logger:     logger,
	}

	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", h.health)
	mux.HandleFunc("POST /chat", h.startChat)
	mux.HandleFunc("GET /chat/result/{jobID}", h.result)
	mux.HandleFunc("DELETE /chat/{jobID}", h.deleteJob)
	mux.HandleFunc("POST /score", h.startScore)
	mux.HandleFunc("GET /score/result/{jobID}", h.result)
	mux.HandleFunc("POST /report", h.startReport)
	mux.HandleFunc("GET /report/result/{jobID}", h.result)

	return withRecovery(withCORS(mux), logger)
}

func (h *handler) health(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "service": "agent"})
}

func (h *handler) startChat(w http.ResponseWriter, r *http.Request) {
	var req flow.ChatRequest
	if !decodeBody(w, r, &req) {
		return
	}
	h.start(w, r, "chat", func(ctx context.Context, jobID string) (string, any) {
		return h.runner.Chat(ctx, req)
	})
}

func (h *handler) startScore(w http.ResponseWriter, r *http.Request) {
	var req flow.ScoreRequest
	if !decodeBody(w, r, &req) {
		return
	}
	h.start(w, r, "score", func(ctx context.Context, jobID string) (string, any) {
		return h.runner.Score(ctx, req)
	})
}

func (h *handler) startReport(w http.ResponseWriter, r *http.Request) {
	var req flow.ReportRequest
	if !decodeBody(w, r, &req) {
		return
	}
	h.start(w, r, "report", func(ctx context.Context, jobID string) (string, any) {
		return h.runner.Report(ctx, req)
	})
}

// start crée le job (pending), lance le workflow en goroutine et renvoie
// immédiatement {job_id} — exactement comme le subprocess Python.
func (h *handler) start(w http.ResponseWriter, r *http.Request, kind string, run func(ctx context.Context, jobID string) (string, any)) {
	h.jobs.Cleanup()

	jobID := uuid.New().String()
	if err := h.jobs.Save(jobID, jobs.StatusPending, nil); err != nil {
		h.logger.Error("création du job impossible", "kind", kind, "error", err)
		writeJSON(w, http.StatusInternalServerError, map[string]any{"error": "impossible de créer le job"})
		return
	}

	ctx, cancel := context.WithTimeout(context.WithoutCancel(r.Context()), h.jobTimeout)
	go func() {
		defer cancel()
		defer func() {
			if rec := recover(); rec != nil {
				h.logger.Error("panic dans le workflow", "kind", kind, "job_id", jobID, "panic", rec)
				h.jobs.Save(jobID, jobs.StatusError, map[string]any{"error": "Erreur interne"})
			}
		}()

		start := time.Now()
		status, result := run(ctx, jobID)
		if err := h.jobs.Save(jobID, status, result); err != nil {
			h.logger.Error("écriture du résultat impossible", "kind", kind, "job_id", jobID, "error", err)
			return
		}
		h.logger.Info("job terminé", "kind", kind, "job_id", jobID, "status", status, "duration", time.Since(start).Round(time.Millisecond))
	}()

	writeJSON(w, http.StatusOK, map[string]any{"job_id": jobID})
}

// result lit un job ; absent/invalide → not_found (comme load_job → None).
func (h *handler) result(w http.ResponseWriter, r *http.Request) {
	jobID := r.PathValue("jobID")
	job, ok := h.jobs.Load(jobID)
	if !ok {
		writeJSON(w, http.StatusOK, jobs.Job{Status: jobs.StatusNotFound, Result: nil})
		return
	}
	writeJSON(w, http.StatusOK, job)
}

func (h *handler) deleteJob(w http.ResponseWriter, r *http.Request) {
	h.jobs.Delete(r.PathValue("jobID"))
	writeJSON(w, http.StatusOK, map[string]any{"deleted": true})
}

func decodeBody(w http.ResponseWriter, r *http.Request, dst any) bool {
	if err := json.NewDecoder(r.Body).Decode(dst); err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"detail": []map[string]any{{"msg": err.Error(), "type": "value_error"}},
		})
		return false
	}
	return true
}

func writeJSON(w http.ResponseWriter, status int, payload any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(payload)
}

func withCORS(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Credentials", "true")
		w.Header().Set("Access-Control-Allow-Methods", "*")
		w.Header().Set("Access-Control-Allow-Headers", "*")
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		next.ServeHTTP(w, r)
	})
}

func withRecovery(next http.Handler, logger *slog.Logger) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		defer func() {
			if rec := recover(); rec != nil {
				logger.Error("panic HTTP", "panic", rec, "path", r.URL.Path)
				writeJSON(w, http.StatusInternalServerError, map[string]any{"error": "internal server error"})
			}
		}()
		next.ServeHTTP(w, r)
	})
}
