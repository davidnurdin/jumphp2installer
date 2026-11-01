<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Creates an account (or changes its password)')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'the password (asked when missing)')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'ROLE_ADMIN too');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string)$input->getArgument('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error("not an e-mail address: $email");
            return Command::FAILURE;
        }
        $password = (string)($input->getOption('password') ?? $io->askHidden('Password'));
        if (strlen($password) < 8) {
            $io->error('8 characters at least');
            return Command::FAILURE;
        }
        $user = $this->users->findOneBy(['email' => $email]) ?? new User($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        if ($input->getOption('admin')) {
            $user->setRoles(['ROLE_ADMIN']);
        }
        $this->em->persist($user);
        $this->em->flush();
        $io->success("$email can sign in");
        return Command::SUCCESS;
    }
}
