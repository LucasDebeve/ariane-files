<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Folder;
use App\Form\FolderType;
use App\Repository\FolderRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/dossiers')]
final class FolderAdminController extends AbstractController
{
    public function __construct(
        private readonly FolderRepository $folders,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_folders', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/folders.html.twig', [
            'folders' => $this->folders->findTree(),
            'counts' => $this->folders->countDocumentsByFolder(),
        ]);
    }

    #[Route('/nouveau', name: 'admin_folder_new', methods: ['GET', 'POST'])]
    #[Route('/{id}/modifier', name: 'admin_folder_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, ?int $id = null): Response
    {
        $folder = null !== $id ? ($this->folders->find($id) ?? throw $this->createNotFoundException()) : new Folder();
        $form = $this->createForm(FolderType::class, $folder);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $folder->setSlug($this->uniqueSlug($folder));
            $this->entityManager->persist($folder);
            $this->auditLogger->log(null === $id ? 'folder.created' : 'folder.updated', $folder, ['name' => $folder->getName()]);
            $this->entityManager->flush();
            $this->addFlash('success', 'admin.folders.flash.saved');

            return $this->redirectToRoute('admin_folders', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/folder_form.html.twig', ['form' => $form, 'folder' => $folder]);
    }

    #[Route('/{id}/supprimer', name: 'admin_folder_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $folder = $this->folders->find($id) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('folder_delete_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$folder->getChildren()->isEmpty() || !$folder->getDocuments()->isEmpty()) {
            $this->addFlash('error', 'admin.folders.flash.not_empty');

            return $this->redirectToRoute('admin_folders', status: Response::HTTP_SEE_OTHER);
        }
        $this->auditLogger->log('folder.deleted', $folder, ['name' => $folder->getName()]);
        $this->entityManager->remove($folder);
        $this->entityManager->flush();
        $this->addFlash('success', 'admin.folders.flash.deleted');

        return $this->redirectToRoute('admin_folders', status: Response::HTTP_SEE_OTHER);
    }

    private function uniqueSlug(Folder $folder): string
    {
        $base = $this->slugger->slug($folder->getPath())->lower()->toString() ?: 'dossier';
        $base = mb_substr($base, 0, 130);
        $slug = $base;
        for ($i = 2; ($existing = $this->folders->findOneBy(['slug' => $slug])) && $existing !== $folder; ++$i) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
