<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Suivi de l'envoi de la carte (PDF) par e-mail, commun aux adhérents et aux clubs.
 *
 * Une carte est « en attente » tant que cardSentAt est vide : elle est envoyée par `app:licences:envoyer`
 * (cron) ou juste après l'inscription, puis marquée comme envoyée. Un échec incrémente le compteur d'essais.
 */
trait CardDeliveryTrait
{
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $cardSentAt = null;

    #[ORM\Column(type: Types::SMALLINT, options: ["default" => 0])]
    private int $cardAttempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $cardLastAttemptAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cardLastError = null;

    // Ancien numéro de licence, si la carte à envoyer REMPLACE une carte déjà envoyée (l'e-mail l'explique).
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $cardReplaces = null;

    public function getCardSentAt(): ?DateTimeImmutable
    {
        return $this->cardSentAt;
    }

    public function getCardAttempts(): int
    {
        return $this->cardAttempts;
    }

    public function getCardLastAttemptAt(): ?DateTimeImmutable
    {
        return $this->cardLastAttemptAt;
    }

    public function getCardLastError(): ?string
    {
        return $this->cardLastError;
    }

    public function getCardReplaces(): ?string
    {
        return $this->cardReplaces;
    }

    public function setCardReplaces(?string $oldLicenceNumber): static
    {
        $this->cardReplaces = $oldLicenceNumber;

        return $this;
    }

    public function markCardSent(?DateTimeImmutable $at = null): static
    {
        $this->cardSentAt = $at ?? new DateTimeImmutable();
        $this->cardLastError = null;
        $this->cardReplaces = null;

        return $this;
    }

    public function recordCardFailure(string $error, ?DateTimeImmutable $at = null): static
    {
        $this->cardAttempts++;
        $this->cardLastAttemptAt = $at ?? new DateTimeImmutable();
        $this->cardLastError = mb_substr($error, 0, 255);

        return $this;
    }

    /** Remet la carte « en attente » (renvoi manuel, ou abandon après trop d'échecs). */
    public function resetCardDelivery(): static
    {
        $this->cardSentAt = null;
        $this->cardAttempts = 0;
        $this->cardLastAttemptAt = null;
        $this->cardLastError = null;
        $this->cardReplaces = null;

        return $this;
    }
}
