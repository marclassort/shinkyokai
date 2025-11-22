<?php

namespace App\Command;

use App\Entity\Club;
use App\Entity\Member;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Twig\Environment as Twig;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AsCommand(
    name: "app:club-import",
    description: "Importe un club + ses membres depuis un CSV/XLSX et un logo : " .
    "création/maj en base + génération des PDF + envoi des emails."
)]
class ImportClubAndMemberLicencesCommand extends Command
{
    private const MEMBER_FALLBACK_EMAIL = "marc.lassort@gmail.com";

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly Twig $twig,
        private readonly ParameterBagInterface $params,
        private readonly RequestStack $requestStack,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument("csvPath", InputArgument::REQUIRED, "Chemin du fichier CSV (ou XLSX)")
            ->addArgument("logoPath", InputArgument::REQUIRED, "Chemin du logo du club (image)")
            ->addArgument("clubNumber", InputArgument::OPTIONAL, "Numéro du club (optionnel" .
                "si club nouveau)");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $publicBase = "https://shinkyokai.com";
        $this->requestStack->push(Request::create($publicBase));

        $io = new SymfonyStyle($input, $output);

        $csvPath  = (string) $input->getArgument("csvPath");
        $logoPath = (string) $input->getArgument("logoPath");

        $projectDir = $this->params->get("kernel.project_dir");

        $csvPath = str_starts_with($csvPath, "/") ? $csvPath : $projectDir . "/" . ltrim($csvPath, "/");
        $logoPath = str_starts_with($logoPath, "/") ? $logoPath : $projectDir . "/" . ltrim($logoPath, "/");

        $clubNumInput = $input->getArgument("clubNumber");
        $clubNum = is_string($clubNumInput) && trim($clubNumInput) !== "" ? trim($clubNumInput) : null;

        $io->writeln($clubNum
            ? "Club n°: <info>$clubNum</info>"
            : "Club n°: <comment>(non fourni)</comment> — un numéro sera généré si le club est nouveau");

        // --- Contrôles de base
        if (!is_file($csvPath)) {
            $io->error("CSV/XLSX introuvable: $csvPath");
            return Command::FAILURE;
        }
        if (!is_file($logoPath)) {
            $io->error("Logo introuvable: $logoPath");
            return Command::FAILURE;
        }

        $io->title("Import club + membres (ÉCRITURE EN BASE)");
        $io->writeln("CSV: <info>$csvPath</info>");
        $io->writeln("Logo: <info>$logoPath</info>");
        $io->writeln("Club n°: <info>" . ($clubNum ?? "(sera généré)") . "</info>");
        $io->newLine();

        // --- 1/2) Récupérer ou créer le club
        /** @var Club|null $club */
        $club = null;
        if ($clubNum) {
            $club = $this->em->getRepository(Club::class)->findOneBy(["clubNumber" => $clubNum]);
        }
        $currentSeason = $this->getCurrentSportSeason();

