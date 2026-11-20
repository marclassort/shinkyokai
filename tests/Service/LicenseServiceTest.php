<?php

namespace App\Tests\Service;

use App\Service\LicenseService;
use App\Tests\Support\RecordingMailer;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Génération et envoi des cartes d'adhérent.
 */
class LicenseServiceTest extends KernelTestCase
{
    private RecordingMailer $mailer;
    private string $outputDir;
    private LicenseService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->mailer = new RecordingMailer();
        $this->outputDir = sys_get_temp_dir() . "/skk-licences-" . uniqid();
        $this->service = new LicenseService(
            static::getContainer()->get("twig"),
            $this->mailer,
            new NullLogger(),
            static::getContainer()->getParameter("kernel.project_dir"),
            $this->outputDir,
            false, // pas de ressources distantes : les tests ne dépendent pas du réseau
            0,
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->outputDir);
        parent::tearDown();
    }

    /**
     * @return array{type: string, email: ?string, data: array, replaces: ?string}
     */
    private function card(int $i, ?string $email = "auto", ?string $replaces = null): array
    {
        return [
            "type" => "club-membre",
            "email" => $email === "auto" ? sprintf("adherent%03d@example.test", $i) : $email,
            "replaces" => $replaces,
            "data" => [
                "membre_prenom" => "Prenom$i",
                "membre_nom" => "NOM$i",
                "membre_date" => "01/01/2010",
                "membre_sexe" => "Masculin",
                "licence" => sprintf("SKK%04d", 194 + $i),
                "logo" => "logo-test.png",
                "club_name" => "Club de test",
                "numero" => "SHIN0011",
                "season" => "2026-2027",
            ],
        ];
    }

    public function testGeneratesARealPdfOutsideOfThePublicDirectory(): void
    {
        $path = $this->service->generatePdf($this->card(1)["data"], "club-membre");

        self::assertFileExists($path);
        self::assertStringNotContainsString("/public/", $path, "Les cartes contiennent des données personnelles");
        self::assertSame("%PDF", substr(file_get_contents($path), 0, 4));
    }

    public function testEveryCardTypeCanBeRendered(): void
    {
        $club = ["logo" => "logo-test.png", "club_name" => "Club", "numero" => "SHIN0011", "season" => "2026-2027",
            "address" => "1 rue", "address2" => "", "zip" => "64000", "city" => "Pau"];
        $individual = ["first_name" => "A", "last_name" => "B", "birth_date" => "01/01/2000", "sex" => "Masculin",
            "email" => "a@example.test", "licence" => "SKK0001", "season" => "2026-2027"];

        foreach (["club" => $club, "membre-individuel" => $individual] as $type => $data) {
            self::assertSame("%PDF", substr(file_get_contents($this->service->generatePdf($data, $type)), 0, 4), $type);
        }
    }

    public function testDeliversEveryCardOfALargeLotToItsOwnRecipient(): void
    {
        $cards = array_map(fn(int $i) => $this->card($i), range(1, 20));

        $result = $this->service->deliver($cards);

        self::assertSame(["sent" => 20, "failed" => 0, "skipped" => 0, "failures" => []], $result);
        self::assertCount(20, $this->mailer->sent);
        self::assertSame("adherent001@example.test", $this->mailer->recipients()[0]);
        foreach ($this->mailer->sent as $email) {
            self::assertCount(1, $email->getAttachments(), "Chaque e-mail contient exactement une carte PDF");
            self::assertSame("Votre licence Shinkyokai", $email->getSubject());
        }
    }

    public function testAFailureInTheMiddleNeverBlocksTheOtherCards(): void
    {
        // Cause du 6 novembre : un seul incident arrêtait tout le reste du lot.
        $this->mailer->failFor("adherent003@example.test");
        $cards = array_map(fn(int $i) => $this->card($i), range(1, 6));

        $result = $this->service->deliver($cards);

        self::assertSame(5, $result["sent"]);
        self::assertSame(1, $result["failed"]);
        self::assertSame(2, $result["failures"][0]["index"], "L'échec est identifié par sa position dans le lot");
        self::assertSame("adherent003@example.test", $result["failures"][0]["email"]);
        self::assertCount(5, $this->mailer->sent);
        self::assertContains("adherent006@example.test", $this->mailer->recipients(), "Les cartes suivantes partent quand même");
        self::assertSame(2, $this->mailer->attempts["adherent003@example.test"], "Un nouvel essai est tenté avant d'abandonner");
    }

    public function testATransientSmtpErrorIsRetriedOnce(): void
    {
        $this->mailer->failFor("adherent001@example.test", 1);

        $result = $this->service->deliver([$this->card(1)]);

        self::assertSame(1, $result["sent"]);
        self::assertSame(0, $result["failed"]);
    }

    public function testCardWithoutEmailIsSkippedNotFailed(): void
    {
        $result = $this->service->deliver([$this->card(1, null), $this->card(2, "")]);

        self::assertSame(0, $result["sent"]);
        self::assertSame(2, $result["skipped"]);
        self::assertSame(0, $result["failed"]);
        self::assertCount(0, $this->mailer->sent);
    }

    public function testReplacementCardExplainsThatItReplacesThePreviousOne(): void
    {
        $this->service->deliver([$this->card(1, "mika@example.test", "SKK0195")]);

        $email = $this->mailer->sent[0];
        self::assertStringContainsString("remplace la précédente", $email->getSubject());
        $body = static::getContainer()->get("twig")->render("emails/club-membre.html.twig", ["replaces" => "SKK0195"]);
        self::assertStringContainsString("SKK0195", $body);
        self::assertStringContainsString("remplace celle que vous avez reçue", $body);
    }

    public function testStandardCardDoesNotMentionAReplacement(): void
    {
        $body = static::getContainer()->get("twig")->render("emails/club-membre.html.twig", ["replaces" => null]);

        self::assertStringNotContainsString("remplace", $body);
        self::assertStringContainsString("Nous vous remercions de vous être inscrit", $body);
    }

    public function testProgressCallbackReportsEveryCard(): void
    {
        $this->mailer->failFor("adherent002@example.test");
        $events = [];

        $this->service->deliver(
            [$this->card(1), $this->card(2), $this->card(3, null)],
            static function (int $index, string $status) use (&$events): void {
                $events[$index] = $status;
            }
        );

        self::assertSame([0 => "sent", 1 => "failed", 2 => "skipped"], $events);
    }

    /**
     * Les PDF ne doivent plus faire de requêtes HTTP vers le site lui-même (cause de lenteur le 6 novembre :
     * 3 téléchargements par carte, vus dans les logs d'accès avec le User-Agent « Dompdf »).
     *
     * @dataProvider cardTemplates
     */
    public function testPdfHtmlReferencesLocalFilesOnly(string $type, array $data): void
    {
        $twig = static::getContainer()->get("twig");
        $html = $twig->render("licenses/$type.html.twig", ["data" => $data]);

        $localize = new \ReflectionMethod($this->service, "localizeAssets");
        $localized = $localize->invoke($this->service, $html, "/srv/public");

        self::assertDoesNotMatchRegularExpression(
            "#(?:src|href)=\"https?://[^\"]*(?:/img/|/uploads/|/assets/|/fonts/)#",
            $localized,
            "Une image du site est encore appelée par HTTP dans $type"
        );
        self::assertDoesNotMatchRegularExpression("#url\(['\"]?https?://[^)]*(?:/img/|/uploads/|/fonts/)#", $localized, "Une image de fond ou une police est encore appelée par HTTP dans $type");
        self::assertStringContainsString("/srv/public/img/", $localized);
    }

    public static function cardTemplates(): iterable
    {
        $base = ["logo" => "logo-test.png", "club_name" => "Club", "numero" => "SHIN0011", "season" => "2026-2027"];

        yield "carte de membre de club" => ["club-membre", $base + ["membre_prenom" => "A", "membre_nom" => "B",
            "membre_date" => "01/01/2010", "membre_sexe" => "Masculin", "licence" => "SKK0001"]];
        yield "carte de club" => ["club", $base + ["address" => "1 rue", "address2" => "", "zip" => "64000", "city" => "Pau"]];
        yield "carte individuelle" => ["membre-individuel", ["first_name" => "A", "last_name" => "B", "birth_date" => "01/01/2000",
            "sex" => "Masculin", "email" => "a@example.test", "licence" => "SKK0001", "season" => "2026-2027"]];
    }

    public function testNoPdfIsLeftOnDiskOnceTheCardIsSentOrFailed(): void
    {
        // Les cartes contiennent des données personnelles : une fois jointes à l'e-mail, aucune copie ne doit rester sur le serveur.
        $this->mailer->failFor("adherent002@example.test");

        $this->service->deliver([$this->card(1), $this->card(2), $this->card(3)]);

        self::assertSame([], glob($this->outputDir . "/*.pdf") ?: [], "Aucun PDF ne reste dans le dossier de sortie");
        $first = $this->mailer->sent[0]->getAttachments()[0];
        self::assertSame("%PDF", substr($first->getBody(), 0, 4), "Le PDF est bien contenu dans l'e-mail lui-même");
        self::assertSame("licence.pdf", $first->getFilename());
    }
}
