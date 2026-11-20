<?php
namespace App\Form;

use App\Entity\Profile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{TextType, TextareaType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, ['label' => 'form.name', 'empty_data' => '', 'attr' => ['maxlength' => 120, 'autocomplete' => 'name']])
            ->add('headline', TextType::class, ['label' => 'form.headline', 'required' => false, 'empty_data' => '', 'attr' => ['maxlength' => 200]])
            ->add('contactLine', TextType::class, ['label' => 'form.contact', 'required' => false, 'empty_data' => '', 'attr' => ['placeholder' => 'form.contact_placeholder', 'maxlength' => 300]])
            ->add('searchTerms', TextType::class, ['label' => 'form.search_terms', 'required' => false, 'empty_data' => '', 'attr' => ['placeholder' => 'form.search_placeholder', 'maxlength' => 120]])
            ->add('factsText', TextareaType::class, ['label' => 'form.facts', 'empty_data' => '',
                'help' => 'form.facts_help',
                'attr' => ['rows' => 12, 'maxlength' => 16000, 'placeholder' => 'form.facts_placeholder']])
            ->add('preferences', TextareaType::class, ['label' => 'form.preferences', 'required' => false, 'empty_data' => '',
                'attr' => ['rows' => 4, 'maxlength' => 3000, 'placeholder' => 'form.preferences_placeholder']]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => Profile::class]); }
}
