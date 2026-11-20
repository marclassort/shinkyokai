<?php

namespace App\Command;

use App\Entity\Club;
use App\Entity\Member;
use App\Entity\Order;
use App\Service\LicenceNumberGenerator;
use App\Service\CardSender;
use App\Service\MemberFileReader;
use App\Service\MemberRegistrar;
use App\Service\NameFormatter;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Régularise une commande dont le traitement a été interrompu après l'envoi d'une partie des cartes
 * (cas du 6 novembre 2026 : 56 cartes envoyées sur 107, rien enregistré en base).
 *
 * - Enregistre TOUS les adhérents du fichier en une seule transaction.
 * - Les N premières lignes (--already-sent) reprennent le numéro de licence déjà imprimé sur leur carte
 *   (--first-licence, puis +1 par ligne) et ne reçoivent AUCUN nouvel e-mail.
 * - Les lignes suivantes continuent la numérotation et reçoivent leur carte.
 * - Les adhérents déjà en base qui occupent un numéro réservé à une ligne du fichier sont renumérotés
 *   après le dernier numéro du lot et reçoivent une carte de remplacement.
 * - Relançable sans risque : un adhérent déjà enregistré n'est pas recréé.
 *
 * Sans --execute, la commande n'écrit RIEN et n'envoie RIEN : elle affiche seulement le plan.
 */
