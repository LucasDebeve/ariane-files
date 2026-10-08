<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ShareCodeType;
use App\Security\ShareAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class AccessController extends AbstractController
{
    use TargetPathTrait;

    public function __construct(
        private readonly ShareAccess $shareAccess,
        #[Autowire(service: 'limiter.share_code')]
        private readonly RateLimiterFactoryInterface $shareCodeLimiter,
    ) {
    }

    #[Route('/acces', name: 'access_code', methods: ['GET', 'POST'])]
    public function code(Request $request): Response
    {
        if ($this->isGranted('SHARE_ACCESS')) {
            return $this->redirectToRoute('home');
        }

        $form = $this->createForm(ShareCodeType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $this->shareCodeLimiter->create((string) $request->getClientIp());
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('error', 'access.flash.too_many_attempts');

                return $this->redirectToRoute('access_code', status: Response::HTTP_SEE_OTHER);
            }

            if ($this->shareAccess->verify((string) $form->get('code')->getData())) {
                $limiter->reset();
                $this->shareAccess->grant();
                $target = $this->getTargetPath($request->getSession(), 'main');
                $this->removeTargetPath($request->getSession(), 'main');

                return $this->redirect($target && str_starts_with($target, $request->getSchemeAndHttpHost().'/') ? $target : $this->generateUrl('home'), Response::HTTP_SEE_OTHER);
            }

            $this->addFlash('error', 'access.flash.invalid');

            return $this->redirectToRoute('access_code', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('access/code.html.twig', [
            'form' => $form,
            'configured' => $this->shareAccess->isConfigured(),
        ]);
    }

    #[Route('/acces/quitter', name: 'access_leave', methods: ['POST'])]
    public function leave(Request $request): Response
    {
        if ($this->isCsrfTokenValid('access_leave', (string) $request->request->get('_token'))) {
            $this->shareAccess->revoke();
        }

        return $this->redirectToRoute('access_code', status: Response::HTTP_SEE_OTHER);
    }
}
