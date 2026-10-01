<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Data: ['date' => DateTimeImmutable, 'weightKg' => float]. Not mapped to the entity because saving is an upsert per day. */
class WeightEntryFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('weightKg', NumberType::class, [
                'label' => 'Weight (kg)',
                'scale' => 1,
                'html5' => true,
                'attr' => ['min' => 20, 'max' => 400, 'step' => '0.1', 'autofocus' => true],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(min: 20, max: 400, notInRangeMessage: 'Weight must be between {{ min }} and {{ max }} kg.'),
                ],
            ])
            ->add('date', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'model_timezone' => 'UTC',
                'view_timezone' => 'UTC',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\LessThanOrEqual(value: $options['today'], message: "You can't log weight for a future date."),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('today');
        $resolver->setAllowedTypes('today', \DateTimeImmutable::class);
    }
}
