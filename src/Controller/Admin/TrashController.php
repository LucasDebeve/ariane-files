<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\DocumentRepository;
use App\Security\Voter\DocumentVoter;
use App\Service\AuditLogger;
use App\Service\Purger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/corbeille')]
final class TrashController extends AbstractController
{
    public function __construct(private readonly DocumentRepository $documents)
    {
    }

    #[Route('', name: 'admin_trash', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/trash.html.twig', [
            'documents' => $this->documents->findTrashed(),
            'retentionDays' => Purger::TRASH_RETENTION_DAYS,
        ]);
    }

    #[Route('/{id}/restaurer', name: 'admin_trash_restore', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function restore(string $id, Request $request, EntityManagerInterface $entityManager, AuditLogger $auditLogger): Response
    {
        $document = $this->documents->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(DocumentVoter::MANAGE_TRASH, $document);
        if (!$this->isCsrfTokenValid('trash_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $document->restore();
        $auditLogger->log('document.restored', $document, ['title' => $document->getTitle()]);
        $entityManager->flush();
        $this->addFlash('success', 'admin.trash.flash.restored');

        return $this->redirectToRoute('admin_trash', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/purger', name: 'admin_trash_purge', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function purge(string $id, Request $request, Purger $purger): Response
    {
        $document = $this->documents->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(DocumentVoter::MANAGE_TRASH, $document);
        if (!$this->isCsrfTokenValid('trash_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $purger->purgeDocument($document);
        $this->addFlash('success', 'admin.trash.flash.purged');

        return $this->redirectToRoute('admin_trash', status: Response::HTTP_SEE_OTHER);
    }
}