        if ($club) {
            $club->setSportSeason($currentSeason);
            $club->setLogo(basename($logoPath));

            // Si le club n'a pas encore d'email, on demande à l'utilisateur
            if (!$club->getEmail()) {
                $helper = $this->getHelper("question");
                $emailQuestion = new Question("Email du club (aucun email trouvé en base) : ");
                $clubEmail = (string) $helper->ask($input, $output, $emailQuestion);
                $club->setEmail($clubEmail);
            }

            $io->success("Club existant trouvé: {$club->getName()} (n° {$club->getClubNumber()})");
            $io->writeln("Saison courante mise à jour sur le club: <comment>$currentSeason</comment>");
        } else {
            // Création d'un nouveau club en posant les questions
            $helper = $this->getHelper("question");

            $questions = [
                "name"           => new Question("Nom du club: "),
                "address"        => new Question("Adresse: "),
                "zip"            => new Question("Code postal: "),
                "city"           => new Question("Ville: "),
                "president_name" => new Question("Nom du président: "),
                "treasurer_name" => new Question("Nom du trésorier: "),
                "email"          => new Question("Email du club: "),
                "country"        => new Question("Pays: ", "France"),
            ];

            $answers = array_map(static function ($q) use ($output, $input, $helper) {
                return (string)$helper->ask($input, $output, $q);
            }, $questions);

            $club = new Club();
            if ($clubNum === null) {
                $club->setName($answers["name"]);
                $club->setAddress($answers["address"]);
                $club->setPostalCode($answers["zip"]);
                $club->setCity($answers["city"]);
                $club->setPresidentName($answers["president_name"]);
                $club->setTreasurerName($answers["treasurer_name"]);
                $club->setEmail($answers["email"]);
                $club->setCountry($answers["country"]);
                $club->setLogo(basename($logoPath));

                $lastClub = $this->em->getRepository(Club::class)->findOneBy([], ["id" => "DESC"]);
                $clubNumber = $this->generateUniqueClubNumber($lastClub);
                $club->setClubNumber($clubNumber);
                $io->writeln("Numéro de club généré: <info>$clubNumber</info>");
            } else {
                $club->setClubNumber($clubNum);
            }
            $club->setSportSeason($currentSeason);

            $this->em->persist($club);
            $io->writeln("→ Nouveau club persisté (prêt pour flush en base).");

            $io->success("NOUVEAU CLUB créé (sera flush en base).");
            $io->listing([
                "Nom: {$club->getName()}",
                "N°: {$club->getClubNumber()}",
                "Adresse: {$club->getAddress()} {$club->getPostalCode()} {$club->getCity()}",
                "Pays: {$club->getCountry()}",
                "Président: {$club->getPresidentName()}",
                "Trésorier: {$club->getTreasurerName()}",
                "Email: {$club->getEmail()}",
                "Saison: {$club->getSportSeason()}",
            ]);
        }

        // On fait un flush déjà sur le club pour garantir l'ID avant de créer les membres
        $this->em->flush();
        $io->writeln("→ Flush effectué sur le club en base.");

        // --- 3) Générer la licence club (PDF) + envoyer email à l'email du club
        $clubEmail = $club->getEmail();

        try {
            $clubData = [
                "logo"      => $logoPath,
                "club_name" => $club->getName() ?? "Club",
                "numero"    => $club->getClubNumber() ?? $clubNum,
                "address"   => $club->getAddress() ?? "",
                "address2"  => null,
                "zip"       => $club->getPostalCode() ?? "",
                "city"      => $club->getCity() ?? "",
                "season"    => $currentSeason,
            ];

            $clubPdf = $this->generateLicensePdf($clubData, "club");
            $io->writeln("→ PDF licence club généré: <info>$clubPdf</info>");

            if ($clubEmail) {
                $io->writeln("→ Envoi email licence club à <info>$clubEmail</info>...");
                $this->sendLicenseEmail($clubPdf, "club", $clubEmail);
                $io->success("Licence CLUB générée + email envoyé à $clubEmail");
            } else {
                $io->warning("Aucune adresse email définie pour le club : licence club non envoyée par email.");
            }
        } catch (TransportExceptionInterface $e) {
            $io->error("Erreur d’envoi email (club): " . $e->getMessage());
        }

        // --- 4) Lire le CSV/XLSX, créer les membres, logger + email chacun
        $rows = $this->readMembersFile($csvPath);
        // Filtrer les lignes vides
        $rows = array_values(
            array_filter($rows, static fn(array $r) => array_filter($r, static fn($v) => $v !== null && $v !== ""))
        );

        if (!$rows) {
            $io->warning("Aucun membre détecté (après en-tête / filtrage).");
        }

        $io->section("Traitement des membres (création en base + email)");

