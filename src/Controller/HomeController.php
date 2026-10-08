<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DocumentRepository;
use App\Repository\FolderRepository;
use App\Repository\TagRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('SHARE_ACCESS')]
final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(DocumentRepository $documents, FolderRepository $folders, TagRepository $tags): Response
    {
        return $this->render('home/index.html.twig', [
            'latest' => $documents->findLatestPublished(8),
            'folders' => $folders->findTree(),
            'popularTags' => $tags->findPopular(6),
            'popularFolders' => $folders->findMostUsed(4),
            'documentCount' => $documents->countPublished(),
        ]);
    }
}
