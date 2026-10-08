<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationType;
use App\Service\AuditLogger;
use App\Service\Captcha\CaptchaVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SecurityController extends AbstractController
{
    #[Route('/connexion', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('certified_index');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/deconnexion', name: 'app_logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key of the firewall.');
    }

    /**
     * Open registration; the account stays "en_attente" until an administrator approves it.
     */
    #[Route('/inscription', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $entityManager,
        CaptchaVerifier $captcha,
        AuditLogger $auditLogger,
        TranslatorInterface $translator,
        #[Autowire(service: 'limiter.registration')] RateLimiterFactoryInterface $registrationLimiter,
    ): Response {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('home');
        }

        $user = new User();
        $form = $this->createForm(RegistrationType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$captcha->verify($request->request->getString('altcha') ?: null)) {
                $form->addError(new FormError($translator->trans('captcha.invalid')));
            } elseif (!$registrationLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError($translator->trans('registration.rate_limited')));
            } else {
                $user->setPassword($hasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
                $entityManager->persist($user);
                $auditLogger->log('user.registered', $user, ['email' => $user->getEmail()], $user->getDisplayName());
                $entityManager->flush();

                return $this->render('security/register_done.html.twig');
            }
        }

        return $this->render('security/register.html.twig', ['form' => $form]);
    }
}