        $memberCounter = 0;
        foreach ($rows as $idx => $data) {
            // Colonnes attendues: 0=Prénom, 1=Nom, 2=Sexe, 3=DateNaissance, 4=Adresse, 5=CP, 6=Ville,
            // 7=Email, 8=Téléphone
            $first  = trim((string)($data[0] ?? ""));
            $last   = trim((string)($data[1] ?? ""));
            $sex    = trim((string)($data[2] ?? ""));
            $birth  = trim((string)($data[3] ?? ""));
            $addr   = trim((string)($data[4] ?? ""));
            $zip    = trim((string)($data[5] ?? ""));
            $city   = trim((string)($data[6] ?? ""));
            $email  = trim((string)($data[7] ?? ""));
            $phone  = trim((string)($data[8] ?? ""));

            $memberCounter++;
            $licenceNumber = $this->generateLicenceNumber($memberCounter);

            $member = new Member();
            $member->setFirstName($first);
            $member->setLastName($last);
            $member->setSex($sex);
            $member->setBirthDate($birth);
            $member->setAddress($addr ?: null);
            $member->setPostalCode($zip ?: null);
            $member->setCity($city ?: null);
            $member->setEmail($email ?: null);
            $member->setPhoneNumber($phone ?: null);
            $member->setCountry("France");
            $member->setClub($club);
            $member->setSportSeason($currentSeason);
            $member->setLicenceNumber($licenceNumber);

            $this->em->persist($member);
            $io->writeln("→ Membre persisté (sera flush en base).");

            $io->note(sprintf(
                "Membre #%d — %s %s | Sexe: %s | Naissance: %s | Email: %s | Tel: %s | Club: %s | Saison: %s" .
                "| Licence: %s",
                $idx + 1,
                $member->getFirstName(),
                $member->getLastName(),
                $member->getSex() ?: "-",
                $member->getBirthDate() ?: "-",
                $member->getEmail() ?: "-",
                $member->getPhoneNumber() ?: "-",
                $club->getClubNumber(),
                $member->getSportSeason(),
                $member->getLicenceNumber()
            ));

            // Email destinataire pour la licence membre
            $memberEmail = $email !== "" ? $email : self::MEMBER_FALLBACK_EMAIL;

            try {
                $memberData = [
                    "membre_prenom" => $member->getFirstName(),
                    "membre_nom"    => $member->getLastName(),
                    "membre_date"   => $member->getBirthDate(),
                    "membre_sexe"   => $member->getSex(),
                    "licence"       => $member->getLicenceNumber(),
                    "logo"          => $logoPath,
                    "club_name"     => $club->getName() ?? "Club",
                    "numero"        => $club->getClubNumber() ?? $clubNum,
                    "season"        => $currentSeason,
                ];
                $pdf = $this->generateLicensePdf($memberData, "club-membre");
                $io->writeln("   → PDF licence membre généré: <info>$pdf</info>");
                $io->writeln("   → Envoi email licence membre à <info>$memberEmail</info>...");
                $this->sendLicenseEmail($pdf, "club-membre", $memberEmail);
                $io->writeln("→ Email licence membre envoyé à $memberEmail");
            } catch (TransportExceptionInterface $e) {
                $io->error("Erreur d’envoi email (membre): " . $e->getMessage());
            }
        }

        // Flush final pour les membres (le club a déjà été flush plus haut)
        $this->em->flush();
        $io->writeln("→ Flush final effectué sur les membres en base (total: $memberCounter).");

