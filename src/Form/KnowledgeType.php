<?php
declare(strict_types=1);
namespace App\Form;

use App\Entity\KnowledgeEntry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType, DateType, HiddenType, TextareaType, TextType, UrlType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class KnowledgeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('title', TextType::class, ['label' => 'kb.title', 'attr' => ['maxlength' => 180]])
            ->add('site', ChoiceType::class, ['label' => 'kb.site', 'choices' => array_combine(array_map(static fn ($site) => 'kb.site.'.$site, KnowledgeEntry::SITES), KnowledgeEntry::SITES)])
            ->add('content', TextareaType::class, ['label' => 'kb.content', 'attr' => ['rows' => 10, 'maxlength' => 6000]])
            ->add('entryVersion', HiddenType::class, ['mapped' => false, 'data' => (string) ($options['data']?->revision ?? 1)]);
        if ($options['shared']) {
            $builder->add('kind', ChoiceType::class, ['label' => 'kb.kind', 'choices' => ['kb.kind.site_tip' => 'site_tip', 'kb.kind.search_prompt' => 'search_prompt']])
                ->add('locale', ChoiceType::class, ['label' => 'kb.language', 'choices' => ['UA' => 'uk', 'PL' => 'pl', 'EN' => 'en']])
                ->add('sourceUrl', UrlType::class, ['label' => 'kb.source', 'required' => false, 'empty_data' => '', 'attr' => ['maxlength' => 1000]])
                ->add('sourceNotes', TextareaType::class, ['label' => 'kb.source_notes', 'required' => false, 'empty_data' => '', 'attr' => ['rows' => 3, 'maxlength' => 1000]])
                ->add('reviewedAt', DateType::class, ['label' => 'kb.reviewed', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'])
                ->add('status', ChoiceType::class, ['label' => 'kb.status', 'choices' => ['kb.status.draft' => 'draft', 'kb.status.published' => 'published', 'kb.status.archived' => 'archived']]);
        }
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => KnowledgeEntry::class, 'shared' => false]); $resolver->setAllowedTypes('shared', 'bool'); }
}
