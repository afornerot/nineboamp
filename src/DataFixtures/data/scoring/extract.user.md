---
purpose: user
variant: extract
temperature: 0.0
max_tokens: 2048
---

Tâche : extraire les éléments suivants depuis cette fiche produit.
{{tasks}}

FORMAT DE RÉPONSE OBLIGATOIRE : un objet JSON strict, sans markdown, sans texte avant/après.
Schéma attendu : {{schema}}

Fiche produit :
----DEBUT----
{{fiche}}
----FIN----
