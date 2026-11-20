<?php

namespace App\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Faux transport d'e-mails : mémorise les envois et peut simuler des pannes SMTP.
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<\Symfony\Component\Mime\Email> */
    public array $sent = [];
    /** @var array<string, int> destinataire => nombre d'essais encore voués à l'échec (PHP_INT_MAX = panne définitive) */
    private array $failures = [];
    /** @var array<string, int> */
    public array $attempts = [];

    public function failFor(string $address, int $times = PHP_INT_MAX): void
    {
        $this->failures[$address] = $times;
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $to = $message->getTo()[0]->getAddress();
        $this->attempts[$to] = ($this->attempts[$to] ?? 0) + 1;

        if (($this->failures[$to] ?? 0) > 0) {
            $this->failures[$to]--;
            throw new TransportException("Panne SMTP simulée pour $to");
        }

        $this->sent[] = $message;
    }

    /**
     * @return list<string> adresses des destinataires, dans l'ordre d'envoi
     */
    public function recipients(): array
    {
        return array_map(static fn($m) => $m->getTo()[0]->getAddress(), $this->sent);
    }
}
