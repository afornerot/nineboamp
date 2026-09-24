---
purpose: user
variant: report
temperature: 0.4
max_tokens: 8000
---

Voici le marché à analyser en vue d'un rapport de positionnement commercial.

## Métadonnées du marché

{{metadata}}

## Description du marché

{{description}}

## Produits associés (top 3 par pertinence, issus du scoring)

{{products}}

## Catalogue produits Cadoles (référence complète)

{{catalogue}}

## Historique du chat pertinent (uniquement les messages marqués comme importants)

{{chatHistory}}

---

## Mission

Produis le rapport de positionnement commercial complet, en respectant **strictement** le plan en 8 sections défini dans tes instructions système.

Workflow recommandé :
1. Si tu as besoin de détails techniques sur le marché, utilise `list_documents` puis `amoxtli_search` sur les sections CCTP/BPU/CCAP pertinentes.
2. Si tu dois confirmer les produits Cadoles à pousser, utilise `get_product_info(ID)` avec les IDs exacts.
3. Si l'historique de chat pertinent contient des points saillants, intègre-les dans les sections 03, 05 et 08.
4. Génère le rapport en Markdown brut, sans wrapper, en suivant l'ordre `## 01` → `## 08` sans rien omettre.

## Contraintes

- **Markdown uniquement.** Pas de HTML, pas de bloc ```markdown, pas d'explication avant/après le rapport.
- **Pas de PDF.** Le rendu PDF est appliqué en aval par l'application.
- **Aucun méta-commentaire.** Le rapport commence directement par `## 01 De quoi on parle ?`.
- Si une information manque, écris-le explicitement (« Non communiqué dans l'avis. », « Estimation non disponible. ») au lieu d'inventer.
- Cite les sources du marché (numéro de lot, référence article CCTP) quand tu fais une affirmation technique précise, entre parenthèses, en italique.
