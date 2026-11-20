<?php

namespace App\Tests\Support;

use App\Entity\Order;
use App\Service\CheckoutGatewayInterface;
use App\Service\LicenseService;
use App\Service\PaymentVerifierInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Socle des tests de parcours de paiement : Stripe simulé, faux transport d'e-mails, base SQLite en mémoire.
 */
abstract class PaymentFlowTestCase extends WebTestCase
{
    use DatabaseFixtures;


    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected FakePaymentVerifier $payment;
    protected FakeCheckoutGateway $checkout;
    protected RecordingMailer $mailer;
    protected string $outputDir;
    /** @var list<string> */
    protected array $tempFiles = [];

    /** Valeur de LICENCE_CARDS_MODE pour ce test (null = valeur par défaut, « inline »). */
    protected function cardsMode(): ?string
    {
        return null;
    }

    protected function setUp(): void
    {
        if ($this->cardsMode() !== null) {
            $_SERVER["LICENCE_CARDS_MODE"] = $_ENV["LICENCE_CARDS_MODE"] = $this->cardsMode();
        }
        $this->client = static::createClient();
        $this->client->disableReboot(); // la base SQLite est en mémoire : on garde la même connexion entre les requêtes

        $container = static::getContainer();
        $this->entityManager = $container->get("doctrine")->getManager();
        $params = $this->entityManager->getConnection()->getParams();
        if (($params["driver"] ?? "") !== "pdo_sqlite" || empty($params["memory"])) {
            throw new \RuntimeException("ARRÊT DE SÉCURITÉ : les tests doivent utiliser SQLite en mémoire, pas " . ($params["driver"] ?? "?"));
        }
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->payment = new FakePaymentVerifier();
        $container->set(PaymentVerifierInterface::class, $this->payment);
        $this->checkout = new FakeCheckoutGateway();
        $container->set(CheckoutGatewayInterface::class, $this->checkout);

        $this->mailer = new RecordingMailer();
        $this->outputDir = sys_get_temp_dir() . "/skk-payment-" . uniqid();
        $container->set(LicenseService::class, new LicenseService(
            $container->get("twig"), $this->mailer, new NullLogger(), $container->getParameter("kernel.project_dir"),
            $this->outputDir, false, 0
        ));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        (new Filesystem())->remove($this->outputDir);
        unset($_SERVER["LICENCE_CARDS_MODE"], $_ENV["LICENCE_CARDS_MODE"]);
        parent::tearDown();
    }

    /**
     * Comme après create-checkout-session : un panier, une commande « en-cours » et un identifiant de session Stripe.
     */
    protected function startCheckout(string $orderType, array $cart, float $amount): Order
    {
        $order = new Order();
        $order->setOrderType($orderType);
        $order->setStatus("en-cours");
        $order->setTotalAmount($amount);
        $order->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $session = static::getContainer()->get("session.factory")->createSession();
        $session->set("cart", $cart);
        $session->set("orderId", $order->getId());
        $session->set("stripeSessionId", "cs_test_123");
        $session->save();
        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $order;
    }

    protected function clubCart(int $members): array
    {
        $file = MemberFile::xlsx(MemberFile::rows($members));
        $this->tempFiles[] = $file;

        return ["club" => [
            "club_name" => "Club Nouveau", "logo" => "logo-test.png", "email" => "club@example.test",
            "president_name" => "Président", "treasurer_name" => "Trésorier", "address" => "1 rue du Dojo",
            "address2" => "", "zip" => "64000", "city" => "Pau", "country" => "France",
            "members" => $members, "csvFilePath" => $file,
        ]];
    }

    protected function rowCount(string $entity): int
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository($entity)->count([]);
    }
}
