<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Folder;
use App\Repository\FolderRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Folder select rendered as an indented tree.
 *
 * @extends AbstractType<\App\Entity\Folder>
 */
final class FolderChoiceType extends AbstractType
{
    public function __construct(private readonly FolderRepository $folders)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Folder::class,
            'exclude' => null,
            'required' => false,
            'placeholder' => 'folder.root',
            'choice_label' => static fn (Folder $folder): string => str_repeat('— ', $folder->getDepth()).$folder->getName(),
            'choice_translation_domain' => false,
        ]);
        $resolver->setAllowedTypes('exclude', ['null', Folder::class]);
        $resolver->setDefault('choices', function (Options $options): array {
            $exclude = $options['exclude'];

            return array_values(array_filter(
                $this->folders->findTree(),
                static fn (Folder $f): bool => null === $exclude || ($f !== $exclude && !$f->isDescendantOf($exclude)),
            ));
        });
    }

    public function getParent(): string
    {
        return EntityType::class;
    }
}
