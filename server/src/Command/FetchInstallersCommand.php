<?php

namespace App\Command;

use App\Service\Installers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:installers:fetch', description: 'Downloads the base installers (Windows, macOS, Linux) of a jumphp2installer release into var/installers')]
final class FetchInstallersCommand extends Command
{
    public function __construct(private readonly Installers $installers)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('repo', null, InputOption::VALUE_REQUIRED, 'the GitHub repository', 'davidnurdin/jumphp2installer')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'the release', 'latest');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $got = $this->installers->fetch((string)$input->getOption('repo'), (string)$input->getOption('tag'));
        if ($got === []) {
            $io->error('no base installer in that release');
            return Command::FAILURE;
        }
        $io->success('var/installers: ' . implode(', ', $got));
        return Command::SUCCESS;
    }
}
