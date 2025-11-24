<?php

namespace App\Command;

use App\Entity\Club;
use App\Entity\Member;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
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
    name: "app:club:send-member-licenses",
    description: "Pour un club donné, regénère les licences membres et envoie les emails, " .
    "avec confirmation membre par membre."
)]
class SendMemberLicensesForClubCommand extends Command
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
            ->addArgument(
                "clubNumber",
                InputArgument::REQUIRED,
                "Numéro du club (ex: SHIN0004)"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $publicBase = "https://shinkyokai.com";
        $this->requestStack->push(Request::create($publicBase));

        $io = new SymfonyStyle($input, $output);
        $helper = $this->getHelper("question");

        $clubNumber = trim((string) $input->getArgument("clubNumber"));
        $io->title("Envoi des licences membres pour le club $clubNumber");

        /** @var Club|null $club */
        $club = $this->em->getRepository(Club::class)->findOneBy([
            "clubNumber" => $clubNumber,
        ]);

        if (!$club) {
            $io->error("Aucun club trouvé avec le numéro: $clubNumber");
            return Command::FAILURE;
        }

        $io->success(sprintf(
            "Club trouvé : %s (%s)",
            $club->getName() ?? "Club",
            $club->getClubNumber()
        ));

        // Récupération / vérification du logo
        $logoFilename = $club->getLogo();
        if (!$logoFilename) {
            $io->error("Aucun logo enregistré pour ce club. Impossible de générer les licences.");
            return Command::FAILURE;
        }

        $projectDir = (string)$this->params->get("kernel.project_dir");
        $logoFullPath = rtrim($projectDir, "/") . "/public/uploads/" . ltrim($logoFilename, "/");

        if (!is_file($logoFullPath)) {
            $io->error("Logo introuvable sur le disque: $logoFullPath");
            return Command::FAILURE;
        }

        // Saison utilisée pour la licence : on prend la saison du club si définie, sinon la saison courante
        $season = $club->getSportSeason() ?: $this->getCurrentSportSeason();

        $io->section("Membres du club");
        /** @var iterable<Member> $members */
        $members = $club->getMembers();

        $sentCount = 0;
        $skippedCount = 0;

        foreach ($members as $member) {
            $licenceNumber = $member->getLicenceNumber() ?: "(sans numéro)";
            $firstName = $member->getFirstName() ?: "";
            $lastName  = $member->getLastName() ?: "";
            $rawEmail  = $member->getEmail();
            $targetEmail = $rawEmail ?: self::MEMBER_FALLBACK_EMAIL;

            $io->newLine();
            $io->writeln(str_repeat("-", 60));
            $io->writeln(sprintf(
                "Membre %s — %s %s",
                $licenceNumber,
                $firstName,
                $lastName
            ));
            $io->writeln(sprintf(
                "Email en base : %s",
                $rawEmail ?: "<aucun, fallback : " . self::MEMBER_FALLBACK_EMAIL . ">"
            ));
            $io->writeln(sprintf(
                "Email cible pour l'envoi : <info>%s</info>",
                $targetEmail
            ));

            $question = new ConfirmationQuestion(
                sprintf(
                    "Envoyer la licence au membre %s (%s %s) à l'adresse %s ? [o/N] ",
                    $licenceNumber,
                    $firstName,
                    $lastName,
                    $targetEmail
                ),
                false // défaut : ne PAS envoyer si l'utilisateur ne tape rien
            );

            if (!$helper->ask($input, $output, $question)) {
                $io->comment("→ Envoi ignoré pour ce membre.");
                $skippedCount++;
                continue;
            }

            // Construction des données pour le PDF
            $memberData = [
                "membre_prenom" => $firstName,
                "membre_nom"    => $lastName,
                "membre_date"   => $member->getBirthDate(),
                "membre_sexe"   => $member->getSex(),
                "licence"       => $licenceNumber,
                "logo"          => $logoFullPath,
                "club_name"     => $club->getName() ?? "Club",
                "numero"        => $club->getClubNumber(),
                "season"        => $season,
            ];

            try {
                $pdfPath = $this->generateLicensePdf($memberData);
                $io->writeln("   → PDF généré : <info>$pdfPath</info>");
                $this->sendLicenseEmail($pdfPath, $targetEmail);
                $io->success("   → Email envoyé à $targetEmail");
                $sentCount++;
            } catch (TransportExceptionInterface $e) {
                $io->error("Erreur d’envoi email : " . $e->getMessage());
            } catch (RuntimeException $e) {
                $io->error("Erreur de génération PDF : " . $e->getMessage());
            }
        }

        $io->newLine();
        $io->success(sprintf(
            "Traitement terminé : %d emails envoyés, %d membres ignorés.",
            $sentCount,
            $skippedCount
        ));

        return Command::SUCCESS;
    }

    // ----------------- OUTILS PRIVÉS -----------------

    /** Déduit la saison courante. */
    private function getCurrentSportSeason(): string
    {
        $today = new DateTimeImmutable();
        $year = (int)$today->format("Y");
        $month = (int)$today->format("m");

        $startYear = ($month >= 7) ? $year : $year - 1;
        return sprintf("%d-%d", $startYear, $startYear + 1);
    }

    /**
     * Rend un template Twig et fabrique un PDF A6 landscape dans /public/uploads/.
     * On reprend ta logique : templates/licenses/club.html.twig et templates/licenses/club-membre.html.twig.
     *
     * @throws RuntimeException
     */
    private function generateLicensePdf(array $data): string
    {
        $data["logo"] = basename($data["logo"]);

        try {
            $html = $this->twig->render("licenses/" . "club-membre" . ".html.twig", ["data" => $data]);
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
            "club-membre",
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
     *
     * @throws TransportExceptionInterface
     */
    private function sendLicenseEmail(string $pdfPath, string $toEmail): void
    {
        $email = (new TemplatedEmail())
            ->from("shinkyokai.academie@gmail.com")
            ->to("marc.lassort@gmail.com")
            ->subject("Votre licence Membre Shinkyokai")
            ->htmlTemplate("licenses/" . "club-membre" . ".html.twig")
            ->attachFromPath($pdfPath, "licence.pdf");

        $this->mailer->send($email);
    }
}
