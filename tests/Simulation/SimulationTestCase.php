<?php

namespace App\Tests\Simulation;

use App\Entity\Club;
use App\Entity\Member;
use App\Entity\Order;
use App\Service\LicenceNumberGenerator;
use App\Service\MemberFileReader;
use App\Tests\Support\PaymentFlowTestCase;
use App\Tests\Support\DatabaseSnapshot;
use App\Tests\Support\PdfInspector;
use App\Tests\Support\SimulationReport;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

/**
 * Socle des simulations : on exécute le VRAI code de l'application sur le VRAI fichier d'adhérents, dans un bac à sable
 * (base SQLite en mémoire, e-mails interceptés, Stripe simulé) et on écrit un rapport de ce qui serait fait en production.
 *
 * Lancement :  vendor/bin/phpunit --testsuite simulation
 * Autre fichier :  SIMULATION_FILE=/chemin/fichier.xlsx vendor/bin/phpunit --testsuite simulation
 * Console moins bavarde (le fichier de rapport reste complet) :  SIMULATION_VERBOSE=0 ...
 */
abstract class SimulationTestCase extends PaymentFlowTestCase
{
    protected const SENDER = "shinkyokai.academie@gmail.com";

    protected SimulationReport $report;
    protected string $sourceFile;
    protected string $sourceHash;
    /** @var list<array<string, string>> */
    protected array $rows = [];
    private int $failuresBefore = 0;
    /** @var list<string> fichiers déjà présents dans var/members avant le scénario */
    private array $membersDirBefore = [];

    protected function setUp(): void
    {
        $this->sourceFile = getenv("SIMULATION_FILE") ?: dirname(__DIR__, 2) . "/public/lot1-skk.xlsx";
        if (!is_file($this->sourceFile)) {
            self::markTestSkipped("Fichier d'adhérents introuvable : {$this->sourceFile} (définissez SIMULATION_FILE).");
        }

        parent::setUp();

        $this->report = SimulationReport::instance();
        $this->failuresBefore = count($this->report->failures());
        $this->membersDirBefore = glob($this->membersDir() . "/*") ?: [];
        $this->sourceHash = hash_file("sha256", $this->sourceFile);
        $this->rows = (new MemberFileReader())->read($this->sourceFile, pathinfo($this->sourceFile, PATHINFO_EXTENSION));
    }

    private function membersDir(): string
    {
        return dirname(__DIR__, 2) . "/var/members";
    }

