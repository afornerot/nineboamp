# BOAMP Finder

Processus d'interrogation des marchés publics français (BOAMP), de leur
détection jusqu'à leur qualification par scoring IA.

## Vue d'ensemble

```
[app:boamp:search] ──> BoampFinderService ──> BoampApiService ──> OpenDataSoft (BOAMP)
       │                     │                       │
       │                     │                       └── récupération détails marché
       │                     ├──> ProductMetadataExtractor ──> AiService (LLM)
       │                     │
       │                     └──> AiService (LLM scoring) ──> OpenDataSoft LLM
       │
       └── affiche le rapport (Trouvés / Qualifiés / Notifiés)
```

Le cron `app:boamp:search` (cf. `crons.md`) exécute ce pipeline une fois par
jour ; il peut aussi être lancé manuellement.

## Composants

| Composant | Rôle | Fichier |
|---|---|---|
| `BoampSearchCommand` | Commande CLI `app:boamp:search` | `src/Command/BoampSearchCommand.php` |
| `BoampFinderService` | Orchestration du run + scoring IA | `src/Service/BoampFinderService.php` |
| `BoampApiService` | Client HTTP OpenDataSoft (recherche + détails) | `src/Service/BoampApiService.php` |
| `ProductMetadataExtractor` | Génération des mots-clés et secteurs depuis les fiches produits | `src/Service/ProductMetadataExtractor.php` |
| `BoampReport` (entité) | Persistance du rapport d'exécution | `src/Entity/BoampReport.php` |
| `Market` (entité) | Marché détecté puis qualifié | `src/Entity/Market.php` |
| `MarketProduct` (entité) | Liaison marché ↔ produit matché | `src/Entity/MarketProduct.php` |
| `ScoringPrompt` (entité) | Prompts LLM éditables en BDD | `src/Entity/ScoringPrompt.php` |
| `PromptLoader` (service) | Charge les prompts depuis `.md` ou BDD | `src/Service/PromptLoader.php` |

## Requalification individuelle

Le bouton **« Requalifier »** sur la page d'un marché (`/user/market/{id}`)
relance le scoring LLM sur ce seul marché sans recrawler BOAMP.

Workflow :

1. Récupère les `rawData` déjà stockés sur le marché (JSON BOAMP)
2. Charge le catalogue produits
3. Supprime les anciens `MarketProduct`
4. Appelle `BoampFinderService::scoreAndQualify()` → met à jour score, priorité, produits, statut, `explanation`
5. Flash success/error et redirection sur la page show

Endpoint : `POST /user/market/{id}/rescore` (`app_market_rescore`).

Utile quand un prompt LLM a été modifié et qu'on veut redériver immédiatement le scoring sans attendre le prochain cron.

## Workflow en 5 étapes

L'exécution d'`app:boamp:search` enchaîne cinq étapes :

### 1. Collecte des produits

`productRepository->findAll()` charge tous les produits de la base. Si la
liste est vide, une `RuntimeException` est levée : *« Aucun produit en base.
Ajoutez des fiches produit avant de lancer la recherche. »*

### 2. Génération des mots-clés

Pour chaque produit, `ProductMetadataExtractor::extractMissing()` est appelé :

- Si le champ `keywords` est rempli manuellement, il est utilisé tel quel.
- Sinon, la fiche Markdown est analysée par un LLM (cf. `ai.md`) qui renvoie
  jusqu'à 10 mots-clés + 5 secteurs au format JSON strict.
- Le champ `sectors` est conservé comme métadonnée d'UI/score, mais n'est
  **pas** envoyé à l'API BOAMP (cette dernière ne sait exploiter que des
  codes départements).

### 3. Recherche OpenDataSoft

`BoampApiService::searchMarkets($keywords, 100, 'dateparution DESC')`
construit une clause `where` du type :

```
((objet LIKE '%mot1%' OR descripteur_libelle LIKE '%mot1%') OR …)
  AND datelimitereponse >= date'2026-09-14'
```

