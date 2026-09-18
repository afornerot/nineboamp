---
purpose: user
variant: scoring
temperature: 0.2
max_tokens: 4096
---

Voici le marché à scorer :

IDWEB: {{idweb}}
Titre: {{title}}
Acheteur: {{buyer}}
Description: {{description}}
Montant: {{amount}}
Date limite: {{deadline}}

Données brutes BOAMP:
{{rawText}}

Catalogue produits de l'entreprise:
{{catalogue}}

## Format de réponse attendu

Réponds UNIQUEMENT avec ce JSON, sans texte avant ou après :
```json
{
  "score": <note 0-100>,
  "priority": "<A si score>=80, B si >=60, C sinon>",
  "explanation": "<3-5 phrases expliquant le score global, en citant des éléments factuels du marché>",
  "products": [
    {
      "name": "<nom du produit>",
      "score": <note 0-100>,
      "priority": "<A/B/C>",
      "relevance": "<2-3 phrases d'explication>",
      "scoreDetails": "<détail des sous-scores: fonctionnel/40, metier/20, technique/20, commercial/10, complexite/10>"
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
- Cite des éléments factuels du marché et des fiches.
