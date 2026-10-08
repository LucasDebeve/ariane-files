<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array{code: string}|null>
 */
final class ShareCodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('code', PasswordType::class, [
            'label' => 'access.form.code',
            'attr' => ['autocomplete' => 'off', 'autofocus' => true, 'placeholder' => 'access.form.placeholder', 'autocapitalize' => 'characters'],
            'constraints' => [new NotBlank(), new Length(max: 100)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'share_code']);
    }
}
