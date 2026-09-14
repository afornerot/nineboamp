# Authentification

## Principe

Les routes protégées ne connaissent pas la méthode d'authentification. Elles
déclarent une exigence de rôle, et le pipeline déclenche le flow paramétré :

```
Requête /admin/cron (access_control : ROLE_ADMIN)
   │
   ├── Connecté avec le bon rôle             → la route s'exécute
   ├── Connecté mais rôle insuffisant        → 403
   └── Non connecté                          → AuthGuardEntryPoint
         │
         └── selon MODE_AUTH (.env / .env.local)
                ├── SQL  → redirection /login (form_login + CSRF)
                ├── CAS  → redirection serveur CAS
                └── OIDC → redirection fournisseur d'identité (+ state)
```

Le flow OIDC inclut une protection CSRF obligatoire : un `state` aléatoire
est stocké en session à la redirection vers le fournisseur d'identité, puis
validé au callback. Sans `state` correspondant en session, l'authentification
est refusée.

Changer de méthode d'authentification = changer `MODE_AUTH`. Aucun code
à modifier.

## Rôles

Hiérarchie (un ADMIN hérite de MASTER hérite de USER) :

| Route exige | Peut y accéder |
|-------------|----------------|
| `ROLE_USER` | tout utilisateur authentifié |
| `ROLE_MASTER` | MASTER et ADMIN |
| `ROLE_ADMIN` | ADMIN |

Deux leviers de protection, au choix :

| Levier | Où | Usage |
|--------|-----|-------|
| `access_control` | `security.yaml` (pattern d'URL) | protection globale par préfixe |
| `#[IsGranted('ROLE_ADMIN')]` | attribut sur une action/controller | protection au cas par cas |

Conventions du skeleton :

- `/admin/...` : interfaces d'administration (ROLE_ADMIN)
- `/master/...` : routes métier intermédiaires (ROLE_MASTER)
- `/user` : profil de l'utilisateur courant (ROLE_USER)

## Identification CAS / OIDC (autosubmit autoupdate)

À chaque login CAS ou OIDC, `IdentityUserProvider` crée (ou met à jour)
l'utilisateur local à partir des attributs du provider :

| Attribut | CAS | OIDC |
|----------|-----|------|
| Identifiant | `CAS_USERNAME` | `OIDC_USERNAMEATTRIBUTE` |
| Email | `CAS_MAIL` | `OIDC_MAILATTRIBUTE` |
| Nom | `CAS_LASTNAME` | `OIDC_LASTNAMEATTRIBUTE` |
| Prénom | `CAS_FIRSTNAME` | `OIDC_FIRSTNAMEATTRIBUTE` |

Si l'attribut email est absent, l'authentification est refusée avec un message
clair (l'email est obligatoire en base).

Le mot de passe des comptes CAS/OIDC est généré à la volée : seul le provider
d'identité peut les faire se connecter.

## Firewall MCP (machine-to-machine)

`/mcp` est un endpoint pour clients API (agents IA). L'authentification est
portée par `McpAuthenticator` : Bearer token `MCP_SECRET` obligatoire à
chaque requête (`stateless: true`, aucune session).

Avant usage, **modifier `MCP_SECRET`** (.env.local) : la valeur par défaut
(`changeme`) ne doit jamais être utilisée. Un token vide est refusé, ainsi
que tout token ne correspondant pas à `MCP_SECRET`.

Le `access_control` sur `^/mcp` est `PUBLIC_ACCESS` sans que ce soit une
faille : l'exigence d'authentification est portée par l'authenticator du
firewall. Un client sans header valide reçoit un **401** (sémantique HTTP
correcte pour une API) plutôt qu'un 403. Un client JSON API ne peut pas
suivre un flow de login interactif (CAS/OIDC) — d'où un firewall séparé
du flux principal.

## Extensibilité

Ajouter une méthode d'authentification (LDAP, SAML, token...) :

1. Créer `src/Security/XxxAuthenticator` — `supports()` = preuve uniquement,
   jamais de redirection
2. Ajouter le case `"XXX"` dans `AuthGuardEntryPoint::start()` — c'est lui qui
   déclenche le flow de login
3. `MODE_AUTH=XXX` dans `.env.local`
