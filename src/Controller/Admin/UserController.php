<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/utilisateurs')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_users', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/users.html.twig', ['users' => $this->users->findAllForAdmin()]);
    }

    #[Route('/{id}/{action}', name: 'admin_user_action', requirements: ['id' => Requirement::DIGITS, 'action' => 'approuver|desactiver|reactiver|reinitialiser-2fa'], methods: ['POST'])]
    public function action(int $id, string $action, Request $request): Response
    {
        $user = $this->users->find($id) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('user_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        /** @var User $admin */
        $admin = $this->getUser();
        if ($user->getId() === $admin->getId()) {
            $this->addFlash('error', 'admin.users.flash.self');

            return $this->redirectToRoute('admin_users', status: Response::HTTP_SEE_OTHER);
        }

        match ($action) {
            'approuver', 'reactiver' => $user->approve(),
            'desactiver' => $user->setStatus(UserStatus::Disabled),
            'reinitialiser-2fa' => $user->setTotpSecret(null),
            default => throw $this->createNotFoundException(),
        };
        $this->auditLogger->log('user.'.$action, $user, ['email' => $user->getEmail()]);
        $this->entityManager->flush();
        $this->addFlash('success', 'admin.users.flash.'.$action);

        return $this->redirectToRoute('admin_users', status: Response::HTTP_SEE_OTHER);
    }
}
