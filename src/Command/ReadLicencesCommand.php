<?php

namespace App\Command;

use Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: "app:read-licences",
    description: "Lit un fichier .xlsx ou .csv et affiche les lignes (colonnes jointes).",
)]
class ReadLicencesCommand extends Command
{
    public function __construct(
        private readonly ParameterBagInterface $params,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                "filename",
                InputArgument::REQUIRED, "Nom du fichier (ex: data.csv ou data.xlsx)"
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filename = $input->getArgument("filename");

        $kernelProjectDir = $this->params->get("kernel.project_dir");

        $filePath = sprintf("%s/public/uploads/%s", $kernelProjectDir, $filename);

        if (!file_exists($filePath)) {
            $io->error(sprintf("Fichier introuvable : %s", $filePath));
            return Command::FAILURE;
        }

        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();

            foreach ($sheet->toArray() as $index => $row) {
                $io->writeln(sprintf("%d: %s", $index + 1, implode(" | ", $row)));
            }

            $io->success("Lecture terminée.");

        } catch (Exception $e) {
            $io->error("Erreur lors de la lecture du fichier : " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}