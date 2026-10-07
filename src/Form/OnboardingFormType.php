<?php

namespace App\Form;

use App\Entity\User;
use App\Profile\ActivityLevel;
use App\Profile\Sex;
use App\Weight\WeightRecorder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\BirthdayType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** "Tell us about yourself" right after registering: everything the calorie formula needs, all required. */
class OnboardingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sex', EnumType::class, [
                'class' => Sex::class,
                'choice_label' => fn (Sex $sex) => $sex->label(),
                'expanded' => true,
                'constraints' => [new Assert\NotNull(message: 'Please choose one.')],
                'help' => 'The calorie formula differs for male and female bodies.',
            ])
            ->add('birthDate', BirthdayType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull(message: 'Please enter your birth date.')],
                'help' => 'Your age is part of the formula.',
            ])
            ->add('heightCm', NumberType::class, [
                'label' => 'Height (cm)',
                'scale' => 1,
                'html5' => true,
                'attr' => ['min' => 100, 'max' => 250, 'step' => '0.5', 'inputmode' => 'decimal'],
                'constraints' => [new Assert\NotNull(message: 'Please enter your height.')],
            ])
            ->add('weightKg', NumberType::class, [
                'label' => 'Weight today (kg)',
                'mapped' => false,
                'scale' => 1,
                'html5' => true,
                'attr' => ['min' => WeightRecorder::MIN_KG, 'max' => WeightRecorder::MAX_KG, 'step' => '0.1', 'inputmode' => 'decimal'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Please enter your weight.'),
                    new Assert\Range(min: WeightRecorder::MIN_KG, max: WeightRecorder::MAX_KG, notInRangeMessage: 'Weight must be between {{ min }} and {{ max }} kg.'),
                ],
            ])
            ->add('activityLevel', EnumType::class, [
                'label' => 'Your everyday activity',
                'class' => ActivityLevel::class,
                'expanded' => true,
                'choice_label' => fn (ActivityLevel $level) => $level->label(),
                'choice_attr' => fn (ActivityLevel $level) => ['data-description' => $level->description()],
                'constraints' => [new Assert\NotNull(message: 'Please choose one.')],
                'help' => "Your normal day without workouts. Don't count workouts here: log them as exercise and they're added on top, so nothing is counted twice.",
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Validated by the constraints above (the User entity allows these fields to be empty).
        $resolver->setDefaults(['data_class' => User::class, 'validation_groups' => ['Default']]);
    }
}
