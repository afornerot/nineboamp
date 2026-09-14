# API

## Swagger

Documentation Swagger : http://localhost:8001/v1/api/doc

## Authentification API

Header `X-API-SECRET` (valeur de `APP_SECRET`).

## MCP

Endpoint MCP : http://localhost:8001/mcp

Authentification : Bearer token dans le header `Authorization`
(`Bearer MCP_SECRET` à chaque requête, `stateless: true` — aucune session).

Un client sans header valide reçoit un **401** JSON. L'endpoint est
machine-to-machine : il ne peut pas suivre un flow de login interactif
(CAS/OIDC), d'où un firewall séparé du flux principal. Voir
[doc/authentification.md](authentification.md) pour le détail.
