<?php

namespace App\Service;

class CronSchedule
{
    /**
     * Calcule la prochaine date d'exécution d'un cron.
     *
     * Pour un intervalle multiple d'une heure, on rattrape depuis la
     * prochaine date planifiée (préserve les créneaux horaires fixes,
     * ex. « tous les jours à 06:00 »).
     * Sinon, on repart de maintenant.
     *
     * @param int        $interval    intervalle en secondes (>= 0 ; 0 = à chaque cycle de planification)
     * @param \DateTime  $now         l'instant du calcul
     * @param ?\DateTime $oldNextDate prochaine date planifiée, si déjà planifiée
     */
    public static function nextDate(int $interval, \DateTime $now, ?\DateTime $oldNextDate): \DateTime
    {
        $interval = max(0, $interval);

        if (0 === $interval) {
            return clone $now;
        }

        $base = (0 === $interval % 3600 && null !== $oldNextDate && $oldNextDate > $now)
            ? clone $oldNextDate
            : clone $now;

        $base->add(new \DateInterval('PT'.$interval.'S'));

        return $base;
    }
}
