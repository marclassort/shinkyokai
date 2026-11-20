<?php

namespace App\Command;

use App\Entity\Club;
use App\Entity\Member;
use App\Service\CardSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Envoie les cartes de licence en attente. Prévue pour être lancée par cron chaque minute :
 * chaque exécution traite un petit lot dans une durée bornée, puis s'arrête.
 *
 * Pour l'essayer SANS risque avant d'activer le cron :
 *   --status                       affiche l'état de la file, sans rien envoyer
 *   --dry-run                      liste ce qui serait envoyé, sans rien envoyer
 *   --test-recipient=moi@...       envoie quelques cartes à cette adresse seulement, sans rien marquer comme envoyé
 */
#[AsCommand(
    name: "app:licences:envoyer",
    description: "Envoie les cartes de licence en attente (à lancer par cron chaque minute)."
)]
class SendPendingCardsCommand extends Command
{
    private const TEST_MODE_MAX_CARDS = 5;

    public function __construct(private readonly CardSender $cardSender)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption("limit", null, InputOption::VALUE_REQUIRED, "Nombre maximal de cartes par exécution", "40")
            ->addOption("time-limit", null, InputOption::VALUE_REQUIRED, "Durée maximale d'une exécution, en secondes (on s'arrête avant la carte suivante)", "50")
            ->addOption("max-backlog", null, InputOption::VALUE_REQUIRED, "Au-delà de ce nombre de cartes en attente, la commande refuse de démarrer (voir --force-large-backlog)", "150")
            ->addOption("force-large-backlog", null, InputOption::VALUE_NONE, "Autorise un nombre de cartes en attente supérieur à --max-backlog")
            ->addOption("status", null, InputOption::VALUE_NONE, "Affiche l'état de la file d'envoi et s'arrête")
            ->addOption("dry-run", null, InputOption::VALUE_NONE, "Liste les cartes qui seraient envoyées, sans rien envoyer ni modifier")
            ->addOption("test-recipient", null, InputOption::VALUE_REQUIRED, "Envoie quelques cartes à cette adresse seulement, sans les marquer comme envoyées");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Une seule exécution à la fois (le cron peut relancer la commande alors que la précédente tourne encore).
        $lock = $this->cardSender->acquireLock();
        if ($lock === null) {
            $io->writeln("Une autre exécution est déjà en cours : rien à faire.");
            return Command::SUCCESS;
        }

        try {
            return $this->process($input, $io);
        } finally {
            $this->cardSender->releaseLock($lock);
        }
    }

    private function process(InputInterface $input, SymfonyStyle $io): int
    {
        $status = $this->cardSender->status();

        if ($input->getOption("status")) {
            $io->title("État de la file d'envoi des cartes");
            $io->definitionList(
                ["En attente (jamais tentées)" => $status["pending"]],
                ["En échec, nouvel essai prévu" => $status["retrying"]],
                ["Abandonnées (trop d'échecs, à traiter à la main)" => $status["abandoned"]],
            );
            return Command::SUCCESS;
        }

        $backlog = $status["pending"] + $status["retrying"];
        if ($backlog > (int)$input->getOption("max-backlog") && !$input->getOption("force-large-backlog")) {
            $io->error(sprintf(
                "%d cartes en attente : c'est anormalement élevé. Les adhérents des saisons précédentes ont-ils bien été marqués "
                . "comme « carte envoyée » (voir docs/regularisation-2026-11-06.md, étape de rattrapage) ? "
                . "Rien n'a été envoyé. Si c'est voulu : --force-large-backlog.",
                $backlog
            ));
            return Command::FAILURE;
        }

        $testRecipient = $input->getOption("test-recipient") ?: null;
        $limit = max(1, (int)$input->getOption("limit"));
        if ($testRecipient) {
            $limit = min($limit, self::TEST_MODE_MAX_CARDS);
            $io->warning(sprintf("MODE TEST : au plus %d carte(s) envoyée(s) à %s. Rien n'est marqué comme envoyé.", $limit, $testRecipient));
        }

        $items = $this->cardSender->pending($limit);
        if (!$items) {
            $io->writeln(sprintf("Aucune carte à envoyer maintenant (en attente de nouvel essai : %d, abandonnées : %d).", $status["retrying"], $status["abandoned"]));
            return Command::SUCCESS;
        }

        if ($input->getOption("dry-run")) {
            $io->title(sprintf("SIMULATION : %d carte(s) seraient envoyées (sur %d en attente)", count($items), $backlog));
            $io->table(["Type", "Licence / n° club", "Nom", "E-mail"], array_map(fn(array $item) => $this->describe($item), $items));
            return Command::SUCCESS;
        }

        $result = $this->cardSender->deliver(
            $items,
            function ($entity, string $status, ?string $error) use ($io): void {
                $label = $entity instanceof Club ? "club " . $entity->getClubNumber() : "membre " . $entity->getLicenceNumber();
                $io->writeln($status === "sent" ? "  envoyée : $label" : "  ÉCHEC   : $label — $error");
            },
            $testRecipient,
            (float)$input->getOption("time-limit")
        );

        $io->writeln(sprintf(
            "Terminé : %d envoyée(s), %d en échec%s. Reste en attente : environ %d.",
            $result["sent"],
            $result["failed"],
            $result["stopped"] ? " (limite de temps atteinte)" : "",
            max(0, $backlog - ($testRecipient ? 0 : $result["sent"]))
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array{entity: Club|Member, card: array} $item
     * @return list<string>
     */
    private function describe(array $item): array
    {
        $entity = $item["entity"];

        return $entity instanceof Club
            ? ["club", $entity->getClubNumber(), $entity->getName(), (string)$entity->getEmail()]
            : ["membre", (string)$entity->getLicenceNumber(), trim($entity->getFirstName() . " " . $entity->getLastName()), (string)$entity->getEmail()];
    }
}
