<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

/**
 * Génère les cartes d'adhérent (PDF) et les envoie par e-mail.
 *
 * Conçu pour être appelé APRÈS l'enregistrement en base : une erreur d'envoi pour un adhérent
 * ne bloque jamais les autres et est journalisée.
 *
 * Format d'une carte : ["type" => ..., "email" => ..., "data" => [...], "replaces" => ?string]
 * ("replaces" : ancien numéro de licence, si cette carte en remplace une autre déjà envoyée).
 */
class LicenseService
{
    private const FROM = "shinkyokai.academie@gmail.com";

    public function __construct(
        private readonly Environment $twig,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire("%kernel.project_dir%")] private readonly string $projectDir,
        #[Autowire("%app.licence_output_dir%")] private readonly string $outputDir,
        #[Autowire("%app.pdf_remote_enabled%")] private readonly bool $remoteEnabled = true,
        #[Autowire("%app.mail_retry_delay%")] private readonly int $retryDelaySeconds = 2,
    ) {
    }

    /**
     * @param list<array{type: string, email: ?string, data: array, replaces?: ?string}> $cards
     * @param (callable(int, string, array, ?string): void)|null $progress appelé après chaque carte
     *        avec (index, "sent"|"failed"|"skipped", carte, message d'erreur)
     * @return array{sent: int, failed: int, skipped: int, failures: list<array{index: int, email: ?string, error: string}>}
     */
    public function deliver(array $cards, ?callable $progress = null): array
    {
        $sent = $failed = $skipped = 0;
        $failures = [];

        foreach (array_values($cards) as $index => $card) {
            $email = $card["email"] ?? null;
            if (!$email) {
                $skipped++;
                $this->logger->warning("Carte non envoyée : aucune adresse e-mail.", ["data" => $card["data"] ?? []]);
                $progress && $progress($index, "skipped", $card, null);
                continue;
            }

            $pdfPath = null;
            try {
                if ($card["type"] !== "atelier-culturel") {
                    $pdfPath = $this->generatePdf($card["data"], $card["type"]);
                }
                $this->sendWithRetry($email, $card["type"], $pdfPath, $card["replaces"] ?? null);
                $sent++;
                $progress && $progress($index, "sent", $card, null);
            } catch (\Throwable $e) {
                $failed++;
                $failures[] = ["index" => $index, "email" => $email, "error" => $e->getMessage()];
                $this->logger->error("Échec d'envoi de carte d'adhérent.", [
                    "email" => $email,
                    "type" => $card["type"],
                    "licence" => $card["data"]["licence"] ?? null,
                    "error" => $e->getMessage(),
                ]);
                $progress && $progress($index, "failed", $card, $e->getMessage());
            } finally {
                // Le PDF est déjà dans l'e-mail : on ne garde aucune copie sur le disque (données personnelles, 400 Ko par carte).
                if ($pdfPath !== null) {
                    @unlink($pdfPath);
                }
            }
        }

        $this->logger->info("Envoi des cartes terminé.", compact("sent", "failed", "skipped"));

        return compact("sent", "failed", "skipped", "failures");
    }

    /**
     * Génère le PDF dans le dossier de sortie (var/licences, jamais dans public/ : données personnelles).
     * deliver() le supprime dès qu'il est joint à l'e-mail.
     */
    public function generatePdf(array $data, string $type): string
    {
        $publicDir = $this->projectDir . "/public";

        $html = $this->twig->render("licenses/" . $type . ".html.twig", ["data" => $data]);
        $html = $this->localizeAssets($html, $publicDir);

        $options = new Options();
        $options->set("isHtml5ParserEnabled", true);
        $options->set("isRemoteEnabled", $this->remoteEnabled);
        $options->set("chroot", $publicDir);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper("A6", "landscape");
        $dompdf->render();

        (new Filesystem())->mkdir($this->outputDir);

        $path = sprintf("%s/licence-%s-%s.pdf", $this->outputDir, $type, uniqid());
        file_put_contents($path, $dompdf->output());

        return $path;
    }

    /**
     * Les ressources du site (logos, polices, images de fond) sont lues sur le disque plutôt que
     * par des requêtes HTTP du serveur vers lui-même : plus rapide, et sans dépendance réseau.
     */
    private function localizeAssets(string $html, string $publicDir): string
    {
        $host = '(?:https?://[^"\'/)\s]+)?';
        $folders = "(?:img|uploads|assets|fonts)";

        // src="..." / href="..."
        $html = preg_replace(
            '#(src|href)="' . $host . '/?(' . $folders . '/[^"]+)"#',
            '$1="' . $publicDir . '/$2"',
            $html
        ) ?? $html;

        // url("...") / url('...') / url(...) dans le CSS (image de fond, polices)
        return preg_replace(
            '#url\((["\']?)' . $host . '/?(' . $folders . '/[^"\')]+)\1\)#',
            'url($1' . $publicDir . '/$2$1)',
            $html
        ) ?? $html;
    }

    private function sendWithRetry(string $to, string $type, ?string $pdfPath, ?string $replaces): void
    {
        try {
            $this->send($to, $type, $pdfPath, $replaces);
        } catch (\Throwable) {
            // Un seul nouvel essai (limitation de débit SMTP, coupure brève)
            if ($this->retryDelaySeconds > 0) {
                sleep($this->retryDelaySeconds);
            }
            $this->send($to, $type, $pdfPath, $replaces);
        }
    }

    private function send(string $to, string $type, ?string $pdfPath, ?string $replaces): void
    {
        $email = (new TemplatedEmail())->from(self::FROM)->to($to);

        if ($type === "atelier-culturel") {
            $email
                ->subject("Vous vous êtes inscrit à un atelier sur le site de Shinkyokai")
                ->htmlTemplate("emails/atelier-culturel.html.twig")
                ->context([]);
        } else {
            $email
                ->subject($replaces
                    ? "Votre licence Shinkyokai : cette carte remplace la précédente"
                    : "Votre licence Shinkyokai")
                ->htmlTemplate("emails/" . $type . ".html.twig")
                ->context(["replaces" => $replaces])
                ->attach((string)file_get_contents($pdfPath), "licence.pdf", "application/pdf");
        }

        $this->mailer->send($email);
    }
}
