<?php
namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{TextareaType, ChoiceType, IntegerType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class RunType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('requestText', TextareaType::class, ['label' => 'form.request', 'required' => false,
                'constraints' => [new Assert\Length(max: 2000)], 'attr' => ['rows' => 3, 'maxlength' => 2000,
                    'placeholder' => 'form.request_placeholder']])
            ->add('sources', ChoiceType::class, ['label' => 'form.sources', 'multiple' => true, 'expanded' => true,
                'choices' => ['form.solidjobs' => 'solidjobs', 'form.dou' => 'dou', 'form.djinni' => 'djinni'],
                'constraints' => [new Assert\Count(min: 1, max: 3)]])
            ->add('minimumScore', IntegerType::class, ['label' => 'form.minimum', 'constraints' => [new Assert\Range(min: 0, max: 100)], 'attr' => ['min' => 0, 'max' => 100]]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data' => ['sources' => ['solidjobs'], 'minimumScore' => 65]]); }
}
