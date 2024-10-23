<?php

namespace App\Command;

use App\Entity\Member;
use App\Repository\MemberRepository;
use App\Service\HelloAssoAuthService;
use App\Service\HelloAssoOrderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[AsCommand(
    name: "app:hello-asso",
    description: "Retrieves users from HelloAsso SNA account.",
)]
class HelloAssoCommand extends Command
{
    public function __construct(private readonly HelloAssoAuthService $helloAssoAuthService, private readonly HelloAssoOrderService $helloAssoOrderService, private readonly EntityManagerInterface $entityManager, private readonly MemberRepository $memberRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Fetch orders from HelloAsso and update members in the database.');
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $accessToken = $this->helloAssoAuthService->getAccessToken(
            '7b9ba3bcc9b94f50944c1c360fb0e821',
            'OAW6QDLq1sNVV2gatJiUGdhNuMfXjKLX'
        );

        $orders = $this->helloAssoOrderService->getOrders($accessToken);

        foreach ($orders['data'] as $order) {
            $email = $order['payer']['email'];

            $existingMember = $this->memberRepository->findOneBy(['email' => $email]);

            if (!$existingMember) {
                $member = new Member();
                $member->setFirstName($order['payer']['firstName']);
                $member->setLastName($order['payer']['lastName']);
                $member->setEmail($order['payer']['email']);
                if (isset($order['payer']['country'])) {
                    $member->setCountry($order['payer']['country']);
                } else {
                    $member->setCountry('FR');
                }

                $this->entityManager->persist($member);
            } else {
                $io->info('Membre avec l\'email ' . $email . ' existe déjà.');
            }
        }

        $this->entityManager->flush();

        $io->success('Les commandes HelloAsso ont été récupérées et les membres ont été mis à jour.');

        return Command::SUCCESS;
    }
}
