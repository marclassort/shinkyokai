<?php

namespace App\Tests\Support;

/**
 * Journal lisible d'une simulation : écrit en direct dans la console ET dans un fichier (var/simulation/).
 * Chaque ligne indique clairement ce qui SERAIT fait en production.
 */
final class SimulationReport
{
    private static ?self $instance = null;
    /** @var resource */
    private $file;
    public readonly string $path;
    /** @var list<array{ok: bool, label: string, detail: string}> */
    private array $checks = [];
    /** @var list<string> */
    private array $warnings = [];
    private float $startedAt;

    private function __construct()
    {
        $dir = dirname(__DIR__, 2) . "/var/simulation";
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->path = $dir . "/rapport-" . date("Ymd-His") . ".log";
        $this->file = fopen($this->path, "w");
        $this->startedAt = microtime(true);
        // Le verdict global s'affiche en toute fin d'exécution, après le résumé de PHPUnit.
        register_shutdown_function(fn() => $this->verdict());
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function title(string $title): void
    {
        $this->raw("");
        $this->raw(str_repeat("=", 110));
        $this->raw("  " . $title);
        $this->raw(str_repeat("=", 110));
    }

    public function section(string $title): void
    {
        $this->raw("");
        $this->raw("--- " . $title . " " . str_repeat("-", max(3, 104 - mb_strlen($title))));
    }

    /** Ce qui serait fait en production. */
    public function would(string $text): void
    {
        $this->raw("  [SERAIT FAIT]  " . $text);
    }

    public function info(string $text): void
    {
        $this->raw("  " . $text);
    }

    public function line(string $text): void
    {
        $this->raw("    " . $text);
    }

    /** Écrit uniquement dans le fichier de rapport (pas dans la console). */
    public function fileOnly(string $text): void
    {
        fwrite($this->file, "      " . $text . "\n");
    }

    /** Point d'attention qui n'est pas une erreur de l'application (ex. donnée douteuse dans le fichier). */
    public function warn(string $text): void
    {
        if (!in_array($text, $this->warnings, true)) {
            $this->warnings[] = $text;
        }
        $this->raw("  [ATTENTION] " . $text);
    }

    /** Détail (une ligne par adhérent, par e-mail...) : toujours dans le fichier, dans la console sauf SIMULATION_VERBOSE=0. */
    public function detail(string $text): void
    {
        $line = "      " . $text . "\n";
        fwrite($this->file, $line);
        if (getenv("SIMULATION_VERBOSE") !== "0") {
            fwrite(STDOUT, $line);
        }
    }

    /**
     * Enregistre une vérification : ✔ si elle est vraie, ✘ sinon. Ne lève pas d'exception : le verdict final les récapitule.
     */
    public function check(bool $ok, string $label, string $detail = ""): bool
    {
        $this->checks[] = ["ok" => $ok, "label" => $label, "detail" => $detail];
        $this->raw(sprintf("  %s %s%s", $ok ? "[ OK ]" : "[ECHEC]", $label, $detail !== "" ? "  —  " . $detail : ""));

        return $ok;
    }

    public function failures(): array
    {
        return array_values(array_filter($this->checks, static fn($c) => !$c["ok"]));
    }

    public function verdict(): void
    {
        $failed = $this->failures();
        $this->title(sprintf("VERDICT : %d vérification(s), %d échec(s), %d point(s) d'attention — durée %.1f s", count($this->checks), count($failed), count($this->warnings), microtime(true) - $this->startedAt));
        foreach ($failed as $check) {
            $this->raw("  [ECHEC] " . $check["label"] . ($check["detail"] !== "" ? "  —  " . $check["detail"] : ""));
        }
        foreach ($this->warnings as $warning) {
            $this->raw("  [ATTENTION] " . $warning);
        }
        $this->raw($failed ? "  => À CORRIGER AVANT LA PRODUCTION." : "  => Tout est conforme. AUCUNE action réelle n'a été effectuée (base en mémoire, e-mails interceptés, Stripe simulé).");
        $this->raw("  Rapport complet : " . $this->path);
    }

    private function raw(string $text): void
    {
        $line = $text . "\n";
        fwrite($this->file, $line);
        fwrite(STDOUT, $line);
    }
}
