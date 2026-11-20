<?php

namespace App\Service;

use App\Entity\Club;
use App\Entity\Member;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Envoie les cartes en attente et mémorise le résultat pour chacune, carte par carte.
 *
 * Une carte est « en attente » si l'adhérent (ou le club) appartient à une commande payée, a une adresse e-mail,
 * et n'a pas encore été marqué comme envoyé. Le résultat est enregistré APRÈS CHAQUE carte : si le processus est
 * interrompu (limite de temps de l'hébergeur, panne), rien n'est perdu ni envoyé deux fois.
 */
class CardSender
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LicenseService $licenseService,
        private readonly MemberRegistrar $registrar,
        #[Autowire("%app.card_max_attempts%")] private readonly int $maxAttempts = 5,
        #[Autowire("%app.card_retry_minutes%")] private readonly int $retryMinutes = 10,
        #[Autowire("%app.licence_output_dir%")] private readonly string $lockDir = "",
    ) {
    }

    /**
     * Verrou commun à tout ce qui envoie des cartes (cron, régularisation) : une seule exécution à la fois,
     * pour qu'une carte ne parte jamais deux fois.
     *
     * @param bool $wait true = attend la fin de l'exécution en cours ; false = renonce tout de suite
     * @return resource|null le verrou (à rendre avec releaseLock), ou null s'il est déjà pris
     */
    public function acquireLock(bool $wait = false)
    {
        $dir = $this->lockDir ?: sys_get_temp_dir();
        (new \Symfony\Component\Filesystem\Filesystem())->mkdir($dir);

        $handle = fopen($dir . "/.envoyer.lock", "c");
        if (!$handle || !flock($handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            $handle && fclose($handle);
            return null;
        }

        return $handle;
    }

    /** @param resource $handle */
    public function releaseLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Cartes à envoyer maintenant (clubs d'abord, puis adhérents, par ordre d'enregistrement).
     * Les cartes en échec ne sont retentées qu'après le délai d'attente, et abandonnées après le nombre d'essais maximal.
     *
     * @return list<array{entity: Club|Member, card: array}>
     */
    public function pending(int $limit, ?DateTimeImmutable $now = null): array
    {
        $retryBefore = ($now ?? new DateTimeImmutable())->modify("-{$this->retryMinutes} minutes");

        $items = [];
        foreach ($this->ready($this->eligibleClubs(), "c", $retryBefore, $limit) as $club) {
            $items[] = ["entity" => $club, "card" => $this->registrar->clubCardFor($club)];
        }
        foreach ($this->ready($this->eligibleMembers(), "m", $retryBefore, $limit - count($items)) as $member) {
            $items[] = ["entity" => $member, "card" => $this->registrar->cardForMember($member)];
        }

        return $items;
    }

    /**
     * Photographie de la file d'envoi.
     *
     * @return array{pending: int, retrying: int, abandoned: int}
     *         pending : jamais tentées ; retrying : en échec, nouvel essai prévu ; abandoned : trop d'échecs
     */
    public function status(): array
    {
        $status = ["pending" => 0, "retrying" => 0, "abandoned" => 0];

        foreach ([[$this->eligibleClubs(), "c"], [$this->eligibleMembers(), "m"]] as [$builder, $alias]) {
            $row = $builder->select(
                "SUM(CASE WHEN $alias.cardAttempts = 0 THEN 1 ELSE 0 END) AS pending",
                "SUM(CASE WHEN $alias.cardAttempts > 0 AND $alias.cardAttempts < :max THEN 1 ELSE 0 END) AS retrying",
                "SUM(CASE WHEN $alias.cardAttempts >= :max THEN 1 ELSE 0 END) AS abandoned"
            )->setParameter("max", $this->maxAttempts)->getQuery()->getSingleResult();

            foreach ($status as $key => $value) {
                $status[$key] = $value + (int)$row[$key];
            }
        }

        return $status;
    }

    /**
     * @param iterable<array{entity: Club|Member, card: array}> $items
     * @param (callable(Club|Member, string, ?string): void)|null $progress appelé avec (entité, "sent"|"failed", erreur)
     * @param string|null $testRecipient si renseigné : toutes les cartes sont envoyées à cette adresse
     *        et RIEN n'est marqué comme envoyé (pour essayer sans risque)
     * @param float $timeLimitSeconds 0 = sans limite ; sinon on s'arrête proprement avant la carte suivante
     * @return array{sent: int, failed: int, stopped: bool}
     */
    public function deliver(iterable $items, ?callable $progress = null, ?string $testRecipient = null, float $timeLimitSeconds = 0.0): array
    {
        $startedAt = microtime(true);
        $sent = $failed = 0;
        $stopped = false;

        foreach ($items as $item) {
            if ($timeLimitSeconds > 0 && microtime(true) - $startedAt >= $timeLimitSeconds) {
                $stopped = true;
                break;
            }

            /** @var Club|Member $entity */
            $entity = $item["entity"];
            $card = $item["card"];
            if ($testRecipient) {
                $card["email"] = $testRecipient;
            }

            $result = $this->licenseService->deliver([$card]);

            if ($result["sent"] === 1) {
                $sent++;
                if (!$testRecipient) {
                    $entity->markCardSent();
                    $this->entityManager->flush();
                }
                $progress && $progress($entity, "sent", null);
                continue;
            }

            $failed++;
            $error = $result["failures"][0]["error"] ?? "Aucune adresse e-mail";
            if (!$testRecipient) {
                $entity->recordCardFailure($error);
                $this->entityManager->flush();
            }
            $progress && $progress($entity, "failed", $error);
        }

        return compact("sent", "failed", "stopped");
    }

    private function eligibleMembers(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select("m")->from(Member::class, "m")
            ->join("m.commande", "o")
            ->where("m.cardSentAt IS NULL")
            ->andWhere("m.licenceNumber IS NOT NULL")
            ->andWhere("m.email IS NOT NULL AND m.email <> ''")
            ->andWhere("o.status = 'payee'");
    }

    private function eligibleClubs(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select("c")->from(Club::class, "c")
            ->join("c.orders", "o")
            ->where("c.cardSentAt IS NULL")
            ->andWhere("c.email IS NOT NULL AND c.email <> ''")
            // La carte de club ne part que pour l'inscription du club : un complément d'affiliation ne la renvoie pas.
            ->andWhere("o.status = 'payee' AND o.orderType = 'inscription-club'");
    }

    /**
     * @return list<Club|Member>
     */
    private function ready(QueryBuilder $builder, string $alias, DateTimeImmutable $retryBefore, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $builder
            ->andWhere("$alias.cardAttempts < :max")
            ->andWhere("($alias.cardLastAttemptAt IS NULL OR $alias.cardLastAttemptAt <= :retryBefore)")
            ->setParameter("max", $this->maxAttempts)
            ->setParameter("retryBefore", $retryBefore)
            ->orderBy("$alias.id", "ASC")
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
