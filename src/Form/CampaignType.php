<?php

namespace App\Form;

use App\Entity\AuditCampaign;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class CampaignType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de la campagne',
                'attr' => [
                    'placeholder' => 'Ex: Audit initial janvier 2025',
                    'class' => 'form-control'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Le nom de la campagne est obligatoire']),
                    new Assert\Length([
                        'min' => 3,
                        'max' => 255,
                        'minMessage' => 'Le nom doit contenir au moins {{ limit }} caractères',
                        'maxMessage' => 'Le nom ne peut pas dépasser {{ limit }} caractères'
                    ])
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Description de la campagne d\'audit (objectifs, périmètre, etc.)',
                    'class' => 'form-control',
                    'rows' => 4
                ]
            ])
            ->add('sampleType', ChoiceType::class, [
                'label' => 'Type d\'échantillon',
                'choices' => [
                    'Personnalisé (sélection manuelle des pages)' => 'custom',
                    'Représentatif (5 pages types recommandées)' => 'representative',
                    'Exhaustif (toutes les pages du site)' => 'exhaustive',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'Le type d\'échantillon détermine quelles pages seront auditées'
            ])
            ->add('startDate', DateType::class, [
                'label' => 'Date de début',
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control'
                ],
                'help' => 'Date de début de la campagne d\'audit'
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Date de fin prévue',
                'required' => false,
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control'
                ],
                'help' => 'Date de fin prévue (peut être modifiée ultérieurement)'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AuditCampaign::class,
        ]);
    }
}
