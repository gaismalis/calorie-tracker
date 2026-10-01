<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Users register themselves, so there's no "new" page. Admins can only change roles;
 * profile data belongs to the user.
 *
 * @extends AbstractCrudController<User>
 */
class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('User')
            ->setEntityLabelInPlural('Users')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['email'])
            ->setTimezone('UTC');
    }

    public function configureActions(Actions $actions): Actions
    {
        $notMe = fn (Action $action) => $action->displayIf(fn (User $user) => $user !== $this->getUser());

        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DELETE, $notMe)
            ->update(Crud::PAGE_DETAIL, Action::DELETE, $notMe);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('createdAt')->add('timezone');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email')->setDisabled();
        yield ChoiceField::new('roles')
            ->setChoices(['Admin' => 'ROLE_ADMIN'])
            ->allowMultipleChoices()
            ->renderExpanded()
            ->renderAsBadges(['ROLE_ADMIN' => 'danger', 'ROLE_USER' => 'secondary'])
            ->setHelp('Every user has ROLE_USER. Admins can open this back office.');
        yield DateTimeField::new('createdAt', 'Registered (UTC)')->hideOnForm();
        yield TextField::new('timezone')->hideOnForm();
        yield ChoiceField::new('sex')->hideOnForm()->hideOnIndex();
        yield DateField::new('birthDate')->hideOnForm()->hideOnIndex();
        yield NumberField::new('heightCm', 'Height (cm)')->setNumDecimals(1)->hideOnForm()->hideOnIndex();
        yield ChoiceField::new('activityLevel')->hideOnForm()->hideOnIndex();
        yield NumberField::new('weeklyGoalKg', 'Weekly goal (kg)')->setNumDecimals(2)->hideOnForm();
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance === $this->getUser()) {
            throw new \LogicException("You can't delete your own account from the admin.");
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }
}