        $io->success("Import terminé : club + membres créés / mis à jour en base, licences générées et emails envoyés.");
        return Command::SUCCESS;
    }

    // ----------------- OUTILS PRIVÉS -----------------

    /** Retourne la liste des saisons sportives possibles. */
    private function sportSeasonChoices(): array
    {
        $endStartYear = null;
        $y = (int) date("Y");
        $endStartYear ??= $y + 2;
        $out = [];
        for ($start = 2024; $start <= $endStartYear; $start++) {
            $label = sprintf("%d-%d", $start, $start + 1);
            $out[$label] = $label;
        }
        return $out;
    }

    /** Déduit la saison courante (similaire à ta logique, mais s’appuie sur sportSeasonChoices). */
    private function getCurrentSportSeason(): string
    {
        $today = new DateTimeImmutable();
        $year = (int)$today->format("Y");
        $month = (int)$today->format("m");

        $startYear = ($month >= 7) ? $year : $year - 1;
        $label = sprintf("%d-%d", $startYear, $startYear + 1);

        $choices = $this->sportSeasonChoices();
        return $choices[$label] ?? $label;
    }

    /** Lit un CSV ou XLSX et retourne les lignes (en-tête ignorée). */
    private function readMembersFile(string $filePath): array
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === "csv") {
            return $this->getMembersFromCSV($filePath);
        }
        if (in_array($ext, ["xlsx", "xls"], true)) {
            return $this->getMembersFromXLSX($filePath);
        }
        throw new RuntimeException("Extension non supportée: .$ext (attendu: csv/xlsx/xls)");
    }

    private function getMembersFromCSV(string $filePath): array
    {
        $rows = [];
        if (($h = fopen($filePath, "rb")) === false) {
            throw new RuntimeException("Impossible d’ouvrir le CSV: $filePath");
        }
        $headerSkipped = false;
        while (($data = fgetcsv($h, 0)) !== false) {
            if (!$headerSkipped) {
                $headerSkipped = true;
                continue;
            }
            $rows[] = $data;
        }
        fclose($h);
        return $rows;
    }

    private function getMembersFromXLSX(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();
        array_shift($rows); // en-tête
        return $rows;
    }

    /** Génère un numéro de licence. */
    private function generateLicenceNumber(int $counter): string
    {
        return sprintf("SKK%04d", $counter);
    }

    /** Rend un template Twig et fabrique un PDF A6 landscape dans /public/uploads/. */
    private function generateLicensePdf(array $data, string $type): string
    {
        $data["logo"] = basename($data["logo"]);

        // Templates attendus : templates/licenses/club.html.twig et templates/licenses/club-membre.html.twig
        try {
            $html = $this->twig->render("licenses/" . $type . ".html.twig", ["data" => $data]);
        } catch (LoaderError|RuntimeError|SyntaxError $e) {
            throw new RuntimeException("Impossible de charger le template: " . $e->getMessage());
        }

        $options = new Options();
        $options->set("isHtml5ParserEnabled", true);
        $options->set("isRemoteEnabled", true);

        $dompdf = new Dompdf($options);
        $ctx = stream_context_create([
            "ssl" => [
                "verify_peer"       => false,
                "verify_peer_name"  => false,
                "allow_self_signed" => true,
            ],
        ]);
        $dompdf->setHttpContext($ctx);
        $dompdf->loadHtml($html);
        $dompdf->setPaper("A6", "landscape");
        $dompdf->render();

        $slugger = new AsciiSlugger();
        $filename = sprintf(
            "licence-%s-%s.pdf",
            $type,
            strtolower((string)$slugger->slug(uniqid("", true)))
        );

        $targetDir = rtrim((string)$this->params->get("kernel.project_dir"), "/")
            . "/public/uploads";
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException(sprintf("Directory '%s' was not created", $targetDir));
        }

        $filePath = $targetDir . "/" . $filename;
        file_put_contents($filePath, $dompdf->output());

        return $filePath;
    }

    /**
     * Envoie un email de licence avec le PDF attaché.
     * Templates attendus : templates/emails/club.html.twig et templates/emails/club-membre.html.twig
     * From configuré en dur pour l'instant.
     * @throws TransportExceptionInterface
     */
    private function sendLicenseEmail(string $pdfPath, string $type, string $toEmail): void
    {
        $email = (new TemplatedEmail())
            ->from("shinkyokai.academie@gmail.com")
            ->to($toEmail)
            ->subject($type === "club" ? "Votre licence Club Shinkyokai" : "Votre licence Membre Shinkyokai")
            ->htmlTemplate("emails/" . $type . ".html.twig")
            ->attachFromPath($pdfPath, "licence.pdf");

        $this->mailer->send($email);
    }

    private function generateUniqueClubNumber(?Club $lastClub): string
    {
        $lastClubNumber = $lastClub ? (int)substr($lastClub->getClubNumber(), 4) : 0;
        $newClubNumber = $lastClubNumber + 1;
        return sprintf("SHIN%04d", $newClubNumber);
    }
}
