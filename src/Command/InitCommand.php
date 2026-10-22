<?php

namespace App\Command;

use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:init',
    description: 'Initialisation of the app',
)]
class InitCommand extends Command
{
    public function __construct(private UserRepository $userRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Recharger les fixtures même si la base contient déjà des comptes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('APP:INIT');
        $io->text('Initialisation of the app');
        $io->text('');

        // app:init tourne à chaque démarrage du conteneur : recharger les
        // fixtures recréerait les produits et crons supprimés depuis l'UI et
        // réinitialiserait rôles et e-mails des comptes APP_ADMIN.
        if (!$input->getOption('force') && $this->userRepository->count([]) > 0) {
            $io->text('> Base déjà initialisée, fixtures ignorées (--force pour les recharger)');
            $io->success('Initialisation terminée');

            return Command::SUCCESS;
        }

        $io->text('> Chargement des fixtures');

        $application = $this->getApplication();
        if (null === $application) {
            throw new \LogicException('Symfony Application instance is not available.');
        }

        $cmd = $application->find('doctrine:fixtures:load');
        $code = $cmd->run(new ArrayInput(['--append' => true, '--no-interaction' => true]), $output);
        if (Command::SUCCESS !== $code) {
            $io->error('Échec du chargement des fixtures');

            return $code;
        }

        $io->text('');
        $io->success('Initialisation terminée');

        return Command::SUCCESS;
    }
}
