<?php

namespace App\Controller;

use App\Entity\Club;
use App\Entity\Order;
use App\Entity\Product;
use App\Repository\CulturalArtsRepository;
use App\Repository\EventRepository;
use App\Repository\ProductRepository;
use App\Repository\ZasshiRepository;
use App\Service\EmailService;
use App\Service\PanierService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use IntlDateFormatter;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Constraints as Assert;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class RouteController extends AbstractController
{
    public function __construct(
        private readonly PanierService   $cartService,
        private readonly EventRepository $eventRepository,
        private readonly CulturalArtsRepository $culturalArtsRepository,
        private readonly ZasshiRepository $zasshiRepository
    )
    {
    }

    #[Route("/", name: "app_home")]
    public function getHome(): Response
    {
        return $this->render("home/home.html.twig");
    }
    
    #[Route("/ryu", name: "app_ryu")]
    public function getRyu(): Response
    {
        return $this->render("home/ryu.html.twig");
    }

    #[Route("/arts-martiaux", name: "app_arts_martiaux_route")]
    public function getArtsMartiauxRoute(): Response
    {
        return $this->redirectToRoute("app_arts_martiaux_tous");
    }

    #[Route("/arts-martiaux/tous", name: "app_arts_martiaux_tous")]
    public function getArtsMartiauxTous(): Response
    {
        return $this->render("arts-martiaux/arts-martiaux.html.twig");
    }

    #[Route("/arts-martiaux/stages", name: "app_arts_martiaux_stages")]
    public function getArtsMartiauxStages(): Response
    {
        $evenements = $this->eventRepository->findBy(
            ["forPublic" => false],
            ["eventDate" => "DESC"]
        );

        return $this->render("arts-martiaux/stages.html.twig", [
            "events" => $evenements
        ]);
    }

    #[Route("/arts-martiaux/aikido", name: "aikido")]
    public function aikido(): Response { return $this->render("arts-martiaux/aikido.html.twig"); }

    #[Route("/arts-martiaux/judo-jujutsu", name: "judo_jujutsu")]
    public function judoJujutsu(): Response { return $this->render("arts-martiaux/judo-jujutsu.html.twig"); }

    #[Route("/arts-martiaux/karate-do", name: "karate_do")]
    public function karateDo(): Response { return $this->render("arts-martiaux/karate-do.html.twig"); }

    #[Route("/arts-martiaux/kung-fu", name: "kung_fu")]
    public function kungFu(): Response { return $this->render("arts-martiaux/kung-fu.html.twig"); }

    #[Route("/arts-martiaux/shinkyokai", name: "shinkyokai")]
    public function shinkyokai(): Response { return $this->render("arts-martiaux/shinkyokai.html.twig"); }

    #[Route("/arts-martiaux/taikibudo", name: "taikibudo")]
    public function taikibudo(): Response { return $this->render("arts-martiaux/taikibudo.html.twig"); }

    #[Route("/arts-martiaux/yoga", name: "yoga")]
    public function yoga(): Response { return $this->render("arts-martiaux/yoga.html.twig"); }

    #[Route("/arts-culturels", name: "app_arts_culturels_route")]
    public function getArtsCulturelsRoute(): Response
    {
        return $this->redirectToRoute("app_arts_culturels");
    }

    #[Route("/arts-culturels/tous", name: "app_arts_culturels")]
    public function getArtsCulturels(): Response
    {
        return $this->render("arts-culturels/arts-culturels.html.twig");
    }

    #[Route("/arts-culturels/inscriptions/{slug}", name: "app_arts_culturels_inscriptions")]
    public function getArtsCulturelsInscriptions(SessionInterface $session, string $slug): Response
    {
        $items = $this->cartService->getCartItems($session);
        $total = $this->cartService->getTotal($session);
        $artsCulturels = $this->culturalArtsRepository->findOneBy(["slug" => $slug]);
        $activity = $artsCulturels->getWorkshopType() . " " .  $artsCulturels->getName();

        return $this->render("arts-culturels/inscriptions.html.twig", [
            "items" => $items,
            "total" => $total,
            "activity" => $activity,
            "artCulturel" => $artsCulturels,
            "stripe_public_key" => $this->getParameter("stripe_public_key")
        ]);
    }

    #[Route("/arts-culturels/sumi-e", name: "app_arts_culturels_sumie")]
    public function getSumie(SessionInterface $session): Response
    {
        $items = $this->cartService->getCartItems($session);
        $total = $this->cartService->getTotal($session);

        $ateliersSumie = $this->culturalArtsRepository->findBy(
            ["workshopType" => "Sumi-e"],
            ["date" => "ASC"]
        );

        $ateliersKintsugi = $this->culturalArtsRepository->findBy(
            ["workshopType" => "Kintsugi"],
            ["date" => "ASC"]
        );

        $ateliersGyotaku = $this->culturalArtsRepository->findBy(
            ["workshopType" => "Gyotaku"],
            ["date" => "ASC"]
        );

        $ateliersSumie = $this->getWorkshop($ateliersSumie);

        $ateliersKintsugi = $this->getWorkshop($ateliersKintsugi);

        $ateliersGyotaku = $this->getWorkshop($ateliersGyotaku);

        return $this->render("arts-culturels/sumi-e.html.twig", [
            "items" => $items,
            "total" => $total,
            "stripe_public_key" => $this->getParameter("stripe_public_key"),
            "ateliersSumie" => $ateliersSumie,
            "ateliersKintsugi" => $ateliersKintsugi,
            "ateliersGyotaku" => $ateliersGyotaku
        ]);
    }

    #[Route("/arts-culturels/origami", name: "app_arts_culturels_origami")]
    public function getOrigami(SessionInterface $session): Response
    {
        $items = $this->cartService->getCartItems($session);
        $total = $this->cartService->getTotal($session);

        $ateliersOrigami = $this->culturalArtsRepository->findBy(
            ["workshopType" => "Origami"],
            ["date" => "ASC"]
        );

        $ateliersOrigami = $this->getWorkshop($ateliersOrigami);

        return $this->render("arts-culturels/origami.html.twig", [
            "items" => $items,
            "total" => $total,
            "stripe_public_key" => $this->getParameter("stripe_public_key"),
            "ateliersOrigami" => $ateliersOrigami
        ]);
    }

    #[Route("/arts-culturels/calligraphie", name: "app_arts_culturels_calligraphie")]
    public function getCalligraphie(SessionInterface $session): Response
    {
        $items = $this->cartService->getCartItems($session);
        $total = $this->cartService->getTotal($session);

        $ateliersCalligraphie = $this->culturalArtsRepository->findBy(
            ["workshopType" => "Calligraphie"],
            ["date" => "ASC"]
        );

        $ateliersCalligraphie = $this->getWorkshop($ateliersCalligraphie);

        return $this->render("arts-culturels/calligraphie.html.twig", [
            "items" => $items,
            "total" => $total,
            "stripe_public_key" => $this->getParameter("stripe_public_key"),
            "ateliersCalligraphie" => $ateliersCalligraphie
        ]);
    }

    #[Route("/vertus", name: "app_vertus")]
    public function getVertus(): Response
    {
        return $this->render("home/vertus.html.twig");
    }

    #[Route("/equipe", name: "app_equipe")]
    public function getEquipe(): Response
    {
        return $this->render("home/equipe.html.twig");
    }

    #[Route("/evenements", name: "app_evenements")]
    public function getEvenements(): Response
    {
        $evenements = $this->eventRepository->findBy(
            ["forPublic" => true],
            ["eventDate" => "DESC"]
        );

        return $this->render("home/evenements.html.twig", [
            "events" => $evenements
        ]);
    }

    #[Route("/evenements/{slug}", name: "app_evenement")]
    public function getEvenement(string $slug): Response
    {
        $evenement = $this->eventRepository->findOneBy(
            ["slug" => $slug],
        );

        return $this->render("home/evenement.html.twig", [
            "event" => $evenement
        ]);
    }

    #[Route("/galerie", name: "app_galerie")]
    public function getGalerie(): Response
    {
        return $this->render("home/galerie.html.twig");
    }

    #[Route("/boutique", name: "app_boutique")]
    public function getBoutique(ProductRepository $productRepository, SessionInterface $session): Response
    {
        $items = $this->cartService->getCartItems($session);
        $total = $this->cartService->getTotal($session);

        $products = $productRepository->findBy(
            [],
            [
                "id" => "DESC"
            ]);

        return $this->render("home/boutique.html.twig",
            [
                "products" => $products,
                "items" => $items,
                "total" => $total,
                "stripe_public_key" => $this->getParameter("stripe_public_key")
            ]
        );
    }

    #[Route("/harumatsuri", name: "app_tee_shirts")]
    public function getTeeShirts(ProductRepository $productRepository, SessionInterface $session): Response
    {
        $items = $this->cartService->getCartItems($session);
        $total = $this->cartService->getTotal($session);

        $products = $productRepository->findBy(
            [],
            [
                "id" => "DESC"
            ]);

        return $this->render("home/tee-shirts.html.twig",
            [
                "products" => $products,
                "items" => $items,
                "total" => $total,
                "stripe_public_key" => $this->getParameter("stripe_public_key")
            ]
        );
    }

    #[Route("/produit", name: "app_produit")]
    public function getProduit(): Response
    {
        return $this->render("home/produit.html.twig");
    }

    #[Route("/panier", name: "app_panier")]
    public function getPanier(SessionInterface $session): Response
    {
        $items = $this->cartService->getCartItems($session);
        $total = $this->cartService->getTotal($session);

        return $this->render("home/panier.html.twig", [
            "items" => $items,
            "total" => $total,
            "stripe_public_key" => $this->getParameter("stripe_public_key")
        ]);
    }

    #[Route("/cart/add/{id}", name: "cart_add")]
    public function add(SessionInterface $session, Product $product): Response
    {
        $this->cartService->addToCart($session, $product);
        return $this->redirectToRoute("app_panier");
    }

    #[Route("/add-order", name: "add_order_to_cart", methods: ["POST", "GET"])]
    public function addOrderToCart(
        SessionInterface $session,
        EntityManagerInterface $entityManager,
        ProductRepository $productRepository
    ): JsonResponse {
        // Récupérer les informations du panier depuis la session
        $cart = $session->get("cart", []);

        // Calculer le total du panier (vous pouvez adapter ce calcul en fonction de votre logique)
        $totalAmount = 0;

        // Créer la commande
        $order = new Order();
        $order->setOrderType("panier");
        $order->setStatus("en-cours");
        $order->setCreatedAt(new DateTimeImmutable("now"));

        foreach ($cart as $item) {
            $product = $productRepository->find($item["product"]->getId());
            $order->addProduct($product);
            $totalAmount += $item["product"]->getPrice() * $item["quantity"];
        }

        $order->setTotalAmount($totalAmount);

        // Sauvegarder la commande dans la base de données
        $entityManager->persist($order);
        $entityManager->flush();

        // Enregistrer l'ID de la commande dans la session
        $session->set("orderId", $order->getId());

        // Retourner une réponse JSON pour confirmer la création de la commande
        return new JsonResponse(["success" => true, "orderId" => $order->getId()]);
    }

    #[Route("/save-order-and-session", name: "save_order_and_session", methods: ["POST", "GET"])]
    public function saveOrderAndSession(
        Request $request,
        SessionInterface $session,
        EntityManagerInterface $entityManager
    ): JsonResponse
    {
        $flow = $request->get("flow", "inscription");

        if ($flow === "complement") {
            /** @var UploadedFile $csvFile */
            $csvFile = $request->files->get("csv");
            if (!$csvFile) {
                return new JsonResponse(["success" => false, "errors" => ["csv" => "Aucun fichier fourni."]], 400);
            }

            // Valider uniquement CSV/XLSX
            $validator = Validation::createValidator();
            $csvConstraints = new Assert\File([
                "mimeTypes" => [
                    "text/csv",
                    "application/vnd.ms-excel",
                    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                ],
                "mimeTypesMessage" => "Veuillez télécharger un fichier CSV ou Excel valide.",
            ]);
            $csvErrors = $validator->validate($csvFile, $csvConstraints);
            if (count($csvErrors) > 0) {
                return new JsonResponse(["success" => false, "errors" => ["csv" => (string) $csvErrors]], 400);
            }

            $clubNumber = $request->get("club_number");
            if (!$clubNumber) {
                return new JsonResponse(["success" => false, "errors" => ["club_number" => "Numéro manquant."]], 400);
            }

            // Stockage du fichier
            $csvFilename = uniqid().".".$csvFile->guessExtension();
            $csvFile->move($this->getParameter("upload_directory"), $csvFilename);
            $csvFilePath = $this->getParameter("upload_directory")."/".$csvFilename;

            $memberCount = (int) $request->get("member_count", 0);

            // Session CART spécifique au complément
            $cart = $session->get("cart", []);
            $cart["club_complement"] = [
                "club_number" => $clubNumber,
                "members" => $memberCount,
                "csvFilePath" => $csvFilePath,
                "price_per_member" => 5,
            ];
            $session->set("cart", $cart);

            // Créer l'ordre
            $order = new Order();
            $order->setOrderType("complement-affiliation");
            $order->setTotalAmount($memberCount * 5);
            $order->setStatus("en-cours");
            $order->setCreatedAt(new \DateTimeImmutable());

            $entityManager->persist($order);
            $entityManager->flush();

            $session->set("orderId", $order->getId());

            return new JsonResponse(["success" => true]);
        }

        $validator = Validation::createValidator();

        $logoFile = $request->files->get("logo");
        $csvFile = $request->files->get("csv");

        // Validation du logo, uniquement des fichiers image
        $logoConstraints = new Assert\File([
            "mimeTypes" => ["image/jpeg", "image/png", "image/gif"],
            "mimeTypesMessage" => "Veuillez télécharger une image valide (jpeg, png ou gif).",
        ]);

        $csvConstraints = new Assert\File([
            "mimeTypes" => [
                "text/csv",
                "application/vnd.ms-excel",
                "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            ],
            "mimeTypesMessage" => "Veuillez télécharger un fichier CSV ou Excel valide.",
        ]);

        $logoErrors = $validator->validate($logoFile, $logoConstraints);
        $csvErrors = $validator->validate($csvFile, $csvConstraints);

        if (count($logoErrors) > 0 || count($csvErrors) > 0) {
            return new JsonResponse([
                "success" => false,
                "errors" => [
                    "logo" => (string) $logoErrors,
                    "csv" => (string) $csvErrors,
                ],
            ]);
        }

        $clubName = $request->get("club_name");
        $logoFile = $request->files->get("logo");
        $csvFile = $request->files->get("csv");
        $email = $request->get("email");
        $presidentName = $request->get("president_name");
        $treasurerName = $request->get("treasurer_name");
        $address = $request->get("address");
        $address2 = $request->get("address2");
        $zip = $request->get("zip");
        $city = $request->get("city");
        $country = $request->get("country");

        // Enregistrement du logo si présent
        if ($logoFile) {
            $logoFilename = uniqid() . "." . $logoFile->guessExtension();
            $logoFile->move($this->getParameter("upload_directory"), $logoFilename);
        } else {
            $logoFilename = null;
        }

        // Enregistrement du fichier CSV/Excel
        if ($csvFile) {
            $csvFilename = uniqid() . "." . $csvFile->guessExtension();
            $csvFile->move($this->getParameter("upload_directory"), $csvFilename);
            $csvFilePath = $this->getParameter("upload_directory") . "/" . $csvFilename;
        } else {
            return new JsonResponse(["error" => "No CSV or Excel file uploaded"], 400);
        }

        $memberCount = (int) $request->get("member_count");

        $cart["club"] = [
            "club_name" => $clubName,
            "logo" => $logoFilename,
            "email" => $email,
            "president_name" => $presidentName,
            "treasurer_name" => $treasurerName,
            "address" => $address,
            "address2" => $address2,
            "zip" => $zip,
            "city" => $city,
            "country" => $country,
            "members" => $memberCount,
            "csvFilePath" => $csvFilePath
        ];

        $session->set("cart", $cart);

        $order = new Order();
        $order->setOrderType("inscription-club");
        $order->setTotalAmount(50 + ($memberCount * 5));
        $order->setStatus("en-cours");
        $order->setCreatedAt(new DateTimeImmutable());

        $entityManager->persist($order);
        $entityManager->flush();

        $session->set("orderId", $order->getId());

        return new JsonResponse(["success" => true]);
    }

    #[Route("/add-cultural-arts", name: "add_cultural_arts_to_cart", methods: ["POST"])]
    public function addCulturalArtsToCart(
        Request $request,
        SessionInterface $session,
        EntityManagerInterface $entityManager
    ): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $price = $data["price"];
        $registrationData = $data["registrationData"];
        $activity = $registrationData["activity"];

        $session->set("cart", []);

        $cart = $session->get("cart", []);
        $cart["cultural_registration"] = [
            "price" => $price,
            "activity" => $activity,
            "first_name" => $registrationData["firstName"],
            "last_name" => $registrationData["lastName"],
            "birth_date" => $registrationData["birthDate"],
            "sex" => $registrationData["sex"],
            "email" => $registrationData["email"],
            "type" => $registrationData["type"],
        ];

        $session->set("cart", $cart);

        $order = new Order();
        $order->setOrderType($activity);
        $order->setTotalAmount($price);
        $order->setStatus("en-cours");
        $order->setCreatedAt(new DateTimeImmutable("now"));

        $entityManager->persist($order);
        $entityManager->flush();

        $session->set("orderId", $order->getId());

        return new JsonResponse(["success" => true]);
    }

    #[Route("/add-individual-registration", name: "add_individual_registration_to_cart", methods: ["POST"])]
    public function addIndividualRegistrationToCart(
        Request $request,
        SessionInterface $session,
        EntityManagerInterface $entityManager
    ): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $price = $data["price"];
        $registrationData = $data["registrationData"];

        $firstName = $registrationData["firstName"];
        $lastName = $registrationData["lastName"];
        $birthDate = $registrationData["birthDate"];
        $sex = $registrationData["sex"];
        $email = $registrationData["email"];

        $session->set("cart", []);

        $cart["club_individuel"] = [
            "price" => $price,
            "first_name" => $firstName,
            "last_name" => $lastName,
            "birth_date" => $birthDate,
            "sex" => $sex,
            "email" => $email,
        ];

        $session->set("cart", $cart);

        $order = new Order();
        $order->setOrderType("inscription-individuelle");
        $order->setTotalAmount(10);
        $order->setStatus("en-cours");
        $order->setCreatedAt(new DateTimeImmutable("now"));

        $entityManager->persist($order);
        $entityManager->flush();

        $session->set("orderId", $order->getId());

        return new JsonResponse(["success" => true]);
    }

    #[Route("/add-cultural-registration", name: "add_cultural_registration_to_cart", methods: ["POST"])]
    public function addCulturalRegistrationToCart(
        Request $request,
        SessionInterface $session,
        EntityManagerInterface $entityManager
    ): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $price = $data["price"];
        $registrationData = $data["registrationData"];

        $cart = $session->get("cart", []);

        $firstName = $registrationData["firstName"];
        $lastName = $registrationData["lastName"];
        $birthDate = $registrationData["birthDate"];
        $sex = $registrationData["sex"];
        $email = $registrationData["email"];

        $cart["cultural_registration"] = [
            "price" => $price,
            "first_name" => $firstName,
            "last_name" => $lastName,
            "birth_date" => $birthDate,
            "sex" => $sex,
            "email" => $email,
        ];

        $session->set("cart", $cart);

        $order = new Order();
        $order->setOrderType("inscription-arts-culturels");
        $order->setTotalAmount(10);
        $order->setStatus("en-cours");
        $order->setCreatedAt(new DateTimeImmutable("now"));

        $entityManager->persist($order);
        $entityManager->flush();

        $session->set("orderId", $order->getId());

        return new JsonResponse(["success" => true]);
    }

    #[Route("/count-members", name: "count_members", methods: ["POST"])]
    public function countMembers(Request $request): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->files->get("csv");
        if (!$file) {
            return new JsonResponse(["error" => "Aucun fichier fourni"], 400);
        }

        $extension = $file->getClientOriginalExtension();
        $validExtensions = ["csv", "xls", "xlsx"];

        if (!in_array($extension, $validExtensions)) {
            return new JsonResponse(["error" => "Type de fichier non pris en charge"], 400);
        }

        $requiredColumns = ["Prénom", "Nom de famille", "Sexe", "Date de naissance", "Adresse électronique"];

        if ($extension === "csv") {
            $result = $this->countMembersInCSV($file, $requiredColumns);
        } else {
            $result = $this->countMembersInXLSX($file, $requiredColumns);
        }

        if (isset($result["error"])) {
            return new JsonResponse(["error" => $result["error"]], 400);
        }

        return new JsonResponse(["memberCount" => $result["memberCount"]]);
    }

    private function countMembersInCSV(UploadedFile $file, array $requiredColumns): array
    {
        $handle = fopen($file->getPathname(), "r");
        $headers = fgetcsv($handle, 1000);
        $headers = array_map("trim", $headers);

        // Vérification des colonnes obligatoires
        $requiredColumnIndices = [];
        foreach ($requiredColumns as $requiredColumn) {
            $columnIndex = array_search($requiredColumn, $headers);
            if ($columnIndex === false) {
                fclose($handle);
                return ["error" => "Colonne obligatoire '$requiredColumn' manquante."];
            }
            $requiredColumnIndices[] = $columnIndex;
        }

        // Comptage des membres valides
        $memberCount = 0;
        $invalidLines = [];
        while (($data = fgetcsv($handle, 1000)) !== FALSE) {
            $isValid = true;
            foreach ($requiredColumnIndices as $index) {
                if (empty(trim($data[$index] ?? ""))) { // Vérifier les colonnes obligatoires
                    $isValid = false;
                    $invalidLines[] = $data;
                    break;
                }
            }

            if ($isValid) {
                $memberCount++;
            }
        }

        fclose($handle);

        if (count($invalidLines) > 0) {
            return ["error" => "Le fichier CSV contient des lignes incomplètes dans les colonnes obligatoires (prénom, nom de famille, sexe, date de naissance).."];
        }

        return ["memberCount" => $memberCount];
    }

    private function countMembersInXLSX(UploadedFile $file, array $requiredColumns): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $sheet = $spreadsheet->getActiveSheet();
        $headers = $sheet->rangeToArray("A1:I1")[0];

        // Vérification des colonnes obligatoires
        $requiredColumnIndices = [];
        foreach ($requiredColumns as $requiredColumn) {
            $columnIndex = array_search($requiredColumn, $headers);
            if ($columnIndex === false) {
                return ["error" => "Colonne obligatoire '$requiredColumn' manquante."];
            }
            $requiredColumnIndices[] = $columnIndex;
        }

        $rows = $sheet->toArray();
        $memberCount = 0;
        $invalidLines = [];

        foreach ($rows as $index => $row) {
            if ($index === 0) continue; // Sauter la ligne des en-têtes

            if (empty(array_filter($row))) {
                continue;
            }

            $isValid = true;
            foreach ($requiredColumnIndices as $colIndex) {
                if (empty(trim($row[$colIndex] ?? ""))) { // Vérifier les colonnes obligatoires
                    $isValid = false;
                    $invalidLines[] = $row;
                    break;
                }
            }

            if ($isValid) {
                $memberCount++;
            }
        }

        if (count($invalidLines) > 0) {
            return ["error" => "Le fichier XLSX contient des lignes incomplètes dans les colonnes obligatoires (prénom, nom de famille, sexe, date de naissance)."];
        }

        return ["memberCount" => $memberCount];
    }

    #[Route("/download-csv-template", name: "download_csv_template")]
    public function downloadCSVTemplate(): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue("A1", "Prénom");
        $sheet->setCellValue("B1", "Nom de famille");
        $sheet->setCellValue("C1", "Sexe");
        $sheet->setCellValue("D1", "Date de naissance");
        $sheet->setCellValue("E1", "Adresse");
        $sheet->setCellValue("F1", "Code postal");
        $sheet->setCellValue("G1", "Ville");
        $sheet->setCellValue("H1", "Adresse électronique");
        $sheet->setCellValue("I1", "Numéro de téléphone");

        $validationSexe = $sheet->getCell("C2")->getDataValidation();
        $validationSexe->setType(DataValidation::TYPE_LIST);
        $validationSexe->setErrorStyle(DataValidation::STYLE_STOP);
        $validationSexe->setAllowBlank(false);
        $validationSexe->setShowDropDown(true);
        $validationSexe->setFormula1("'Masculin,Féminin'");

        $sheet->getStyle("D2:D1000")->getNumberFormat()->setFormatCode("DD/MM/YYYY");
        $validationDate = $sheet->getCell("D2")->getDataValidation();
        $validationDate->setType(DataValidation::TYPE_DATE);
        $validationDate->setErrorStyle(DataValidation::STYLE_STOP);
        $validationDate->setAllowBlank(false);
        $validationDate->setOperator(DataValidation::OPERATOR_BETWEEN);
        $validationDate->setFormula1("DATE(1900,1,1)");
        $validationDate->setFormula2("DATE(2100,12,31)");
        $validationDate->setShowInputMessage(true);
        $validationDate->setPromptTitle("Format attendu");
        $validationDate->setPrompt("Veuillez entrer une date au format JJ/MM/AAAA.");

        $validationPhone = $sheet->getCell("I2")->getDataValidation();
        $validationPhone->setType(DataValidation::TYPE_TEXTLENGTH);
        $validationPhone->setErrorStyle(DataValidation::STYLE_STOP);
        $validationPhone->setAllowBlank(false);
        $validationPhone->setOperator(DataValidation::OPERATOR_BETWEEN);
        $validationPhone->setFormula1("10");
        $validationPhone->setFormula2("12");
        $validationPhone->setShowInputMessage(true);
        $validationPhone->setPromptTitle("Format attendu");
        $validationPhone->setPrompt("Veuillez entrer un numéro de téléphone entre 10 et 12 chiffres.");

        $validationPostalCode = $sheet->getCell("F2")->getDataValidation();
        $validationPostalCode->setType(DataValidation::TYPE_WHOLE);
        $validationPostalCode->setErrorStyle(DataValidation::STYLE_STOP);
        $validationPostalCode->setAllowBlank(false);
        $validationPostalCode->setOperator(DataValidation::OPERATOR_BETWEEN);
        $validationPostalCode->setFormula1("10000");
        $validationPostalCode->setFormula2("99999");
        $validationPostalCode->setShowInputMessage(true);
        $validationPostalCode->setPromptTitle("Format attendu");
        $validationPostalCode->setPrompt("Veuillez entrer un code postal composé de 5 chiffres.");

        $validationEmail = $sheet->getCell("H2")->getDataValidation();
        $validationEmail->setType(DataValidation::TYPE_CUSTOM);
        $validationEmail->setErrorStyle(DataValidation::STYLE_STOP);
        $validationEmail->setAllowBlank(false);
        $validationEmail->setFormula1("=AND(ISNUMBER(SEARCH('@',H2)),ISNUMBER(SEARCH(".",H2)))");
        $validationEmail->setShowInputMessage(true);
        $validationEmail->setPromptTitle("Format attendu");
        $validationEmail->setPrompt("Veuillez entrer une adresse électronique valide (ex : exemple@domaine.fr).");

        for ($i = 2; $i <= 1000; $i++) {
            $sheet->getCell("C$i")->setDataValidation(clone $validationSexe);
            $sheet->getCell("D$i")->setDataValidation(clone $validationDate);
            $sheet->getCell("I$i")->setDataValidation(clone $validationPhone);
            $sheet->getCell("F$i")->setDataValidation(clone $validationPostalCode);
            $sheet->getCell("H$i")->setDataValidation(clone $validationEmail);
        }
        foreach (range("A", "I") as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $response = new StreamedResponse(function() use ($writer) {
            $writer->save("php://output");
        });

        $response->headers->set("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
        $response->headers->set("Content-Disposition", "attachment;filename=template.xlsx");
        $response->headers->set("Cache-Control", "max-age=0");

        return $response;
    }

    #[Route("/cart/update/ajax", name: "cart_update_ajax", methods: ["POST"])]
    public function updateAjax(SessionInterface $session, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $productId = $data["id"];
        $quantity = (int) $data["quantity"];

        $cart = $session->get("cart", []);

        if (isset($cart[$productId])) {
            $cart[$productId]["quantity"] = $quantity;
        }

        $session->set("cart", $cart);

        $itemTotal = $cart[$productId]["product"]->getPrice() * $quantity;
        $cartTotal = $this->cartService->getTotal($session);

        return new JsonResponse([
            "success" => true,
            "itemTotal" => number_format($itemTotal, 2),
            "cartTotal" => number_format($cartTotal, 2),
        ]);
    }

    #[Route("/cart/remove/{id}", name: "cart_remove")]
    public function remove(SessionInterface $session, Product $product): Response
    {
        $this->cartService->removeFromCart($session, $product);
        return $this->redirectToRoute("app_panier");
    }

    #[Route("/paiement", name: "app_paiement")]
    public function getPaiement(): Response
    {
        return $this->render("home/paiement.html.twig");
    }

    #[Route("/inscription-club", name: "app_inscription_club")]
    public function getInscription(SessionInterface $session, PanierService $panierService): Response
    {
        $items = $this->cartService->getCartItems($session);
        $total = $this->cartService->getTotal($session);

        $panierService->resetClubRegistration($session);

        return $this->render("home/inscription-club.html.twig", [
            "items" => $items,
            "total" => $total,
            "stripe_public_key" => $this->getParameter("stripe_public_key_test"),
            "stripe_secret_key" => $this->getParameter("stripe_secret_key_test"),
        ]);
    }

    #[Route("/complement-affiliation", name: "app_complement_affiliation")]
    public function getComplementAffiliation(SessionInterface $session, PanierService $panierService): Response
    {
        $items = $this->cartService->getCartItems($session);
        $total = $this->cartService->getTotal($session);

        $panierService->resetClubRegistration($session);

        return $this->render("home/complement-affiliation.html.twig", [
            "items" => $items,
            "total" => $total,
            "stripe_public_key" => $this->getParameter("stripe_public_key_test"),
            "stripe_secret_key" => $this->getParameter("stripe_secret_key_test"),
        ]);
    }

    #[Route("/inscription-individuelle", name: "app_inscription_individuelle")]
    public function getInscriptionIndividuelle(SessionInterface $session): Response
    {
        $items = $this->cartService->getCartItems($session);
        $total = $this->cartService->getTotal($session);

        return $this->render("home/inscription-individuelle.html.twig", [
            "items" => $items,
            "total" => $total,
            "stripe_public_key" => $this->getParameter("stripe_public_key")
        ]);
    }

    #[Route("/inscription-decouverte-sumi-e", name: "app_inscription_decouverte_sumie")]
    public function getInscriptionSumie(): Response
    {
        return $this->redirectToRoute("app_arts_culturels_sumie");
    }

    #[Route("/filter/products", name: "filter_products_by_price", methods: ["GET"])]
    public function filterProductsByPrice(Request $request, ProductRepository $productRepository): JsonResponse
    {
        $priceRange = $request->query->get("priceRange");

        // Séparer la plage de prix pour obtenir le min et le max
        [$minPrice, $maxPrice] = explode("-", $priceRange);

        // Récupérer les produits correspondant à la plage de prix
        $products = $productRepository->findByPriceRange((float) $minPrice, (float) $maxPrice);

        // Retourner une réponse JSON
        $responseProducts = [];

        foreach ($products as $product) {
            $responseProducts[] = [
                "id" => $product->getId(),
                "name" => $product->getName(),
                "price" => $product->getPrice(),
                "image" => $product->getImage(),
                "category" => $product->getCategory(),
            ];
        }

        return new JsonResponse(["products" => $responseProducts]);
    }

    /**
     * @throws SyntaxError
     * @throws TransportExceptionInterface
     * @throws RuntimeError
     * @throws LoaderError
     * @throws LoaderError
     */
    #[Route("/decouverte-sumie", name: "app_decouverte")]
    public function decouverteSumie(Request $request, EmailService $emailService): Response
    {
        if ($request->isXmlHttpRequest()) {
            $data = json_decode($request->getContent(), true);

            $emailService->sendContactEmail(
                "contact@shinkyokai.com",
                "Inscription atelier découverte - 16 octobre",
                $data
            );

            return $this->json(["success" => true]);
        }

        return $this->render("home/inscription-sumie.html.twig");
    }

    /**
     * @throws SyntaxError
     * @throws TransportExceptionInterface
     * @throws RuntimeError
     * @throws LoaderError
     */
    #[Route("/contact", name: "app_contact")]
    public function contact(Request $request, EmailService $emailService): Response
    {
        if ($request->isXmlHttpRequest()) {
            $data = json_decode($request->getContent(), true);

            $emailService->sendContactEmail(
                "contact@shinkyokai.com",
                "Nouveau message de contact",
                $data
            );

            return $this->json(["success" => true]);
        }

        return $this->render("home/contact.html.twig");
    }

    #[Route("/politique-de-confidentialite", name: "app_politique_de_confidentialite")]
    public function getPrivacyPolicy(): Response
    {
        return $this->render("home/politique-de-confidentialite.html.twig");
    }

    #[Route("/zasshi", name: "app_zasshi")]
    public function getZasshi(): Response
    {
        $zasshis = $this->zasshiRepository->findBy([], ["date" => "DESC"]);

        $zasshisByMonth = [];
        foreach ($zasshis as $zasshi) {
            $monthYear = substr($zasshi->getDate(), 0, 7);
            $zasshisByMonth[$monthYear][] = [
                "id" => $zasshi->getId(),
                "name" => $zasshi->getName(),
                "pdf" => $zasshi->getPdf(),
                "monthYear" => $this->formatDateToFrench($monthYear),
            ];
        }

        return $this->render("home/zasshi.html.twig", [
            "zasshisByMonth" => $zasshisByMonth,
        ]);
    }

    private function formatDateToFrench(string $date): string
    {
        // Correspondance des mois en français
        $monthNames = [
            "01" => "Janvier", "02" => "Février", "03" => "Mars", "04" => "Avril",
            "05" => "Mai", "06" => "Juin", "07" => "Juillet", "08" => "Août",
            "09" => "Septembre", "10" => "Octobre", "11" => "Novembre", "12" => "Décembre",
        ];

        // Séparer l'année et le mois
        [$year, $month] = explode("-", $date);

        // Retourner le format "Mois Année"
        return $monthNames[$month] . " " . $year;
    }

    #[Route("/la-caverne-secrete/logout", name: "app_logout", methods: ["GET"])]
    public function logout(): Response
    {
        return new Response(
            "Déconnecté avec succès.", Response::HTTP_OK, ["WWW-Authenticate" => "Basic realm='admin'"]);
    }

    #[Route("/api/club/check-number", name: "api_check_club_number", methods: ["POST"])]
    public function checkClubNumber(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $clubNumber = $request->request->get("club_number");
        if (!$clubNumber) {
            return new JsonResponse(["ok" => false, "error" => "Numéro manquant."], 400);
        }

        $club = $em->getRepository(Club::class)->findOneBy(["clubNumber" => $clubNumber]);
        if (!$club) {
            return new JsonResponse(["ok" => false, "error" => "Numéro introuvable."], 404);
        }

        return new JsonResponse([
            "ok" => true,
            "club" => [
                "id" => $club->getId(),
                "name" => $club->getName(),
                "email" => $club->getEmail(),
                "number" => $club->getClubNumber(),
                "city" => $club->getCity(),
                "postalCode" => $club->getPostalCode(),
                "country" => $club->getCountry(),
                "logo" => $club->getLogo(),
            ],
        ]);
    }

    private function getWorkshop($ateliersSumie): array
    {
        $groupedAteliers = [];

        foreach ($ateliersSumie as $atelierSumie) {
            $monthYear = IntlDateFormatter::create(
                "fr_FR",
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                null,
                null,
                "LLLL yyyy"
            )->format($atelierSumie->getDate());

            $groupedAteliers[$monthYear][] = $atelierSumie;

            $dateString = $atelierSumie->getDate()->format("d/m/Y à H\hi");
            $atelierSumie->formattedDate = $dateString;
        }

        return $groupedAteliers;
    }
}