    /**
     * L'application range chaque fichier reçu dans var/members (hors de public/). C'est ce qui serait fait en production :
     * on le journalise, puis on supprime ces copies : une simulation ne laisse rien derrière elle.
     */
    protected function reportAndCleanStoredUploads(): void
    {
        $stored = array_diff(glob($this->membersDir() . "/*") ?: [], $this->membersDirBefore);
        foreach ($stored as $file) {
            $this->report->would(sprintf("Ranger le fichier reçu dans var/members/%s (%s Ko)", basename($file), number_format(filesize($file) / 1024, 1, ",", "")));
        }
        $this->report->check(!str_contains(implode(" ", $stored), "/public/"), "Les fichiers d'adhérents reçus sont rangés HORS de public/ (non téléchargeables)");
        foreach ($stored as $file) {
            unlink($file);
        }
        if ($stored) {
            $this->report->info(sprintf("(simulation : %d copie(s) supprimée(s) de var/members)", count($stored)));
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->report)) {
            // Filet de sécurité si le scénario s'est arrêté avant le nettoyage
            foreach (array_diff(glob($this->membersDir() . "/*") ?: [], $this->membersDirBefore) as $file) {
                @unlink($file);
            }
            $this->report->check(hash_file("sha256", $this->sourceFile) === $this->sourceHash, "Le fichier source n'a pas été modifié ni déplacé");
        }
        parent::tearDown();
    }

    /** Un scénario interrompu par une erreur doit apparaître dans le verdict : jamais de « tout est conforme » trompeur. */
    protected function onNotSuccessfulTest(\Throwable $t): never
    {
        if (isset($this->report)) {
            $this->report->check(false, "Le scénario s'est ARRÊTÉ sur une erreur : " . get_class($t), $t->getMessage());
        }
        parent::onNotSuccessfulTest($t);
    }

    /** Fait échouer le test s'il y a eu au moins un [ECHEC] dans ce scénario. */
    protected function assertScenarioHasNoFailure(): void
    {
        $new = array_slice($this->report->failures(), $this->failuresBefore);
        self::assertSame([], array_map(static fn($c) => $c["label"] . " — " . $c["detail"], $new), "Vérifications en échec (voir le rapport)");
    }

    // ------------------------------------------------------------------------------------------------------------
    // Étanchéité et description du contexte
    // ------------------------------------------------------------------------------------------------------------

    protected function reportSandboxGuards(): void
    {
        $this->report->section("Garde-fous : aucune action réelle possible");
        $params = $this->entityManager->getConnection()->getParams();
        $this->report->check(($params["driver"] ?? "") === "pdo_sqlite" && !empty($params["memory"]), "Base de données = SQLite EN MÉMOIRE (votre base locale n'est pas ouverte)", "driver=" . ($params["driver"] ?? "?"));
        $this->report->check($this->mailer instanceof \App\Tests\Support\RecordingMailer, "E-mails = transport INTERCEPTÉ (aucun e-mail ne peut partir)");
        $this->report->check($this->checkout instanceof \App\Tests\Support\FakeCheckoutGateway, "Stripe = passerelle SIMULÉE (aucun appel réseau, aucun paiement)");
        $this->report->check($this->payment instanceof \App\Tests\Support\FakePaymentVerifier, "Vérification de paiement = SIMULÉE (« payé » sur demande)");
        $this->report->check(str_contains(static::getContainer()->getParameter("app.licence_output_dir"), "cache"), "PDF écrits dans un dossier temporaire du cache de test", static::getContainer()->getParameter("app.licence_output_dir"));
    }

    protected function reportFile(): void
    {
        $this->report->section("Fichier d'adhérents");
        $this->report->info("Fichier : " . $this->sourceFile);
        $this->report->info(sprintf("Taille : %s Ko — empreinte sha256 : %s…", number_format(filesize($this->sourceFile) / 1024, 1, ",", " "), substr($this->sourceHash, 0, 16)));
        $this->report->check(count($this->rows) > 0, sprintf("Lecture du fichier : %d adhérents valides (colonnes obligatoires remplies, e-mails valides)", count($this->rows)));

        $emails = array_map(static fn($r) => mb_strtolower($r["email"]), $this->rows);
        $shared = array_filter(array_count_values($emails), static fn($n) => $n > 1);
        $this->report->info(sprintf("%d adresses e-mail distinctes pour %d adhérents (%d adresses partagées entre plusieurs adhérents : un e-mail PAR carte sera envoyé)", count(array_unique($emails)), count($this->rows), count($shared)));
        $keys = array_map(static fn($r) => mb_strtolower($r["first_name"] . "|" . $r["last_name"] . "|" . $r["birth_date"]), $this->rows);
        $this->report->check(count($keys) === count(array_unique($keys)), "Aucun adhérent en double dans le fichier (même nom, prénom et date de naissance)");
        $this->reportDataQuality();
        $this->report->detail("Ligne 1 : " . $this->who($this->rows[0]));
        $this->report->detail("Ligne 2 : " . $this->who($this->rows[1] ?? $this->rows[0]));
        $this->report->detail("…");
        $this->report->detail(sprintf("Ligne %d : %s", count($this->rows), $this->who($this->rows[array_key_last($this->rows)])));
    }

    /**
     * Diagnostic sur les valeurs BRUTES du fichier (avant la mise en forme automatique des prénoms) : ce n'est pas une erreur
     * de l'application, mais ces données se retrouvent sur les cartes.
     */
    private function reportDataQuality(): void
    {
        $raw = $this->rawNames();
        if ($raw === null) {
            return;
        }

        $corrected = [];
        foreach ($raw as $i => [$first, $last]) {
            $formatted = \App\Service\NameFormatter::firstName($first);
            if ($formatted !== trim($first)) {
                $corrected[] = sprintf("ligne %d : %s → %s", $i + 1, trim($first), $formatted);
            }
        }
        foreach ($this->suspectedSwappedRows() as $rowNumber) {
            [$first, $last] = $raw[$rowNumber - 1];
            $this->report->warn(sprintf("Ligne %d (%s %s) : prénom en MAJUSCULES et nom en casse mixte : prénom et nom inversés ? — à corriger avec --swap-names=%d", $rowNumber, $last, $first, $rowNumber));
        }
        $this->report->info(sprintf("%d prénom(s) mis en forme automatiquement (majuscule initiale, jamais tout en capitales) :", count($corrected)));
        $this->report->detail(implode(" ; ", array_slice($corrected, 0, 12)) . (count($corrected) > 12 ? sprintf(" … (+%d)", count($corrected) - 12) : ""));
    }

    /**
     * Numéros (à partir de 1) des lignes dont le prénom et le nom semblent inversés : prénom tout en capitales, nom en casse mixte.
     *
     * @return list<int>
     */
    protected function suspectedSwappedRows(): array
    {
        $rows = [];
        foreach ($this->rawNames() ?? [] as $i => [$first, $last]) {
            if (mb_strtoupper($first) === $first && mb_strlen($first) > 2 && mb_strtoupper($last) !== $last) {
                $rows[] = $i + 1;
            }
        }

        return $rows;
    }

    /** @return list<array{0: string, 1: string}>|null prénom et nom bruts de chaque ligne (xlsx uniquement) */
    private function rawNames(): ?array
    {
        if (strtolower(pathinfo($this->sourceFile, PATHINFO_EXTENSION)) !== "xlsx") {
            return null;
        }
        try {
            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->sourceFile)->getActiveSheet();
            $table = $sheet->toArray(null, true, true, false);
        } catch (\Throwable) {
            return null;
        }
        $header = array_map(static fn($h) => mb_strtolower(trim((string)$h)), array_shift($table) ?? []);
        $firstCol = array_search("prénom", $header, true);
        $lastCol = array_search("nom de famille", $header, true);
        if ($firstCol === false || $lastCol === false) {
            return null;
        }

        $names = [];
        foreach ($table as $line) {
            if (!array_filter($line, static fn($v) => trim((string)$v) !== "")) {
                continue;
            }
            $names[] = [trim((string)$line[$firstCol]), trim((string)$line[$lastCol])];
        }

        return $names;
    }

    protected function who(array $row): string
    {
        return sprintf("%s %s, né(e) le %s, %s", $row["last_name"], $row["first_name"], $row["birth_date"], $row["email"]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // État initial : la base ressemble à celle de la production
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Reproduit l'état de la production le 20 novembre 2026 (voir les captures d'EasyAdmin fournies).
     *
     * @return array{shinkyobudo: Club, pau: Club, order52: Order}
     */
    protected function seedProductionLikeState(): array
    {
        $logo = $this->existingLogo();
        $shinkyobudo = $this->club("SHIN0011", "Shinkyobudo", "presidence@shinkyobudo.example", $logo);
        $pau = $this->club("SHIN0021", "Pau Académie Martiale", "pau@example.test", $logo);

        $order43 = $this->makeOrder("inscription-club", "payee", 55.0, new \DateTimeImmutable("2026-09-09 13:19"));
        $order43->setClub($shinkyobudo);
        $existant = $this->makeMember("Chloe", "SIMULE-C", "SKK0141", $shinkyobudo, $order43, "2026-2027", "05/05/1990");
        $old = $this->makeMember("Ancien", "Adherent", "SKK0194", $pau, $order43, "2026-2027", "02/02/1980");

        $order53 = $this->makeOrder("complement-affiliation", "payee", 10.0, new \DateTimeImmutable("2026-11-18 07:26"));
        $order53->setClub($pau);
        $remplace1 = $this->makeMember("Alice", "SIMULE-A", "SKK0195", $pau, $order53, "2026-2027", "03/03/1975");
        $remplace2 = $this->makeMember("Bruno", "SIMULE-B", "SKK0196", $pau, $order53, "2026-2027", "04/04/2012");
        $remplace1->setEmail("famille-simulee@example.test");
        $remplace2->setEmail("famille-simulee@example.test");

        $order52 = $this->makeOrder("complement-affiliation", "en-cours", 535.0, new \DateTimeImmutable("2026-11-06 21:57"));

        // Comme après le rattrapage SQL : tout ce qui existe déjà a reçu sa carte (sinon le cron la renverrait).
        foreach ([$shinkyobudo, $pau, $existant, $old, $remplace1, $remplace2] as $entity) {
            $entity->markCardSent(new \DateTimeImmutable("2026-09-09 15:20"));
        }
        $this->entityManager->flush();

        return ["shinkyobudo" => $shinkyobudo, "pau" => $pau, "order52" => $order52];
    }

    private function club(string $number, string $name, string $email, string $logo): Club
    {
        $club = $this->makeClub($number, $name);
        $club->setLogo($logo);
        $club->setEmail($email);

        return $club;
    }

    /** Un vrai logo de club présent dans public/uploads (les PDF affichent alors une vraie image). */
    private function existingLogo(): string
    {
        $found = glob(dirname(__DIR__, 2) . "/public/uploads/*.{png,jpg,jpeg}", GLOB_BRACE) ?: [];

        return $found ? basename($found[0]) : "logo-introuvable.png";
    }

    protected function reportDatabaseState(string $title): void
    {
        $this->report->section($title);
        $this->entityManager->clear();
        foreach ($this->entityManager->getRepository(Club::class)->findBy([], ["id" => "ASC"]) as $club) {
            $count = $this->entityManager->getRepository(Member::class)->count(["club" => $club]);
            $this->report->info(sprintf("Club %s « %s » (saison %s) : %d adhérent(s)", $club->getClubNumber(), $club->getName(), $club->getSportSeason(), $count));
        }
        $numbers = (new LicenceNumberGenerator($this->entityManager))->numbersInUse();
        $this->report->info(sprintf("Licences en base : %d — plus grand numéro : %s — prochain numéro attribuable : %s",
            count($numbers), $numbers ? LicenceNumberGenerator::format(max($numbers)) : "aucun",
            LicenceNumberGenerator::format((new LicenceNumberGenerator($this->entityManager))->nextNumber())));
        foreach ($this->entityManager->getRepository(Order::class)->findBy([], ["id" => "ASC"]) as $order) {
            $this->report->detail(sprintf("Commande #%d : %s, statut « %s », %s €", $order->getId(), $order->getOrderType(), $order->getStatus(), number_format($order->getTotalAmount(), 2, ",", "")));
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Requêtes HTTP réelles
    // ------------------------------------------------------------------------------------------------------------

    /** Copie jetable du fichier : l'application DÉPLACE le fichier reçu, on ne lui donne jamais l'original. */
    protected function uploadCopy(): UploadedFile
    {
        $copy = sys_get_temp_dir() . "/skk-upload-" . uniqid() . "." . pathinfo($this->sourceFile, PATHINFO_EXTENSION);
        copy($this->sourceFile, $copy);
        $this->tempFiles[] = $copy;

        return new UploadedFile($copy, basename($this->sourceFile), mime_content_type($copy) ?: null, null, true);
    }

    /** @return array<string, mixed> */
    protected function http(string $method, string $uri, array $params = [], array $files = []): array
    {
        $started = microtime(true);
        $this->client->request($method, $uri, $params, $files);
        $response = $this->client->getResponse();
        $body = (string)$response->getContent();
        $json = json_decode($body, true);

        $this->report->info(sprintf("%s %s → HTTP %d en %.2f s%s", $method, $uri, $response->getStatusCode(), microtime(true) - $started,
            is_array($json) ? "  " . json_encode($json, JSON_UNESCAPED_UNICODE) : ""));

        return ["status" => $response->getStatusCode(), "json" => $json, "body" => $body];
    }

    protected function runCommand(string $name, array $input): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find($name));
        $tester->execute($input, ["interactive" => false, "decorated" => false]);
        $this->report->info("$ php bin/console " . $name . " " . implode(" ", array_map(
            static fn($k, $v) => !str_starts_with((string)$k, "--") ? $v : ($v === true ? $k : $k . "=" . $v), array_keys($input), $input)));
        foreach (preg_split("/\r?\n/", rtrim($tester->getDisplay())) as $line) {
            $this->report->detail("│ " . $line);
        }

        return $tester;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Vérifications : fichier → base de données → e-mail → PDF
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Compare, ligne par ligne, les adhérents enregistrés pour une commande avec le fichier.
     *
     * @param list<Member> $members dans l'ordre d'enregistrement
     * @param int $firstRow numéro (à partir de 1) de la première ligne du fichier correspondant à $members[0]
     */
    protected function verifyMembersMatchFile(array $members, int $firstRow = 1): void
    {
        $mismatches = [];
        foreach ($members as $i => $member) {
            $row = $this->rows[$firstRow - 1 + $i] ?? null;
            if ($row === null) {
                $mismatches[] = "adhérent #" . ($i + 1) . " sans ligne correspondante";
                continue;
            }
            $actual = [$member->getFirstName(), $member->getLastName(), $member->getSex(), $member->getBirthDate(), (string)$member->getAddress(),
                (string)$member->getPostalCode(), (string)$member->getCity(), (string)$member->getEmail(), (string)$member->getPhoneNumber()];
            $expected = [$row["first_name"], $row["last_name"], $row["sex"], $row["birth_date"], $row["address"], $row["postal_code"], $row["city"], $row["email"], $row["phone"]];
            if ($actual !== $expected) {
                $mismatches[] = sprintf("ligne %d : fichier %s ≠ base %s", $firstRow + $i, json_encode($expected, JSON_UNESCAPED_UNICODE), json_encode($actual, JSON_UNESCAPED_UNICODE));
            }
        }
        $this->report->check(!$mismatches, sprintf("Base de données = fichier : %d adhérents enregistrés, tous champs identiques (prénom, nom, sexe, naissance, adresse, CP, ville, e-mail, téléphone)", count($members)), implode(" ; ", array_slice($mismatches, 0, 3)));
    }

    /**
     * Examine chaque e-mail intercepté : expéditeur, destinataire, pièce jointe, PDF (texte réel) et cohérence avec la base.
     *
     * @param list<Email> $mails
     * @param array<string, Member> $expectedByLicence adhérents qui DOIVENT recevoir une carte, par numéro de licence
     * @param string|null $testRecipient mode essai : tous les e-mails doivent aller à cette adresse (et non à celle des adhérents)
     * @return array<string, list<array<string, mixed>>> détail de chaque e-mail, par numéro de licence lu dans le PDF
     */
    protected function verifyMails(array $mails, array $expectedByLicence, string $label, ?string $testRecipient = null): array
    {
        $this->report->section("E-mails et cartes PDF — " . $label);
        $this->report->info(sprintf("%d e-mail(s) interceptés (rien n'est parti). Ils seraient envoyés depuis %s%s.", count($mails), self::SENDER, $testRecipient ? ", TOUS à l'adresse de test " . $testRecipient : ""));
        if (!PdfInspector::available()) {
            $this->report->check(false, "pdftotext/pdfinfo (poppler) indisponibles : contenu des PDF non vérifiable", "brew install poppler");
        }

        $twig = static::getContainer()->get("twig");
        $foundLicences = [];
        $details = [];
        $problems = [];
        $pdfBytes = 0;
        $started = microtime(true);

        foreach ($mails as $i => $mail) {
            $issues = [];
            $to = $mail->getTo()[0]->getAddress();
            $attachments = $mail->getAttachments();

            if (count($mail->getTo()) !== 1) {
                $issues[] = "plusieurs destinataires";
            }
            if ($mail->getFrom()[0]->getAddress() !== self::SENDER) {
                $issues[] = "expéditeur inattendu";
            }
            if (count($attachments) !== 1 || $attachments[0]->getFilename() !== "licence.pdf" || $attachments[0]->getMediaType() . "/" . $attachments[0]->getMediaSubtype() !== "application/pdf") {
                $issues[] = "pièce jointe attendue : exactement 1 fichier licence.pdf (application/pdf)";
            }

            $body = "";
            try {
                $body = $twig->render($mail->getHtmlTemplate(), $mail->getContext());
            } catch (\Throwable $e) {
                $issues[] = "le gabarit de l'e-mail ne s'affiche pas : " . $e->getMessage();
            }
            if (!str_contains($body, "ci-joint")) {
                $issues[] = "corps de l'e-mail : mention de la pièce jointe absente";
            }

            $keepable = $attachments !== [] && ($i < (int)(getenv("SIMULATION_KEEP_PDFS") === false ? 3 : getenv("SIMULATION_KEEP_PDFS")) || ($mail->getContext()["replaces"] ?? null) !== null);
            if ($keepable) {
                $this->keepSamplePdf($label, $i + 1, $attachments[0]->getBody());
            }
            $pdf = ["valid" => false, "bytes" => 0, "pages" => null, "pageSize" => null, "images" => null, "text" => "", "error" => "pas de pièce jointe"];
            if ($attachments) {
                $pdf = PdfInspector::inspect($attachments[0]->getBody());
                $pdfBytes += $pdf["bytes"];
            }
            if (!$pdf["valid"] || ($pdf["error"] !== null && PdfInspector::available())) {
                $issues[] = "PDF invalide : " . $pdf["error"];
            }
            if ($pdf["pages"] !== null && $pdf["pages"] !== 1) {
                $issues[] = "le PDF contient " . $pdf["pages"] . " pages (1 attendue)";
            }

            // Ce que dit le PDF
            $licence = preg_match("/N°\s*LICENCE\s*:\s*(SKK\d+)/u", $pdf["text"], $m) ? $m[1] : null;
            $club = preg_match("/N°\s*CLUB\s*:\s*(\S+)/u", $pdf["text"], $m) ? $m[1] : null;
            $first = preg_match("/Prénom\s*:\s*(.+)/u", $pdf["text"], $m) ? trim($m[1]) : null;
            $last = preg_match("/^\s*Nom\s*:\s*(.+)/mu", $pdf["text"], $m) ? trim($m[1]) : null;

            $verdict = "ok";
            if ($licence === null) {
                $issues[] = "numéro de licence introuvable dans le texte du PDF";
            } else {
                $foundLicences[$licence] = ($foundLicences[$licence] ?? 0) + 1;
                $member = $expectedByLicence[$licence] ?? null;
                if ($member === null) {
                    $issues[] = "licence $licence : ne correspond à aucun adhérent attendu";
                } else {
                    if (mb_strtolower((string)$first) !== mb_strtolower($member->getFirstName()) || mb_strtolower((string)$last) !== mb_strtolower($member->getLastName())) {
                        $issues[] = sprintf("nom dans le PDF « %s %s » ≠ adhérent « %s %s »", $first, $last, $member->getFirstName(), $member->getLastName());
                    }
                    $expectedTo = $testRecipient ?? (string)$member->getEmail();
                    if (mb_strtolower($to) !== mb_strtolower($expectedTo)) {
                        $issues[] = sprintf("destinataire %s ≠ %s %s", $to, $testRecipient ? "adresse de test" : "e-mail de l'adhérent", $expectedTo);
                    }
                    if ($club !== $member->getClub()?->getClubNumber()) {
                        $issues[] = sprintf("n° de club dans le PDF « %s » ≠ %s", $club, $member->getClub()?->getClubNumber());
                    }
                }
            }

            // Carte de REMPLACEMENT : l'e-mail doit le dire et citer l'ancien numéro ; une carte normale ne doit rien dire de tel.
            $replaces = $mail->getContext()["replaces"] ?? null;
            if ($replaces !== null) {
                if (!str_contains($body, $replaces) || !str_contains($body, "remplace") || !str_contains($mail->getSubject(), "remplace")) {
                    $issues[] = "carte de remplacement : l'e-mail n'explique pas qu'elle remplace $replaces";
                }
            } elseif (str_contains($mail->getSubject(), "remplace") || str_contains($body, "remplace celle")) {
                $issues[] = "mention de remplacement dans une carte normale";
            }

            $details[$licence ?? "?"][] = [
                "to" => $to, "subject" => $mail->getSubject(), "bytes" => $pdf["bytes"], "pages" => $pdf["pages"], "pageSize" => $pdf["pageSize"],
                "pdfName" => trim(($first ?? "?") . " " . ($last ?? "?")), "pdfClub" => $club, "issues" => $issues,
                "replaces" => $mail->getContext()["replaces"] ?? null, "test" => $testRecipient !== null,
            ];

            $this->report->fileOnly(sprintf(
                "#%03d → %-34s %-8s %-30s PDF %s Ko, %s page, %s, %s image(s)  %s%s",
                $i + 1, $to, $licence ?? "?", trim(($last ?? "?") . " " . ($first ?? "")), number_format($pdf["bytes"] / 1024, 1, ",", ""),
                $pdf["pages"] ?? "?", $pdf["pageSize"] ?? "format ?", $pdf["images"] ?? "?",
                $issues ? "✘ " . implode(" ; ", $issues) : "✔",
                str_contains($mail->getSubject(), "remplace") ? "  [carte de REMPLACEMENT]" : ""
            ));
            if ($issues) {
                $problems[] = sprintf("e-mail #%d (%s) : %s", $i + 1, $to, implode(" ; ", $issues));
            }
        }

        $this->report->check(!$problems, sprintf("Chaque e-mail : bon expéditeur, 1 seul destinataire = e-mail de l'adhérent, pièce jointe licence.pdf valide (1 page), texte du PDF = adhérent attendu (%d e-mails, %.1f s d'analyse)", count($mails), microtime(true) - $started), implode(" | ", array_slice($problems, 0, 3)));

        $duplicates = array_keys(array_filter($foundLicences, static fn($n) => $n > 1));
        $this->report->check(!$duplicates, "Aucune carte envoyée en double (une licence ne figure que dans un seul e-mail)", implode(", ", $duplicates));

        $missing = array_diff(array_keys($expectedByLicence), array_keys($foundLicences));
        $unexpected = array_diff(array_keys($foundLicences), array_keys($expectedByLicence));
        $this->report->check(!$missing, sprintf("Aucun adhérent oublié : les %d adhérents attendus ont tous une carte", count($expectedByLicence)), "sans carte : " . implode(", ", array_slice($missing, 0, 10)));
        $this->report->check(!$unexpected, "Aucune carte pour un adhérent qui ne devait pas en recevoir", "en trop : " . implode(", ", array_slice($unexpected, 0, 10)));
        if ($mails) {
            $this->report->info(sprintf("Poids total des PDF : %s Ko (%s Ko en moyenne)", number_format($pdfBytes / 1024, 0, ",", " "), number_format($pdfBytes / count($mails) / 1024, 1, ",", "")));
        }

        return $details;
    }

    /** Conserve quelques cartes pour les ouvrir à l'œil (var/simulation/pdf/, ignoré par git). */
    private function keepSamplePdf(string $label, int $number, string $content): void
    {
        $dir = dirname(__DIR__, 2) . "/var/simulation/pdf";
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $slug = trim(preg_replace("/[^a-z0-9]+/", "-", strtolower(iconv("UTF-8", "ASCII//TRANSLIT", $label) ?: $label)), "-");
        $path = sprintf("%s/%s-%s-carte%03d.pdf", $dir, date("His"), substr($slug, 0, 40), $number);
        file_put_contents($path, $content);
        $this->report->detail("(exemple conservé pour relecture visuelle : " . str_replace(dirname(__DIR__, 2) . "/", "", $path) . ")");
    }

    /** @return list<Member> adhérents d'une commande, dans l'ordre d'enregistrement */
    protected function membersOfOrder(int $orderId): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(Member::class)->findBy(["commande" => $orderId], ["id" => "ASC"]);
    }

    /** @return array<string, Member> */
    protected function byLicence(array $members): array
    {
        $map = [];
        foreach ($members as $member) {
            $map[$member->getLicenceNumber()] = $member;
        }

        return $map;
    }

    protected function reportLicenceNumbers(array $members, ?int $expectedFirst = null): void
    {
        $numbers = array_map(static fn(Member $m) => LicenceNumberGenerator::parse($m->getLicenceNumber()), $members);
        $contiguous = $numbers === range($numbers[0], $numbers[0] + count($numbers) - 1);
        $this->report->check(count($numbers) === count(array_unique($numbers)), "Aucun doublon dans les numéros de licence attribués");
        $this->report->check($contiguous, sprintf("Numéros consécutifs dans l'ordre du fichier : %s → %s", LicenceNumberGenerator::format($numbers[0]), LicenceNumberGenerator::format(end($numbers))));
        if ($expectedFirst !== null) {
            $this->report->check($numbers[0] === $expectedFirst, sprintf("La numérotation commence bien à %s", LicenceNumberGenerator::format($expectedFirst)), "constaté : " . LicenceNumberGenerator::format($numbers[0]));
        }
        $all = (new LicenceNumberGenerator($this->entityManager))->numbersInUse();
        $this->report->check(count($all) === count(array_unique($all)), "Aucun doublon de numéro de licence dans toute la base");
    }

    // ------------------------------------------------------------------------------------------------------------
    // Journal des modifications de la base de données (avant / après)
    // ------------------------------------------------------------------------------------------------------------

    private const COLUMN_LABELS = [
        "first_name" => "prénom", "last_name" => "nom", "sex" => "sexe", "birth_date" => "date de naissance", "address" => "adresse",
        "postal_code" => "code postal", "city" => "ville", "country" => "pays", "email" => "e-mail", "phone_number" => "téléphone",
        "licence_number" => "n° de licence", "sport_season" => "saison", "club_id" => "club", "commande_id" => "commande",
        "card_sent_at" => "carte envoyée le", "card_attempts" => "essais d'envoi", "card_last_attempt_at" => "dernier essai d'envoi",
        "card_last_error" => "dernière erreur d'envoi", "card_replaces" => "carte qui remplace l'ancien n°", "status" => "statut",
        "total_amount" => "montant (€)", "order_type" => "type de commande", "created_at" => "créée le", "name" => "nom du club",
        "club_number" => "n° de club", "president_name" => "président", "treasurer_name" => "trésorier", "logo" => "logo",
    ];

    protected function snapshot(): array
    {
        return DatabaseSnapshot::take($this->entityManager->getConnection());
    }

    /** Valeur lisible : les identifiants de club et de commande sont remplacés par leur numéro. */
    private function show(string $column, mixed $value, array $snapshot): string
    {
        if ($value === null) {
            return "(vide)";
        }
        if ($column === "club_id") {
            return ($snapshot["club"][$value]["club_number"] ?? "?") . " (" . ($snapshot["club"][$value]["name"] ?? "?") . ")";
        }
        if ($column === "commande_id") {
            return "commande #" . $value . " (" . ($snapshot["order"][$value]["order_type"] ?? "?") . ")";
        }

        return "« " . $value . " »";
    }

    private function describeRow(string $table, array $row, array $snapshot): string
    {
        return match ($table) {
            "club" => sprintf("club %s « %s »", $row["club_number"], $row["name"]),
            "order" => sprintf("commande #%d (%s, statut « %s », %s €)", $row["id"], $row["order_type"], $row["status"], number_format((float)$row["total_amount"], 2, ",", "")),
            default => sprintf("adhérent #%d %s %s (%s)", $row["id"], $row["first_name"], $row["last_name"], $row["licence_number"] ?? "sans licence"),
        };
    }

    /**
     * Ce qu'une opération a écrit en base : ce qui est ajouté, ce qui est modifié (colonne par colonne, ancienne → nouvelle valeur)
     * et ce qui n'a pas bougé.
     *
     * @param array<string, list<array<string, mixed>>> $mailDetails détail des e-mails (voir verifyMails), pour rappeler les cartes envoyées
     */
    protected function reportDatabaseChanges(string $title, array $before, array $after, array $mailDetails = []): array
    {
        $this->report->section($title);
        $diff = DatabaseSnapshot::diff($before, $after);
        $labels = ["club" => "Clubs", "order" => "Commandes", "member" => "Adhérents"];

        foreach (["club", "order", "member"] as $table) {
            $d = $diff[$table];
            $this->report->info(sprintf("%-10s : %d ajouté(s), %d modifié(s), %d supprimé(s), %d inchangé(s)", $labels[$table], count($d["created"]), count($d["modified"]), count($d["deleted"]), $d["unchanged"]));

            foreach ($d["modified"] as $id => $changes) {
                $this->report->line(sprintf("MODIFIÉ  %s", $this->describeRow($table, $after[$table][$id], $after)));
                foreach ($changes as $column => [$old, $new]) {
                    $this->report->detail(sprintf("%-32s %s  →  %s", (self::COLUMN_LABELS[$column] ?? $column) . " :", $this->show($column, $old, $before), $this->show($column, $new, $after)));
                }
                $licence = $after[$table][$id]["licence_number"] ?? null;
                foreach ($mailDetails[$licence] ?? [] as $mail) {
                    if (!$mail["test"]) {
                        $this->report->detail(sprintf("e-mail de remplacement → %s, objet « %s »", $mail["to"], $mail["subject"]));
                    }
                }
            }
            if ($table !== "member") {
                foreach ($d["created"] as $row) {
                    $this->report->line("AJOUTÉ   " . $this->describeRow($table, $row, $after));
                }
            } elseif ($d["created"]) {
                $ids = array_keys($d["created"]);
                $this->report->line(sprintf("AJOUTÉ   %d adhérents (#%d à #%d) : le détail de chacun figure dans le journal ligne par ligne", count($ids), min($ids), max($ids)));
            }
            foreach ($d["deleted"] as $row) {
                $this->report->line("SUPPRIMÉ " . $this->describeRow($table, $row, $before));
            }
            if ($table === "club") {
                foreach ($after["club"] as $id => $club) {
                    if (!isset($d["modified"][$id]) && !isset($d["created"][$id])) {
                        $this->report->detail(sprintf("INCHANGÉ club %s « %s » : aucune information du club n'est modifiée", $club["club_number"], $club["name"]));
                    }
                }
            }
        }

        return $diff;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Journal ligne par ligne du fichier
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Pour CHAQUE ligne du fichier : ce qui est écrit en base (toutes les informations), les transformations appliquées,
     * et l'e-mail (adresse, objet, PDF relu) ou la raison de son absence. Écrit aussi un CSV ouvrable dans Excel.
     *
     * @param list<Member> $members adhérents créés, dans l'ordre des lignes du fichier
     * @param array<string, list<array<string, mixed>>> $mailDetails e-mails réels, par numéro de licence
     * @param array<string, list<array<string, mixed>>> $testDetails e-mails du mode essai, par numéro de licence
     * @param array<int, int> $existingMemberIds adhérents déjà en base avant l'opération (clé = id)
     */
    protected function reportPerRowJournal(string $scenario, array $members, array $before, array $after, array $mailDetails, array $testDetails = [], int $firstRow = 1): void
    {
        $this->report->section("JOURNAL LIGNE PAR LIGNE — " . $scenario);
        $this->report->info("Pour chaque ligne du fichier : ce qui serait écrit en base de données, et ce qui serait envoyé par e-mail (rien n'est réellement écrit ni envoyé).");

        $raw = $this->rawNames() ?? [];
        $csv = [["Ligne", "Nom (fichier)", "Prénom (fichier)", "Nom (base)", "Prénom (base)", "Action en base", "N° adhérent (id)", "N° de licence", "Sexe", "Date de naissance", "Adresse", "Code postal", "Ville", "E-mail de l'adhérent", "Téléphone", "Club", "Commande", "Saison", "Transformations", "Carte", "E-mail envoyé à", "Objet de l'e-mail", "PDF (Ko)", "PDF relu (nom / club)", "Contrôle"]];
        $total = count($this->rows);

        foreach ($members as $i => $member) {
            $rowNumber = $firstRow + $i;
            $file = $this->rows[$rowNumber - 1];
            $rawFirst = $raw[$rowNumber - 1][0] ?? $file["first_name"];
            $rawLast = $raw[$rowNumber - 1][1] ?? $file["last_name"];
            $db = $after["member"][$member->getId()] ?? null;
            $existed = isset($before["member"][$member->getId()]);
            $licence = $db["licence_number"] ?? $member->getLicenceNumber();

            $transformations = [];
            if ($db !== null && mb_strtolower($rawFirst) === mb_strtolower($db["last_name"]) && mb_strtolower($rawLast) === mb_strtolower($db["first_name"])) {
                $transformations[] = sprintf("nom et prénom inversés dans le fichier, remis dans l'ordre (prénom « %s », nom « %s »)", $db["first_name"], $db["last_name"]);
            } elseif ($db !== null && $rawFirst !== $db["first_name"]) {
                $transformations[] = sprintf("prénom mis en forme : « %s » → « %s »", $rawFirst, $db["first_name"]);
            }

            // Carte
            $mails = array_values(array_filter($mailDetails[$licence] ?? [], static fn($m) => !$m["test"]));
            $tests = $testDetails[$licence] ?? [];
            $sentAt = $db["card_sent_at"] ?? null;
            if ($mails) {
                $cardLine = sprintf("E-mail à %s, objet « %s », pièce jointe licence.pdf (%s Ko, %s page, %s)", $mails[0]["to"], $mails[0]["subject"], number_format($mails[0]["bytes"] / 1024, 1, ",", ""), $mails[0]["pages"] ?? "?", $mails[0]["pageSize"] ?? "format ?");
                $pdfLine = sprintf("PDF relu : « %s », club %s, licence %s", $mails[0]["pdfName"], $mails[0]["pdfClub"] ?? "?", $licence);
                $control = $mails[0]["issues"] ? "✘ " . implode(" ; ", $mails[0]["issues"]) : "✔ conforme";
                $cardState = "e-mail envoyé";
            } elseif ($sentAt !== null) {
                $cardLine = sprintf("AUCUN e-mail : carte déjà envoyée le %s (enregistré en base, ne sera pas renvoyée)", $sentAt);
                $pdfLine = null;
                $control = "carte déjà envoyée";
                $cardState = "déjà envoyée le " . $sentAt;
            } else {
                $cardLine = "AUCUN e-mail pendant ce test : carte « en attente » (partira avec app:licences:envoyer)";
                $pdfLine = null;
                $control = "en attente";
                $cardState = "en attente";
            }

            $club = $db ? $this->show("club_id", $db["club_id"], $after) : "";
            $order = $db ? "commande #" . $db["commande_id"] : "";

            $this->report->detail("");
            $this->report->detail(sprintf("Ligne %03d/%d — %s %s", $rowNumber, $total, $db["last_name"] ?? $rawLast, $db["first_name"] ?? $rawFirst));
            $this->report->detail(sprintf("  FICHIER : prénom « %s » · nom « %s » · %s · né(e) le %s · %s, %s %s · %s · %s", $rawFirst, $rawLast, $file["sex"], $file["birth_date"], $file["address"] ?: "(sans adresse)", $file["postal_code"], $file["city"], $file["email"], $file["phone"] ?: "(sans téléphone)"));
            if ($db !== null) {
                $this->report->detail(sprintf("  BASE    : %s adhérent #%d — prénom « %s », nom « %s », %s, né(e) le %s, %s, %s %s, %s, tél %s", $existed ? "DÉJÀ PRÉSENT" : "AJOUTÉ", $db["id"], $db["first_name"], $db["last_name"], $db["sex"], $db["birth_date"], $db["address"] ?: "(sans adresse)", $db["postal_code"], $db["city"], $db["email"], $db["phone_number"] ?: "(aucun)"));
                $this->report->detail(sprintf("            licence %s · %s · %s · saison %s · pays %s", $db["licence_number"], $club, $order, $db["sport_season"], $db["country"]));
            }
            foreach ($transformations as $t) {
                $this->report->detail("  ≈ " . $t);
            }
            $this->report->detail("  CARTE   : " . $cardLine);
            if ($pdfLine) {
                $this->report->detail("            " . $pdfLine . "  →  " . $control);
            }
            foreach ($tests as $t) {
                $this->report->detail(sprintf("            (aperçu : cette carte a aussi été envoyée en mode essai à %s)", $t["to"]));
            }

            $csv[] = [$rowNumber, $rawLast, $rawFirst, $db["last_name"] ?? "", $db["first_name"] ?? "", $existed ? "déjà présent" : "AJOUTÉ", $db["id"] ?? "", $licence, $db["sex"] ?? "", $db["birth_date"] ?? "", $db["address"] ?? "", $db["postal_code"] ?? "", $db["city"] ?? "", $db["email"] ?? "", $db["phone_number"] ?? "", $club, $order, $db["sport_season"] ?? "", implode(" ; ", $transformations), $cardState, $mails[0]["to"] ?? "", $mails[0]["subject"] ?? "", isset($mails[0]) ? number_format($mails[0]["bytes"] / 1024, 1, ",", "") : "", $mails ? ($mails[0]["pdfName"] . " / " . ($mails[0]["pdfClub"] ?? "?")) : "", $control];
        }

        // Adhérents modifiés mais absents du fichier (ex. cartes de remplacement)
        $inFile = array_map(static fn(Member $m) => $m->getId(), $members);
        foreach (DatabaseSnapshot::diff($before, $after)["member"]["modified"] as $id => $changes) {
            if (in_array($id, $inFile, true)) {
                continue;
            }
            $row = $after["member"][$id];
            $mails = array_values(array_filter($mailDetails[$row["licence_number"]] ?? [], static fn($m) => !$m["test"]));
            $csv[] = ["(hors fichier)", $row["last_name"], $row["first_name"], $row["last_name"], $row["first_name"], "MODIFIÉ", $id, $row["licence_number"], $row["sex"], $row["birth_date"], $row["address"], $row["postal_code"], $row["city"], $row["email"], $row["phone_number"], $this->show("club_id", $row["club_id"], $after), "commande #" . $row["commande_id"], $row["sport_season"], implode(" ; ", array_map(fn($c, $v) => (self::COLUMN_LABELS[$c] ?? $c) . " : " . $this->show($c, $v[0], $before) . " → " . $this->show($c, $v[1], $after), array_keys($changes), $changes)), $mails ? "e-mail de remplacement envoyé" : "", $mails[0]["to"] ?? "", $mails[0]["subject"] ?? "", isset($mails[0]) ? number_format($mails[0]["bytes"] / 1024, 1, ",", "") : "", $mails ? $mails[0]["pdfName"] . " / " . ($mails[0]["pdfClub"] ?? "?") : "", $mails && !$mails[0]["issues"] ? "✔ conforme" : ($mails ? "✘" : "")];
        }

        $path = $this->writeCsv($scenario, $csv);
        $this->report->info("");
        $this->report->info(sprintf("Journal complet (%d lignes) exporté pour Excel : %s", count($csv) - 1, str_replace(dirname(__DIR__, 2) . "/", "", $path)));
    }

    /** @param list<list<mixed>> $rows */
    private function writeCsv(string $scenario, array $rows): string
    {
        $dir = dirname(__DIR__, 2) . "/var/simulation";
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $slug = trim(preg_replace("/[^a-z0-9]+/", "-", strtolower(iconv("UTF-8", "ASCII//TRANSLIT", $scenario) ?: $scenario)), "-");
        $path = sprintf("%s/journal-par-ligne-%s-%s.csv", $dir, date("Ymd-His"), substr($slug, 0, 40));
        $handle = fopen($path, "w");
        fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8 : Excel affiche correctement les accents
        foreach ($rows as $row) {
            fputcsv($handle, $row, ";", "\"", "");
        }
        fclose($handle);

        return $path;
    }
}
