<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DocumentRepository;
use App\Repository\FolderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('SHARE_ACCESS')]
final class FolderController extends AbstractController
{
    #[Route('/dossiers', name: 'folder_index', methods: ['GET'])]
    public function index(FolderRepository $folders, DocumentRepository $documents): Response
    {
        return $this->render('folder/index.html.twig', [
            'folders' => $folders->findTree(),
            'counts' => $folders->countDocumentsByFolder(),
            'rootDocuments' => $documents->findPublishedInFolder(null),
        ]);
    }

    #[Route('/dossiers/{slug}', name: 'folder_show', methods: ['GET'])]
    public function show(string $slug, FolderRepository $folders, DocumentRepository $documents): Response
    {
        $folder = $folders->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException();

        return $this->render('folder/show.html.twig', [
            'folder' => $folder,
            'documents' => $documents->findPublishedInFolder($folder),
            'counts' => $folders->countDocumentsByFolder(),
        ]);
    }
}
