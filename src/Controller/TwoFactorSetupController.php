<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\TwoFactorSetupType;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mandatory TOTP enrolment. The candidate secret lives in the session until
 * the user proves their authenticator app generates valid codes.
 */
final class TwoFactorSetupController extends AbstractController
{
    private const SESSION_KEY = 'totp_candidate_secret';

    #[Route('/compte/2fa', name: 'account_2fa_setup', methods: ['GET', 'POST'])]
    public function setup(
        Request $request,
        TotpAuthenticatorInterface $totpAuthenticator,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
        TranslatorInterface $translator,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if ($user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('certified_index');
        }

        $session = $request->getSession();
        $secret = $session->get(self::SESSION_KEY);
        if (!\is_string($secret) || '' === $secret) {
            $secret = $totpAuthenticator->generateSecret();
            $session->set(self::SESSION_KEY, $secret);
        }

        // Check the code against a clone carrying the candidate secret.
        $candidate = clone $user;
        $candidate->setTotpSecret($secret);

        $form = $this->createForm(TwoFactorSetupType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($totpAuthenticator->checkCode($candidate, (string) $form->get('code')->getData())) {
                $user->setTotpSecret($secret);
                $session->remove(self::SESSION_KEY);
                $auditLogger->log('user.2fa_enabled', $user);
                $entityManager->flush();
                $this->addFlash('success', 'twofactor.setup.done');

                return $this->redirectToRoute('certified_index', status: Response::HTTP_SEE_OTHER);
            }
            $form->get('code')->addError(new FormError($translator->trans('twofactor.setup.invalid')));
        }

        $uri = $totpAuthenticator->getQRContent($candidate);
        $qrCode = (new Builder(writer: new SvgWriter(), data: $uri, size: 220, margin: 8))->build();

        return $this->render('security/2fa_setup.html.twig', [
            'form' => $form,
            'qrCode' => $qrCode->getDataUri(),
            'secret' => $secret,
        ]);
    }
}
