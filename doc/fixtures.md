# Fixtures

Les fixtures sont chargées automatiquement lors de `app:init` via
`doctrine:fixtures:load --append` (conserve les données existantes).

## Compte admin

**Le compte admin est créé par `UserFixtures`** (dans `src/DataFixtures/`),
à partir des variables `APP_ADMIN` et `APP_ADMIN_EMAIL`. Le mot de passe
initial correspond à `APP_SECRET` (d'où l'importance de le changer avant
tout démarrage).

## Pattern recommandé (idempotent)

Les fixtures métier vivent dans `src/DataFixtures/`. Chaque classe étend
`Doctrine\Bundle\FixturesBundle\Fixture` et implémente `load()`. Les
fixtures métier doivent déclarer `UserFixtures` en dépendance.

```php
class StatusFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $data = [
            ['code' => '001', 'title' => 'Titre 1'],
            ['code' => '002', 'title' => 'Titre 2'],
        ];

        foreach ($data as $item) {
            $entity = $this->repository->findOneBy(['code' => $item['code']]);
            if (!$entity) {
                $entity = new Status();
                $entity->setCode($item['code']);
                $this->addReference('status_'.$item['code'], $entity);
            }
            $entity->setTitle($item['title']);
            $manager->persist($entity);
        }

        $manager->flush();
    }
}
```

## Dépendances entre fixtures

Implémenter `DependentFixtureInterface` pour forcer l'ordre d'exécution :

```php
class ServiceFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }
}
```
