# Crons

## Fonctionnement

Le skeleton inclut un planificateur de tâches applicatives :

```
supercronic (chaque minute) ──> app:cron ──> table cron ──> exécute les commandes dues
```

- `supercronic` (installé dans l'image Docker) lance `app:cron` chaque minute
- `app:cron` interroge la table `cron` et exécute les commandes échues :
  statuts 0 (à exécuter), 2/3 avec date d'exécution dépassée, 3 en retry
  (`repeatcall > repeatexec`)
- Une commande en échec passe en statut KO et est relancée jusqu'à
  `repeatcall` fois (0 = retry infini)
- L'entrée `app:cron` est verrouillée (`LockableTrait`), pas d'exécution
  concurrente
- Un cron resté bloqué en "exécution en cours" plus d'une heure (crash
  précédent) est recalé automatiquement en "à exécuter"
- Les logs sont dans `var/log/cron.log`
- Gestion via l'interface admin : http://localhost:8024/admin/cron

## Ajouter un cron

1. Créer la commande console (ex: `app:sync-forge`)
2. Déclarer son exécution dans `CronFixtures` :

```php
$crons = [
    [
        'command' => 'app:sync-forge',
        'description' => 'Synchronise les projets depuis la forge.',
        'statut' => Cron::STATUT_OK,          // activé
        'repeatcall' => 0,
        'repeatexec' => 0,
        'repeatinterval' => 86400,            // 24h ; 0 = à chaque cycle de planification (1 minute)
    ],
];
```

Champs disponibles : `command`, `description`, `statut`, `repeatcall`,
`repeatexec`, `repeatinterval`, `nextexecdate`.

3. `app:init` (lancé au démarrage du container) crée l'entrée cron de
   façon idempotente

Exemple de référence inclus : `app:bonjour` (désactivé par défaut, à activer
depuis l'admin).

## Worker Messenger (async)

Les messages async (transports `doctrine://default?auto_setup=0`) sont
consommés par un worker lancé en parallèle des crons :

- `messenger:consume async` traite notamment les `IndexMarketMessage`
  déclenchés par les uploads / suppressions de fichiers ([doc/amoxtli.md](amoxtli.md)).
- Démarrage dans `misc/script/reconfigure.sh` (via `supervisord` ou
  `nohup` selon le contexte Docker).
- Vérifier l'état via `php bin/console messenger:stats`.
