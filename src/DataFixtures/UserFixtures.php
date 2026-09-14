<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public function __construct(
        #[Autowire(param: 'appAdmin')]
        private string $appAdmin,
        #[Autowire(param: 'appAdminEmail')]
        private string $appAdminEmail,
        #[Autowire(param: 'appSecret')]
        private string $appSecret,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $admins = array_map('trim', explode(',', $this->appAdmin));
        $emails = array_map('trim', explode(',', $this->appAdminEmail));

        if (count($admins) !== count($emails)) {
            throw new \RuntimeException(sprintf('APP_ADMIN (%d logins) et APP_ADMIN_EMAIL (%d emails) doivent avoir le même nombre d\'éléments, séparés par des virgules.', count($admins), count($emails)));
        }

        if (count($emails) !== count(array_flip($emails))) {
            throw new \RuntimeException('APP_ADMIN_EMAIL contient un email dupliqué.');
        }

        foreach ($admins as $index => $admin) {
            $user = $manager->getRepository(User::class)->findOneBy(['username' => $admin]);

            if (!$user) {
                $user = new User();
                $user->setUsername($admin);
                $user->setPassword(
                    $this->passwordHasher->hashPassword($user, $this->appSecret)
                );
                $user->setAvatar('medias/avatar/admin.jpg');
            }

            $user->setEmail($emails[$index] ?? $this->appAdminEmail);
            $user->setRoles(['ROLE_ADMIN']);
            $manager->persist($user);

            $this->addReference('user_'.$admin, $user);
        }

        $manager->flush();
    }
}
