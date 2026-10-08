<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Folder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<\App\Entity\Folder>
 */
final class FolderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Folder|null $folder */
        $folder = $options['data'] ?? null;
        $builder
            ->add('name', TextType::class, ['label' => 'admin.folders.form.name'])
            ->add('parent', FolderChoiceType::class, [
                'label' => 'admin.folders.form.parent',
                'exclude' => null !== $folder?->getId() ? $folder : null,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Folder::class,
            'csrf_token_id' => 'folder',
        ]);
    }
}
