<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * @extends AbstractType<array{code: string}|null>
 */
final class TwoFactorSetupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('code', TextType::class, [
            'label' => 'twofactor.setup.code',
            'attr' => ['autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'pattern' => '[0-9]{6}', 'maxlength' => 6],
            'constraints' => [new NotBlank(), new Regex(pattern: '/^\d{6}$/', message: 'twofactor.setup.code_format')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => '2fa_setup']);
    }
}
