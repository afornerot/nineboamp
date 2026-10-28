---
purpose: user
variant: scoring
temperature: 0.2
max_tokens: 8192
---

Voici le marché à scorer :

IDWEB: {{idweb}}
Titre: {{title}}
Acheteur: {{buyer}}
Description: {{description}}
Montant: {{amount}}
Date limite: {{deadline}}

Catalogue produits de l'entreprise :
{{catalogue}}

## Format de réponse attendu

Réponds UNIQUEMENT avec ce JSON, sans texte avant ou après :
```json
{
  "score": <note 0-100>,
  "priority": "<A si score>=80, B si >=60, C sinon>",
  "explanation": "<3 phrases maximum, 400 caractères maximum, sur le score global>",
  "products": [
    {
      "id": <ID du produit, repris tel quel du catalogue>,
      "name": "<nom du produit>",
      "score": <note 0-100>,
      "priority": "<A/B/C>",
      "relevance": "<2 phrases maximum, 300 caractères maximum>",
      "scoreDetails": "<une seule ligne, cinq sous-scores séparés par des virgules, ex: fonctionnel 40/40, métier 19/20, technique 18/20, commercial 8/10, complexité 9/10 — aucun commentaire>"
    }
  ]
}
```

## Règles

- **Ne utilise pas d'outils** - réponds directement avec le JSON.
- Score en te basant UNIQUEMENT sur les informations fournies dans ce prompt. Si une information n'est pas disponible, fais une estimation raisonnable.
- ATTENTION : le champ "Description" contient le texte complet et détaillé de l'annonce. Le titre seul ne suffit pas. Lis attentivement ce texte pour évaluer la correspondance réelle avec chaque produit.
- Par exemple, "Formation" comme descripteur BOAMP ne signifie pas que le marché concerne la formation en général — lis le texte complet pour comprendre le domaine exact.
- Le score global correspond au MEILLEUR score parmi les produits + un bonus si plusieurs produits correspondent (score ≥ 50).
- Un marché a besoin de correspondre qu'à UN seul produit pour être pertinent.
- Le score final est plafonné à 100.
- Si un produit ne matche pas, score=0 pour CE produit uniquement.
- Information non disponible = neutre.
- Cite des éléments factuels du marché dans "relevance" (2 phrases) et "explanation" (3 phrases) : jamais dans "scoreDetails".
- "scoreDetails" ne contient QUE les cinq sous-scores, sur une ligne, sans justification ni commentaire.
- Pour les produits dont le score est 0 : renvoie seulement {id, name, score: 0, priority: "C"}, sans texte.
- Reprends EXACTEMENT l'ID et le nom de chaque produit depuis le catalogue : n'invente ni produit ni identifiant.
