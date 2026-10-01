<?php

namespace App\Form;

use App\Entity\User;
use App\Profile\ActivityLevel;
use App\Profile\Sex;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\BirthdayType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sex', EnumType::class, [
                'class' => Sex::class,
                'choice_label' => fn (Sex $sex) => $sex->label(),
                'placeholder' => 'Choose…',
                'required' => false,
                'help' => 'Used only for the calorie formula.',
            ])
            ->add('birthDate', BirthdayType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('heightCm', NumberType::class, [
                'label' => 'Height (cm)',
                'required' => false,
                'scale' => 1,
                'html5' => true,
                'attr' => ['min' => 100, 'max' => 250, 'step' => '0.5'],
            ])
            ->add('activityLevel', EnumType::class, [
                'class' => ActivityLevel::class,
                'choice_label' => fn (ActivityLevel $level) => $level->label(),
                'placeholder' => 'Choose…',
                'required' => false,
            ])
            ->add('weeklyGoalKg', ChoiceType::class, [
                'label' => 'Weekly goal',
                'choices' => array_combine(array_map(self::goalLabel(...), User::WEEKLY_GOALS), User::WEEKLY_GOALS),
                'help' => 'Your daily target is adjusted by about 1100 kcal per kg per week. 0.25–0.5 kg/week is sustainable for most people.',
            ])
            ->add('timezone', TimezoneType::class, [
                'help' => 'Decides when your day starts and ends.',
            ]);
    }

    public static function goalLabel(float $kg): string
    {
        return match (true) {
            $kg < 0 => sprintf('Lose %s kg per week', abs($kg)),
            $kg > 0 => sprintf('Gain %s kg per week', $kg),
            default => 'Keep my weight',
        };
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