Les mots-clés sont échappés (`\`, `'`, `"`) avant interpolation pour
éviter les injections et les réponses vides silencieuses de l'API.

### 4. Filtre deadline

`filterCandidates()` retient uniquement les marchés dont la date limite de
réponse est ≥ `now() + 30 jours`. Les marchés expirés ou trop serrés sont
exclus du scoring.

### 5. Scoring IA

Pour chaque candidat retenu (max 30), un **deuxième appel API** (`getMarketDetails()`)
récupère la fiche complète du marché. Le champ `donnees` (JSON) contient les
informations détaillées utilisées pour le scoring :

- `OBJET.OBJET_COMPLET` → texte complet de l'annonce (description détaillée)
- `OBJET.CARACTERISTIQUES.QUANTITE` → spécifications
- `PROCEDURE.CRITERES_ATTRIBUTION.CRITERES_LIBRE` → critères d'attribution
- `PROCEDURE.CONDITION_PARTICIPATION.CAP_TECH` → capacité technique requise

Le champ `description` envoyé au LLM est extrait de `OBJET_COMPLET` (pas du
descripteur BOAMP qui n'est qu'une étiquette comme "Formation").

Ensuite, `BoampFinderService::scoreAndQualify()` appelle le LLM pour évaluer
la correspondance avec chaque produit du catalogue.

**Score par produit** : le LLM attribue à chaque produit un score de 0 à 100
(pondéré : adéquation fonctionnelle 40 %, métier 20 %, technique 20 %,
potentiel commercial 10 %, complexité 10 %). Un produit qui ne correspond pas
reçoit 0.

**Score global** : calculé en PHP *après* la réponse du LLM, selon la formule :

```
base  = meilleur score parmi tous les produits
bonus = Σ (score_produit - 50) × 0.15  pour chaque AUTRE produit avec score ≥ 50
score = min(100, round(base + bonus))
```

**Priorité** : `A` (score ≥ 80), `B` (60-79) ou `C` (< 60).

**Logique** : un marché n'a besoin de correspondre qu'à **un seul** produit
pour être pertinent. Si plusieurs produits matchent bien (score ≥ 50), le
score global augmente grâce au bonus multi-produits. Cela reflète la
visibilité du marché auprès de plusieurs services de l'entreprise.

Exemples :

| Produits | Calcul | Score |
|---|---|:---:|
| `[85]` | 85 + 0 | **85** |
| `[85, 72]` | 85 + (72-50)×0.15 = 88.3 | **88** |
| `[85, 72, 65]` | 85 + 3.3 + 2.25 | **90** |
| `[90, 88, 85]` | 90 + 5.7 + 5.25 | **100** (capped) |

Les résultats sont persistés en base (`market`, `market_product`).

## Limites et quotas

Constantes exposées dans `BoampFinderService` :

| Constante | Valeur | Rôle |
|---|---|---|
| `MAX_DETAILS` | 30 | Nombre maximum de candidats détaillés par run |
| `MIN_SCORE_NOTIFY` | 50 | Score minimum pour notification RocketChat (désactivée) |
| `MIN_DAYS_DEADLINE` | 30 | Délai minimum avant clôture pour qu'un marché soit qualifié |
| Bonus multi-produits | 0.15 | Coefficient de bonus par produit additionnel (score ≥ 50) |

Côté LLM, les quotas dépendent du provider configuré. Voir `ai.md`.

## Sortie et observabilité

### Sortie console

```
BOAMP SEARCH
============

 1/5 — Collecte des produits
   → 8 produit(s) chargé(s).

 2/5 — Génération des mots-clés
   → 50 mot(s)-clé(s) unique(s) généré(s) (max 50 transmis à l'API).
   → Échantillon : IA, Recherche, Souveraineté, Documentation, RGPD, ...

 3/5 — Recherche OpenDataSoft (BOAMP)
   → 100 résultat(s) brut(s) renvoyé(s) par l'API.
   → 35 candidat(s) retenu(s) après filtre deadline (≥ +30 jours).

4/5 — Scoring IA (max 30 marchés détaillés)
   → Scoring de 30 marché(s)…
 4/30 [============>-------------------]  13%  (1.05s / 7.50s) — 26-88644 scored 85/100 (priorité A)
  → 12 marché(s) qualifié(s), 18 ignoré(s).

 5/5 — Persistance du rapport
   → Rapport #128 persisté (durée 14.32s).

 Bilan
 =====

   Date d'exécution : 2026-09-14 09:00:00

  +------------------------------+--------+
  | Étape                        | Valeur |
  +------------------------------+--------+
  | Candidats (deadline ≥ +30j)  | 35     |
  | Marchés trouvés (boucle)     | 30     |
  | Marchés qualifiés (persistés)| 12     |
  | Marchés notifiés (RocketChat)| 0      |
  +------------------------------+--------+

 Marchés qualifiés (12)
 ======================

  +-----------+--------+-------+------------+-----------------------------+----------------------------+
  | IDWEB     | Prio.  | Score | Deadline   | Titre                       | Acheteur                   |
  +-----------+--------+-------+------------+-----------------------------+----------------------------+
  | 26-88644  | A      | 85    | 2026-12-31 | Audit et vérification...    | Ministère X...             |
  | ...                                                                                          |
  +-----------+--------+-------+------------+-----------------------------+----------------------------+

 [OK] 12 marché(s) qualifié(s) sur 35 candidat(s).
```

L'option `--no-table` masque le tableau détaillé des marchés qualifiés (utile
quand le run produit beaucoup de résultats et qu'on veut juste le bilan).

### Logs (dev) `var/log/dev.log`

Étapes tracées par `BoampFinderService` :

- `BoampFinderService: démarrage` — nombre de produits trouvés.
- `BoampFinderService: mots-clés générés` — liste échantillonnée des mots-clés.
- `BoampFinderService: résultats API bruts` — nombre de marchés renvoyés par l'API.
- `BoampFinderService: candidats après filtre deadline` — nombre après filtre deadline.

Erreurs possibles :

- `BoampApiService: erreur recherche` — échec HTTP OpenDataSoft.
- `ProductMetadataExtractor: réponse IA vide — vérifiez AI_PROVIDER/AI_MODEL/...` — IA indisponible.
- `AiService: erreur API LLM` — quota dépassé ou provider invalide.

### Persistance

Un enregistrement `BoampReport` est persisté à chaque run (succès ou
échec), consultable via l'interface admin ou en SQL.

## Dépannage

| Symptôme | Cause probable | Action |
|---|---|---|
| 0 marché trouvé, API 200 | Mots-clés trop restrictifs ou deadline +30j trop courte | Consulter les logs `mots-clés générés` et `candidats après filtre deadline`. Réduire `MIN_DAYS_DEADLINE` ou enrichir les fiches produits. |
| 0 marché, erreur API HTTP | Provider LLM KO (quota dépassé), URL incorrecte | Vérifier `var/log/dev.log` pour `AiService: erreur API LLM` et corriger `AI_MODEL`/`AI_API_KEY` dans `.env.local`. |
| Mots-clés = `[$name]` au lieu de LLM | IA KO ou fiche non lue | Vérifier la longueur de la fiche et la configuration LLM. Le warning *« réponse IA vide »* doit apparaître dans les logs. |
| Marchés non persistés | Échec silencieux sur persistance | Examiner `var/log/dev.log` autour de la date d'exécution ; vérifier que les entités `Market` et `MarketProduct` sont flushées. |

## Commandes utiles

```bash
# Lancement manuel du run
bin/console app:boamp:search

# Diagnostic LLM pour les mots-clés
bin/console app:dev:probe-metadata

# Dernier rapport persisté
bin/console doctrine:query:sql "SELECT * FROM boamp_report ORDER BY id DESC LIMIT 5"

# Marchés qualifiés (score >= 60)
bin/console doctrine:query:sql "SELECT idweb, title, score, priority FROM market WHERE score >= 60 ORDER BY score DESC LIMIT 20"
```

## Configuration des produits

- Les fiches produits de démo sont placées dans
  `src/DataFixtures/data/products/*.md`.
- À la création d'un produit (sans `keywords` saisi), `submit()` appelle
  l'extracteur pour pré-remplir `keywords` et `sectors`.
- L'utilisateur peut éditer manuellement les champs, auquel cas l'IA ne
  les écrasera pas (cf. `testManualKeywordsPreservedSectorsExtracted`).
- Le champ `sectors` reste local : il n'influence pas la requête BOAMP,
  il sert à l'UI et au score IA en sortie.

## Liens connexes

- [`crons.md`](crons.md) — déclenchement du cron `app:boamp:search`.
- [`ai.md`](ai.md) — configuration du provider LLM.
- [`fixtures.md`](fixtures.md) — chargement initial des fiches produits.
