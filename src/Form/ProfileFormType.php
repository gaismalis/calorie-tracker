<?php

namespace App\Form;

use App\Entity\User;
use App\Profile\ActivityLevel;
use App\Profile\Sex;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\BirthdayType;
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
            ->add('timezone', TimezoneType::class, [
                'help' => 'Decides when your day starts and ends.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
