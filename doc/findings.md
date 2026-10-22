# Constats hors périmètre

Problèmes relevés en marge de la revue de `fix/scoring-delai-agent` (délai annoncé par l'agent pour le scoring), mais qui ne relèvent pas de cette branche. Chacun mérite sa propre branche.

## 1. Un rapport long est marqué en erreur alors que l'agent travaille encore

**Où :** `agent-go/internal/jobs/jobs.go` (`Cleanup`), `src/Controller/MarketReportController.php` (`poll`).

À chaque création de job (`POST /chat`, `/score`, `/report`), l'agent appelle `Cleanup()`. Ce dernier supprime tout fichier de job plus vieux que `-job-max-age` (300 s par défaut) selon sa date de modification, **y compris les jobs encore `pending`**. Or un job de chat ou de rapport peut durer jusqu'à `-job-timeout` (600 s).

Scénario : un rapport tourne depuis plus de 300 s et un autre utilisateur lance un chat. Le fichier du rapport est supprimé et le poll suivant reçoit `not_found`. `MarketReportController::poll` traite alors `not_found` comme `error` et enregistre le rapport en erreur avec « Erreur inconnue ». Quand l'agent écrit enfin le rapport, plus personne ne le lit.

Le chat n'est pas touché : sur `not_found`, le JS continue de poller, et `jobs.Save` recrée le fichier à la fin du job. Le scoring non plus, tant que son délai (165 s par défaut) reste sous 300 s.

**Pistes :**
- faire ignorer les jobs `pending` à `Cleanup()`, puisque le délai du job garantit qu'ils passeront à un statut final ;
- ou refuser au démarrage un `-job-max-age` inférieur au plus long délai de job.

## 2. Code mort : `AgentService::chat()` et son délai de 120 s

**Où :** `src/Service/AgentService.php` (`chat`, `waitForResult`, `TIMEOUT_POLL = 120`).

`chat()` démarre un job puis attend son résultat au plus 120 s, alors qu'un job de chat peut durer 600 s. Ces méthodes n'ont cependant **aucun appelant** : `MarketChatController` utilise `startChat()` puis `getResult()`, et c'est le JS qui interroge l'agent, sans limite de temps.

Le délai incohérent n'a donc pas d'effet aujourd'hui, mais il en aura un dès que quelqu'un réutilisera `chat()`. Il faut soit supprimer `chat()` et `waitForResult()`, soit les aligner sur le délai renvoyé par l'agent (`timeout` dans la réponse du `POST`, ajouté par `fix/scoring-delai-agent`).

## 3. Le polling des jobs de l'agent est dupliqué et ses copies ont divergé

**Où :**

| Copie | Fichier | Sur `not_found` | Sur `error` |
|-------|---------|-----------------|-------------|
| Scoring (cron) | `src/Service/ScoringAgentService.php` | échec immédiat | échec avec la raison |
| Rescore (UI) | `src/Controller/MarketController.php` + JS `pollRescore` | poll à nouveau | message à l'utilisateur |
| Chat (UI) | `src/Service/AgentService.php` + JS `pollForResult` | poll à nouveau, sans limite | message à l'utilisateur |
| Rapport (UI) | `src/Service/MarketReportService.php` + `MarketReportController::poll` | rapport en erreur | rapport en erreur |

Chaque client refait la même chose : `GET /{kind}/result/{id}` avec un timeout de 10 s, puis l'aiguillage sur le statut. Les comportements ont divergé : traitement de `not_found`, codes HTTP renvoyés au JS et prise en compte (ou non) du délai annoncé par l'agent.

**Piste :** un client unique, par exemple `AgentJobClient`, qui prend le type de job et le délai annoncé, et rend un résultat typé (`done` / `error` avec raison / `not_found` / `timeout`). Les contrôleurs n'auraient plus qu'à traduire ce résultat pour le JS. Le constat n° 1 deviendrait alors un seul choix explicite au lieu de quatre.
