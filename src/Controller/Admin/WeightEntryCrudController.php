<?php

namespace App\Controller\Admin;

use App\Entity\WeightEntry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;

/**
 * Read-only list of weigh-ins; admins can delete obviously wrong entries.
 *
 * @extends AbstractCrudController<WeightEntry>
 */
class WeightEntryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return WeightEntry::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Weight')
            ->setEntityLabelInPlural('Weights')
            ->setDefaultSort(['date' => 'DESC'])
            ->setSearchFields(['user.email'])
            ->setTimezone('UTC');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('user')->add('date')->add('weightKg');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield AssociationField::new('user')->formatValue(fn ($value, WeightEntry $entry) => $entry->getUser()->getEmail());
        yield DateField::new('date');
        yield NumberField::new('weightKg', 'Weight (kg)')->setNumDecimals(1);
        yield DateTimeField::new('updatedAt', 'Logged (UTC)');
    }
}
