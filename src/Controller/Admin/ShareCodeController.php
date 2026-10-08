<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\ShareAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class ShareCodeController extends AbstractController
{
    #[Route('/admin/code-partage', name: 'admin_share_code', methods: ['GET', 'POST'])]
    public function index(Request $request, ShareAccess $shareAccess): Response
    {
        $newCode = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('share_code_rotate', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            /** @var User $admin */
            $admin = $this->getUser();
            // Shown in this response only: it is stored hashed.
            $newCode = $shareAccess->rotate($admin);
        }

        $response = $this->render('admin/share_code.html.twig', [
            'active' => $shareAccess->getActive(),
            'newCode' => $newCode,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
