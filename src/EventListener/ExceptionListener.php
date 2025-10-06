<?php

namespace App\EventListener;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

readonly class ExceptionListener
{
    public function __construct(private MailerInterface $mailer, private Environment $twig)
    {
    }

    /**
     * @throws SyntaxError
     * @throws TransportExceptionInterface
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        $response = $event->getResponse();

        if (!$response) {
            $response = new Response();
        }

        if ($response->getStatusCode() === 500) {
            $email = (new Email())
                ->from('shinkyokai.academie@gmail.com')
                ->to('marc.lassort@gmail.com')
                ->subject('Erreur 500 détectée')
                ->html($this->twig->render('emails/error500.html.twig', [
                    'exception' => $exception,
                ]));

            $this->mailer->send($email);
        }
    }
}
