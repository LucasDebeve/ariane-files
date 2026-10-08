<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\ProposalData;
use App\Service\Upload\FileType as AllowedFileType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

/**
 * One form for the three kinds of proposal ("mode" option: ajout, modification, suppression).
 */
/**
 * @extends AbstractType<\App\Dto\ProposalData>
 */
final class ProposalType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('proposerName', TextType::class, [
            'label' => 'proposal.form.proposer_name',
            'help' => 'proposal.form.proposer_name_help',
            'attr' => ['autocomplete' => 'name', 'maxlength' => 100],
        ]);

        if ('suppression' === $options['mode']) {
            $builder->add('reason', TextareaType::class, [
                'label' => 'proposal.form.deletion_reason',
                'attr' => ['rows' => 4, 'maxlength' => 2000],
            ]);

            return;
        }

        $builder
            ->add('title', TextType::class, [
                'label' => 'proposal.form.title',
                'attr' => ['maxlength' => 200],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'proposal.form.description',
                'required' => false,
                'attr' => ['rows' => 4, 'maxlength' => 5000],
            ])
            ->add('folder', FolderChoiceType::class, [
                'label' => 'proposal.form.folder',
            ])
            ->add('tags', TextType::class, [
                'label' => 'proposal.form.tags',
                'help' => 'proposal.form.tags_help',
                'required' => false,
                'attr' => ['maxlength' => 500, 'placeholder' => 'proposal.form.tags_placeholder'],
            ])
            ->add('file', FileType::class, [
                'label' => 'ajout' === $options['mode'] ? 'proposal.form.file' : 'proposal.form.replace_file',
                'help' => 'proposal.form.file_help',
                'help_translation_parameters' => ['%max%' => (int) round($options['max_upload_bytes'] / 1048576)],
                'required' => false,
                'attr' => ['accept' => AllowedFileType::acceptAttribute()],
                'constraints' => [new File(maxSize: $options['max_upload_bytes'])],
            ])
            ->add('videoUrl', UrlType::class, [
                'label' => 'proposal.form.video_url',
                'help' => 'proposal.form.video_url_help',
                'required' => false,
                'default_protocol' => null,
                'attr' => ['placeholder' => 'https://www.youtube.com/watch?v=…'],
            ]);

        if ('modification' === $options['mode']) {
            $builder->add('reason', TextareaType::class, [
                'label' => 'proposal.form.modification_reason',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 2000],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProposalData::class,
            'csrf_token_id' => 'proposal',
            'max_upload_bytes' => 104857600,
            'validation_groups' => static fn (\Symfony\Component\Form\FormInterface $form): array => ['Default', match ($form->getConfig()->getOption('mode')) {
                'ajout' => 'addition',
                'modification' => 'modification',
                default => 'deletion',
            }],
        ]);
        $resolver->setRequired('mode');
        $resolver->setAllowedValues('mode', ['ajout', 'modification', 'suppression']);
        $resolver->setAllowedTypes('max_upload_bytes', 'int');
    }
}
