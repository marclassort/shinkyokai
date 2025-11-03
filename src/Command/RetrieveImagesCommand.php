<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;

#[AsCommand(
    name: "app:retrieve-images",
    description: "Envoie un fichier par email en pièce jointe."
)]
class RetrieveImagesCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                "filepath",
                InputArgument::REQUIRED,
                "Chemin du fichier (ex: public/uploads/image.jpg)"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $filepath = (string) $input->getArgument("filepath");

        if (!is_file($filepath)) {
            $io->error(sprintf("Fichier introuvable : %s", $filepath));
            return Command::FAILURE;
        }

        try {
            $email = (new Email())
                ->from("shinkyokai.academie@gmail.com")
                ->to("marc.lassort@gmail.com")
                ->subject("Images récupérées")
                ->text("Bonjour, vous trouverez le fichier en pièce jointe.")
                ->attachFromPath($filepath, basename($filepath));

            $this->mailer->send($email);
            $io->success(sprintf(
                "Email envoyé à %s avec la pièce jointe %s.",
                "marc.lassort@gmail.com",
                basename($filepath)
            ));
            return Command::SUCCESS;

        } catch (TransportExceptionInterface $e) {
            $io->error("Erreur d’envoi (transport) : " . $e->getMessage());
        } catch (Throwable $e) {
            $io->error("Erreur : " . $e->getMessage());
        }

        return Command::FAILURE;
    }
}