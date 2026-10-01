<?php

namespace App\Controller\Admin;

use App\Meal\MealStatus;
use App\Repository\MealEntryRepository;
use App\Repository\UserRepository;
use App\Repository\WeightEntryRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

/** Back office. Only ROLE_ADMIN (see security.yaml access_control for ^/admin). */
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MealEntryRepository $meals,
        private readonly WeightEntryRepository $weights,
    ) {
    }

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'userCount' => $this->users->count([]),
            'mealCount' => $this->meals->count([]),
            'pendingCount' => $this->meals->count(['status' => MealStatus::Pending]),
            'failedCount' => $this->meals->count(['status' => MealStatus::Failed]),
            'weightCount' => $this->weights->count([]),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('🥗 Calorie Tracker admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Overview', 'fa fa-home');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fa fa-user');
        yield MenuItem::linkTo(MealEntryCrudController::class, 'Meals', 'fa fa-utensils');
        yield MenuItem::linkTo(WeightEntryCrudController::class, 'Weights', 'fa fa-weight-scale');
        yield MenuItem::section();
        yield MenuItem::linkToRoute('Back to the app', 'fa fa-arrow-left', 'app_dashboard');
    }
}