#[AsCommand(
    name: "app:licences:regulariser",
    description: "Régularise une commande de complément interrompue (simulation par défaut, --execute pour appliquer)."
)]
class RegulariseComplementCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MemberFileReader $fileReader,
        private readonly MemberRegistrar $registrar,
        private readonly LicenceNumberGenerator $licenceNumbers,
        private readonly CardSender $cardSender,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument("order", InputArgument::REQUIRED, "Identifiant de la commande à régulariser (ex. 52)")
            ->addArgument("file", InputArgument::REQUIRED, "Chemin du fichier d'adhérents (CSV ou XLSX) envoyé par le club")
            ->addOption("club-number", null, InputOption::VALUE_REQUIRED, "Numéro du club (ex. SHIN0011)")
            ->addOption("first-licence", null, InputOption::VALUE_REQUIRED, "Numéro de licence imprimé sur la carte de la 1re ligne (ex. 195)")
            ->addOption("already-sent", null, InputOption::VALUE_REQUIRED, "Nombre de premières lignes ayant déjà reçu une carte valide", "0")
            ->addOption("already-sent-at", null, InputOption::VALUE_REQUIRED, "Date/heure d'envoi des cartes déjà parties, à enregistrer (ex. \"2026-11-06 22:03\", heure UTC)")
            ->addOption("mail-rows", null, InputOption::VALUE_REQUIRED, "Lignes à qui envoyer une carte (ex. 57-107). Par défaut : toutes celles qui n'en ont pas reçu")
            ->addOption("swap-names", null, InputOption::VALUE_REQUIRED, "Lignes dont le prénom et le nom sont inversés dans le fichier (ex. 31 ou 31,48) : remis dans le bon ordre, le nom de famille en capitales")
            ->addOption("resend-rows", null, InputOption::VALUE_REQUIRED, "Lignes déjà servies dont la carte envoyée était erronée (ex. 31) : une carte corrigée est renvoyée, avec e-mail explicatif")
            ->addOption("no-replacement-cards", null, InputOption::VALUE_NONE, "Les adhérents renumérotés ne reçoivent AUCUNE carte (ni remplacement, ni nouvelle) : à utiliser s'ils n'ont jamais reçu l'ancienne carte et que l'envoi se fait autrement")
            ->addOption("no-mail", null, InputOption::VALUE_NONE, "Enregistre tout mais n'envoie rien : les cartes restent « en attente » pour app:licences:envoyer (permet de l'essayer d'abord)")
            ->addOption("execute", null, InputOption::VALUE_NONE, "Applique réellement les changements et envoie les e-mails");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = (bool)$input->getOption("execute");

        $io->title("Régularisation d'une commande de complément" . ($execute ? "" : " — SIMULATION (rien n'est modifié)"));

        // --- Paramètres -------------------------------------------------------------------------------------
        foreach (["club-number", "first-licence"] as $required) {
            if (!$input->getOption($required)) {
                $io->error("L'option --$required est obligatoire.");
                return Command::INVALID;
            }
        }
        $firstLicence = (int)$input->getOption("first-licence");
        $alreadySent = (int)$input->getOption("already-sent");

        $order = $this->entityManager->getRepository(Order::class)->find((int)$input->getArgument("order"));
        if (!$order) {
            $io->error("Commande introuvable.");
            return Command::FAILURE;
        }

        $club = $this->entityManager->getRepository(Club::class)->findOneBy(["clubNumber" => $input->getOption("club-number")]);
        if (!$club) {
            $io->error("Club introuvable : " . $input->getOption("club-number"));
            return Command::FAILURE;
        }
        if ($order->getClub() !== null && $order->getClub()->getId() !== $club->getId()) {
            $io->error(sprintf("La commande est déjà rattachée à un autre club (%s).", $order->getClub()->getClubNumber()));
            return Command::FAILURE;
        }

        $file = (string)$input->getArgument("file");
        if (!is_file($file)) {
            $io->error("Fichier introuvable : $file");
            return Command::FAILURE;
        }
        try {
            $rows = $this->fileReader->read($file, pathinfo($file, PATHINFO_EXTENSION));
        } catch (InvalidArgumentException $e) {
            $io->error("Fichier d'adhérents invalide : " . $e->getMessage());
            return Command::FAILURE;
        }
        $total = count($rows);

        try {
            $swapRows = $this->parseRowList((string)$input->getOption("swap-names"), $total, "--swap-names");
            $resendRows = $this->parseRowList((string)$input->getOption("resend-rows"), $total, "--resend-rows");
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }
        foreach ($swapRows as $rowNumber) {
            $original = $rows[$rowNumber - 1];
            // Le nom de famille est écrit en capitales (convention du fichier) : le prénom lu « DURAND » a déjà été mis en forme « Durand ».
            [$rows[$rowNumber - 1]["first_name"], $rows[$rowNumber - 1]["last_name"]] = [NameFormatter::firstName($original["last_name"]), mb_strtoupper($original["first_name"])];
        }

        if ($alreadySent < 0 || $alreadySent > $total) {
            $io->error("--already-sent doit être compris entre 0 et $total.");
            return Command::INVALID;
        }

        // Garde-fou : une commande déjà régularisée ne renvoie rien sans demande explicite.
        $noMail = (bool)$input->getOption("no-mail");
        $noReplacementCards = (bool)$input->getOption("no-replacement-cards");
        $explicitRange = $input->getOption("mail-rows");
        if ($order->getStatus() === "payee" && !$explicitRange) {
            $io->warning("Cette commande est déjà « payee » : rien n'est modifié ni envoyé. "
                . "Pour renvoyer des cartes précises, utilisez --mail-rows=DEBUT-FIN.");
            return Command::SUCCESS;
        }

        try {
            [$mailFrom, $mailTo] = $explicitRange ? $this->parseRange((string)$explicitRange, $total) : [$alreadySent + 1, $total];
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        try {
            $alreadySentAt = ($v = $input->getOption("already-sent-at")) ? new \DateTimeImmutable((string)$v, new \DateTimeZone("UTC")) : new \DateTimeImmutable();
        } catch (\Exception) {
            $io->error("--already-sent-at : date illisible.");
            return Command::INVALID;
        }

        $season = $this->seasonOf($order->getCreatedAt() ?? new \DateTimeImmutable());

        // --- Plan -------------------------------------------------------------------------------------------
        $existingByKey = [];
        foreach ($this->entityManager->getRepository(Member::class)->findBy(["club" => $club, "sportSeason" => $season]) as $member) {
            $existingByKey[MemberRegistrar::identityKey($member->getFirstName(), $member->getLastName(), $member->getBirthDate())][] = $member;
        }

        $plan = [];
        $imposed = [];
        foreach ($rows as $i => $row) {
            $rowNumber = $i + 1;
            $key = MemberRegistrar::identityKey($row["first_name"], $row["last_name"], $row["birth_date"]);
            $existing = !empty($existingByKey[$key]) ? array_shift($existingByKey[$key]) : null;
            $licence = LicenceNumberGenerator::format($firstLicence + $i);

            $plan[] = ["row" => $rowNumber, "data" => $row, "licence" => $licence, "existing" => $existing, "resend" => in_array($rowNumber, $resendRows, true)];
            if (!$existing) {
                $imposed[$firstLicence + $i] = true;
            }
        }

        $inUse = $this->licenceNumbers->numbersInUse();
        $alreadyInFile = [];
        foreach ($plan as $entry) {
            if ($entry["existing"]) {
                $alreadyInFile[$entry["existing"]->getId()] = true;
            }
        }

        // Adhérents en base qui occupent un numéro destiné à une ligne du fichier : à renuméroter.
        $victims = [];
        foreach ($inUse as $memberId => $number) {
            if (isset($imposed[$number]) && !isset($alreadyInFile[$memberId])) {
                $victims[$memberId] = $number;
            }
        }
        ksort($victims);

        $highestOutsideVictims = 0;
        foreach ($inUse as $memberId => $number) {
            if (!isset($victims[$memberId])) {
                $highestOutsideVictims = max($highestOutsideVictims, $number);
            }
        }
        $nextFree = max($firstLicence + $total - 1, $highestOutsideVictims) + 1;

        $renumbering = [];
        foreach (array_keys($victims) as $memberId) {
            $renumbering[$memberId] = LicenceNumberGenerator::format($nextFree++);
        }

        // --- Affichage du plan ------------------------------------------------------------------------------
        $toCreate = count(array_filter($plan, static fn($e) => !$e["existing"]));
        $io->definitionList(
            ["Commande" => sprintf("#%d (%s, statut « %s »)", $order->getId(), $order->getOrderType(), $order->getStatus())],
            ["Club" => sprintf("%s (%s), saison %s", $club->getName(), $club->getClubNumber(), $season)],
            ["Fichier" => sprintf("%d adhérents", $total)],
            ["À créer en base" => $toCreate . " (déjà présents : " . ($total - $toCreate) . ")"],
            ["Numéros du lot" => sprintf("%s → %s", $plan[0]["licence"], $plan[$total - 1]["licence"])],
            ["Cartes déjà envoyées (conservées)" => $alreadySent . " (lignes 1 à $alreadySent, aucun e-mail)"],
            ["Cartes à envoyer" => sprintf("lignes %d à %d", $mailFrom, $mailTo)],
            ["Corrections" => $this->describeCorrections($plan, $swapRows, $resendRows)],
        );

        if ($victims) {
            $rowsTable = [];
            foreach ($victims as $memberId => $oldNumber) {
                $member = $this->entityManager->find(Member::class, $memberId);
                $rowsTable[] = [
                    $memberId,
                    trim($member->getFirstName() . " " . $member->getLastName()),
                    $member->getClub()?->getName() ?? "(sans club)",
                    LicenceNumberGenerator::format($oldNumber),
                    $renumbering[$memberId],
                    $member->getEmail() ?? "—",
                    $noReplacementCards
                        ? "NON (option --no-replacement-cards : aucune carte envoyée)"
                        : ($member->getEmail() && $member->getCommande()?->getStatus() === "payee" ? "oui" : "NON (pas d'e-mail ou commande non payée)"),
                ];
            }
            $io->section("Adhérents renumérotés (leur ancien numéro est déjà imprimé sur une carte du lot)");
            $io->table(["id", "Nom", "Club", "Ancien n°", "Nouveau n°", "E-mail", "Dans la file d'envoi"], $rowsTable);
            $io->writeln($noReplacementCards
                ? "→ Ils sont renumérotés SANS qu'aucune carte ne leur soit envoyée (--no-replacement-cards)."
                : "→ Chacun recevra une carte de remplacement avec un e-mail explicatif.");
        } else {
            $io->writeln("Aucun adhérent existant à renuméroter.");
        }

        $io->section("Lignes à créer");
        $table = [];
        foreach ($plan as $entry) {
            if ($entry["existing"]) {
                continue;
            }
            $table[] = [
                $entry["row"],
                $entry["licence"],
                $entry["data"]["last_name"] . " " . $entry["data"]["first_name"],
                $entry["resend"] ? "RENVOI : carte corrigée, remplace celle de " . $entry["licence"] : ($entry["row"] >= $mailFrom && $entry["row"] <= $mailTo ? "carte envoyée" : "carte déjà reçue"),
            ];
        }
        $io->table(["Ligne", "N° licence", "Adhérent", "Carte"], $table);

        if (!$execute) {
            $io->note("Simulation terminée : relancez avec --execute pour appliquer. Aucun changement n'a été fait.");
            return Command::SUCCESS;
        }

        // --- Application ------------------------------------------------------------------------------------

        // Même verrou que le cron : pendant la régularisation, les nouveaux adhérents sont « en attente » en base,
        // le cron ne doit pas leur envoyer la carte en même temps que cette commande.
        $lock = $this->cardSender->acquireLock(wait: true);
        try {
            return $this->apply($io, $plan, $order, $club, $season, $victims, $renumbering, $mailFrom, $mailTo, $alreadySent, $alreadySentAt, $noMail, $noReplacementCards);
        } finally {
            $lock && $this->cardSender->releaseLock($lock);
        }
    }

    /**
     * @param list<array> $plan
     * @param array<int, int> $victims
     * @param array<int, string> $renumbering
     */
    private function apply(SymfonyStyle $io, array $plan, Order $order, Club $club, string $season, array $victims, array $renumbering, int $mailFrom, int $mailTo, int $alreadySent, \DateTimeImmutable $alreadySentAt, bool $noMail, bool $noReplacementCards): int
    {
        /** @var list<array{entity: Member, card: array}> $items */
        $items = [];
        $rowOf = [];
        $created = [];

        $this->entityManager->wrapInTransaction(function () use ($plan, $order, $club, $season, $victims, $renumbering, $mailFrom, $mailTo, $alreadySent, $alreadySentAt, $noReplacementCards, &$items, &$rowOf, &$created): void {
            // 1. Libérer les numéros occupés (flush séparé : la contrainte d'unicité s'applique à chaque requête).
            foreach ($victims as $memberId => $oldNumber) {
                $member = $this->entityManager->find(Member::class, $memberId);
                $member->setLicenceNumber($renumbering[$memberId]);
                if ($noReplacementCards) {
                    // Seul le numéro change : l'état d'envoi de la carte n'est pas touché, rien n'est mis en file.
                    continue;
                }
                // Carte de remplacement « en attente » : envoyée tout de suite ou par app:licences:envoyer, avec l'e-mail explicatif.
                $member->resetCardDelivery()->setCardReplaces(LicenceNumberGenerator::format($oldNumber));
                $items[] = ["entity" => $member, "card" => $this->registrar->cardFor($member, $member->getClub() ?? $club, $member->getSportSeason() ?? $season, LicenceNumberGenerator::format($oldNumber))];
            }
            $this->entityManager->flush();

            // 2. Enregistrer les adhérents du fichier.
            foreach ($plan as $entry) {
                $member = $entry["existing"] ?? $this->registrar->createClubMember($order, $club, $entry["data"], $entry["licence"], $season);
                if (!$entry["existing"]) {
                    $created[] = $member;
                    // Les premières lignes ont déjà reçu une carte valide : on l'enregistre, pour que personne ne la renvoie.
                    if ($entry["row"] <= $alreadySent) {
                        if ($entry["resend"]) {
                            // La carte envoyée le 6 novembre portait des données erronées : une carte corrigée la remplace (même numéro).
                            $member->resetCardDelivery()->setCardReplaces($member->getLicenceNumber());
                            $items[] = ["entity" => $member, "card" => $this->registrar->cardFor($member, $club, $season, $member->getLicenceNumber())];
                        } else {
                            $member->markCardSent($alreadySentAt);
                        }
                    }
                }
                if ($entry["row"] >= $mailFrom && $entry["row"] <= $mailTo) {
                    $items[] = ["entity" => $member, "card" => $this->registrar->cardFor($member, $club, $season)];
                    $rowOf[spl_object_id($member)] = $entry["row"];
                }
            }

            // 3. Commande payée et rattachée au club.
            $order->setClub($club);
            $order->setStatus("payee");

            $this->entityManager->flush();
        });

        $io->success(sprintf("Base mise à jour : %d adhérent(s) créé(s), %d renuméroté(s), commande #%d passée à « payee ».", count($created), count($victims), $order->getId()));

        // --- Envoi des cartes (après l'enregistrement : un échec d'envoi n'annule rien) --------------------------
        if ($noMail) {
            $io->note(sprintf("--no-mail : %d carte(s) restent « en attente ». Essayez-les avec `app:licences:envoyer --dry-run` puis "
                . "`--test-recipient=votre@adresse`, et envoyez-les avec `app:licences:envoyer` (à la main ou par cron).", count($items)));
            return Command::SUCCESS;
        }

        if (!$items) {
            $io->writeln("Aucune carte à envoyer.");
            return Command::SUCCESS;
        }

        $io->section(sprintf("Envoi de %d carte(s)", count($items)));
        $progressBar = $io->createProgressBar(count($items));
        $progressBar->start();
        $failures = [];
        $result = $this->cardSender->deliver($items, function (object $entity, string $status, ?string $error) use ($progressBar, &$failures, $rowOf): void {
            $progressBar->advance();
            if ($status === "failed") {
                $failures[] = sprintf(" - %s %s (%s)%s : %s", $entity->getLastName(), $entity->getFirstName(), $entity->getLicenceNumber(),
                    isset($rowOf[spl_object_id($entity)]) ? " ligne " . $rowOf[spl_object_id($entity)] : "", $error);
            }
        });
        $progressBar->finish();
        $io->newLine(2);

        $io->writeln(sprintf("Envoyées : %d — échecs : %d", $result["sent"], $result["failed"]));

        if ($failures) {
            $io->error("Des cartes n'ont pas pu être envoyées. Elles restent « en attente » (le cron les retentera ; sinon relancez avec --mail-rows pour ces lignes) :");
            array_map($io->writeln(...), $failures);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function parseRowList(string $value, int $total, string $option): array
    {
        if (trim($value) === "") {
            return [];
        }
        $rows = [];
        foreach (explode(",", $value) as $part) {
            if (!preg_match("/^\s*\d+\s*$/", $part) || (int)$part < 1 || (int)$part > $total) {
                throw new InvalidArgumentException("$option doit contenir des numéros de ligne entre 1 et $total, séparés par des virgules.");
            }
            $rows[] = (int)$part;
        }

        return array_values(array_unique($rows));
    }

    /**
     * @param list<array> $plan
     * @param list<int> $swapRows
     * @param list<int> $resendRows
     */
    private function describeCorrections(array $plan, array $swapRows, array $resendRows): string
    {
        $lines = [];
        foreach ($swapRows as $row) {
            $data = $plan[$row - 1]["data"];
            $lines[] = sprintf("ligne %d : nom et prénom inversés dans le fichier, remis dans l'ordre → %s %s", $row, $data["last_name"], $data["first_name"]);
        }
        foreach ($resendRows as $row) {
            $lines[] = sprintf("ligne %d : carte déjà envoyée mais erronée → carte corrigée renvoyée (%s)", $row, $plan[$row - 1]["licence"]);
        }

        return $lines ? implode("\n", $lines) : "aucune";
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseRange(string $range, int $total): array
    {
        if (!preg_match("/^(\d+)-(\d+)$/", trim($range), $matches) || (int)$matches[1] < 1 || (int)$matches[1] > (int)$matches[2] || (int)$matches[2] > $total) {
            throw new InvalidArgumentException("--mail-rows doit être de la forme DEBUT-FIN avec 1 <= DEBUT <= FIN <= $total.");
        }

        return [(int)$matches[1], (int)$matches[2]];
    }

    private function seasonOf(\DateTimeInterface $date): string
    {
        $year = (int)$date->format("Y");
        $start = (int)$date->format("n") >= 7 ? $year : $year - 1;

        return sprintf("%d-%d", $start, $start + 1);
    }
}
