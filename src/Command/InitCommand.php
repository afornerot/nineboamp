<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:init',
    description: 'Initialisation of the app',
)]
class InitCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('APP:INIT');
        $io->text('Initialisation of the app');
        $io->text('');
        $io->text('> Chargement des fixtures');

        $application = $this->getApplication();
        if (null === $application) {
            throw new \LogicException('Symfony Application instance is not available.');
        }

        $command = $application->find('doctrine:fixtures:load');
        $arguments = [
            '--append' => true,
            '--no-interaction' => true,
        ];
        $fixtureInput = new ArrayInput($arguments);
        $command->run($fixtureInput, $output);

        $io->text('');
        $io->success('Initialisation terminée');

        return Command::SUCCESS;
    }
}
