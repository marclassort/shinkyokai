<?php

namespace App\Controller\Admin;

use App\Entity\Club;
use App\Entity\CulturalArts;
use App\Entity\Event;
use App\Entity\Member;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\Zasshi;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractDashboardController
{
    #[Route('/la-caverne-secrete', name: 'admin')]
    public function index(): Response
    {
        return $this->render('admin/my-dashboard.html.twig', []);    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Shinkyokai');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-home');
        yield MenuItem::section("Adhésions", "fa fa-address-card");
        yield MenuItem::linkToCrud('Clubs', 'fa fa-address-card', Club::class);
        yield MenuItem::linkToCrud('Membres', 'fa fa-user', Member::class);
        yield MenuItem::linkToCrud('Zasshi', 'fa fa-file', Zasshi::class);
        yield MenuItem::section("Événements", "fa fa-calendar");
        yield MenuItem::linkToCrud('Événements', 'fa fa-calendar', Event::class);
        yield MenuItem::linkToCrud('Ateliers arts culturels', 'fa fa-palette', CulturalArts::class);
        yield MenuItem::section("Boutique", "fa fa-shopping-cart");
        yield MenuItem::linkToCrud('Produits', 'fa fa-shopping-cart', Product::class);
        yield MenuItem::linkToCrud('Commandes', 'fa fa-shopping-cart', Order::class);
    }
}
