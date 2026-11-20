<?php

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base des tests qui ont besoin de Symfony et de la base de données (SQLite en mémoire, schéma recréé à chaque test).
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    use DatabaseFixtures;

    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get("doctrine")->getManager();
        self::assertSandboxDatabase($this->entityManager);
        $this->createSchema();
    }

    /**
     * Garde-fou : on refuse de continuer si la connexion n'est pas la base SQLite en mémoire (jamais la base locale).
     */
    protected static function assertSandboxDatabase(\Doctrine\ORM\EntityManagerInterface $entityManager): void
    {
        $params = $entityManager->getConnection()->getParams();
        if (($params["driver"] ?? "") !== "pdo_sqlite" || empty($params["memory"])) {
            throw new \RuntimeException("ARRÊT DE SÉCURITÉ : les tests doivent utiliser SQLite en mémoire, pas " . ($params["driver"] ?? "?") . " " . ($params["dbname"] ?? ""));
        }
    }

    protected function createSchema(): void
    {
        $tool = new SchemaTool($this->entityManager);
        $tool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }
}
