<?php

namespace App\Controller\Admin;

use App\Entity\MealEntry;
use App\Meal\MealStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Read-only view of logged meals and their AI estimates, mainly to see what the AI did and why
 * estimates failed. Admins can delete a meal but not edit one.
 *
 * @extends AbstractCrudController<MealEntry>
 */
class MealEntryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return MealEntry::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Meal')
            ->setEntityLabelInPlural('Meals')
            ->setDefaultSort(['eatenAt' => 'DESC'])
            ->setSearchFields(['rawText', 'user.email', 'estimatedBy'])
            ->setTimezone('UTC');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('user')->add('status')->add('eatenAt')->add('estimatedBy');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield AssociationField::new('user')->formatValue(fn ($value, MealEntry $entry) => $entry->getUser()->getEmail());
        yield DateTimeField::new('eatenAt', 'Eaten (UTC)');
        yield TextareaField::new('rawText', 'What the user typed');
        yield ChoiceField::new('status')->renderAsBadges([
            MealStatus::Estimated->value => 'success',
            MealStatus::Pending->value => 'warning',
            MealStatus::Failed->value => 'danger',
        ]);
        yield NumberField::new('kcal')->setNumDecimals(0);
        yield NumberField::new('protein', 'Protein (g)')->setNumDecimals(1)->hideOnIndex();
        yield NumberField::new('carbs', 'Carbs (g)')->setNumDecimals(1)->hideOnIndex();
        yield NumberField::new('fat', 'Fat (g)')->setNumDecimals(1)->hideOnIndex();
        yield Field::new('items', 'Items')->onlyOnDetail()->setTemplatePath('admin/field/meal_items.html.twig');
        yield TextField::new('estimatedBy', 'Estimated by');
        yield IntegerField::new('estimationAttempts', 'Attempts')->hideOnIndex();
        yield DateTimeField::new('lastEstimationAttemptAt', 'Last attempt (UTC)')->hideOnIndex();
        yield TextareaField::new('lastEstimationError', 'Last error')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Logged (UTC)')->hideOnIndex();
    }
}
