<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: "app:create-user")]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription("Creates a new user.")
            ->addArgument("email", InputArgument::REQUIRED, "The email of the user.")
            ->addArgument("password", InputArgument::REQUIRED, "The password of the user.")
            ->addArgument("admin", InputArgument::OPTIONAL, "If set, the user will be created as an admin");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getArgument("email");
        $password = $input->getArgument("password");
        $isAdmin = $input->getArgument("admin");

        // Crée une instance de l'utilisateur
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($isAdmin ? ["ROLE_ADMIN"] : ["ROLE_USER"]);

        // Hash du mot de passe
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        // Sauvegarde de l'utilisateur en base de données
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln("User successfully created!");

        return Command::SUCCESS;
    }
}