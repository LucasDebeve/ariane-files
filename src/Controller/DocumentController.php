<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Repository\ChangeRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\FolderRepository;
use App\Repository\TagRepository;
use App\Security\Voter\DocumentVoter;
use App\Service\DocumentSearch;
use App\Service\DownloadTracker;
use App\Service\SearchCriteria;
use App\Service\Upload\FileType;
use App\Service\ZipArchiveStreamer;
use App\Storage\DocumentStorage;
use App\Storage\StorageArea;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('SHARE_ACCESS')]
final class DocumentController extends AbstractController
{
    private const PREVIEW_TTL = 300;
    private const DOWNLOAD_TTL = 60;
    private const TEXT_PREVIEW_BYTES = 200_000;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentStorage $storage,
    ) {
    }

    #[Route('/documents', name: 'document_index', methods: ['GET'])]
    public function index(Request $request, DocumentSearch $search, FolderRepository $folders, TagRepository $tags): Response
    {
        $folder = $request->query->getString('dossier') ? $folders->findOneBy(['slug' => $request->query->getString('dossier')]) : null;
        $tag = $request->query->getString('tag') ? $tags->findOneBy(['slug' => $request->query->getString('tag')]) : null;
        $criteria = new SearchCriteria(
            query: $request->query->getString('q') ?: null,
            folder: $folder,
            tag: $tag,
            page: max(1, $request->query->getInt('page', 1)),
        );

        return $this->render('document/index.html.twig', [
            'result' => $search->search($criteria),
            'criteria' => $criteria,
            'folders' => $folders->findTree(),
            'popularTags' => $tags->findPopular(12),
        ]);
    }

    #[Route('/documents/{id}', name: 'document_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id, ChangeRequestRepository $changeRequests): Response
    {
        $document = $this->findDocument($id);
        $this->denyAccessUnlessGranted(DocumentVoter::VIEW, $document);

        return $this->render('document/show.html.twig', [
            'document' => $document,
            'preview' => $this->buildPreview($document),
            'pendingChanges' => $changeRequests->countPendingFor($document),
        ]);
    }

    /**
     * Counts the download, then redirects to a 60-second presigned URL on files.*.
     */
    #[Route('/documents/{id}/telecharger', name: 'document_download', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function download(string $id, DownloadTracker $tracker): Response
    {
        $document = $this->findDocument($id);
        $this->denyAccessUnlessGranted(DocumentVoter::VIEW, $document);

        if ($document->isVideoLink()) {
            return new RedirectResponse((string) $document->getExternalUrl());
        }
        $tracker->track($document);
        $url = $this->storage->temporaryUrl(StorageArea::Published, (string) $document->getStorageKey(), (string) $document->getOriginalFilename(), (string) $document->getMimeType(), self::DOWNLOAD_TTL);

        $response = new RedirectResponse($url);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/documents/zip', name: 'document_zip', methods: ['POST'])]
    public function zip(
        Request $request,
        ZipArchiveStreamer $zipStreamer,
        #[Autowire(service: 'limiter.zip')] RateLimiterFactoryInterface $zipLimiter,
    ): Response {
        if (!$this->isCsrfTokenValid('document_zip', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        /** @var list<string> $ids */
        $ids = array_values(array_filter($request->request->all('ids'), 'is_string'));
        $documents = $this->documents->findPublishedByIds($ids);
        if ([] === $documents) {
            $this->addFlash('error', 'zip.error.empty');

            return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('document_index'), Response::HTTP_SEE_OTHER);
        }

        return $this->streamZip($request, $zipStreamer, $zipLimiter, $documents, 'ariane-selection');
    }

    #[Route('/dossiers/{slug}/zip', name: 'folder_zip', methods: ['GET'])]
    public function folderZip(
        string $slug,
        Request $request,
        FolderRepository $folders,
        ZipArchiveStreamer $zipStreamer,
        #[Autowire(service: 'limiter.zip')] RateLimiterFactoryInterface $zipLimiter,
        #[Autowire(param: 'app.zip_max_files')] int $maxFiles,
    ): Response {
        $folder = $folders->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException();
        $documents = $this->documents->findPublishedFilesInFolders($folders->findSubtreeIds($folder), $maxFiles + 1);
        if ([] === $documents) {
            $this->addFlash('error', 'zip.error.empty');

            return $this->redirectToRoute('folder_show', ['slug' => $slug], Response::HTTP_SEE_OTHER);
        }

        return $this->streamZip($request, $zipStreamer, $zipLimiter, $documents, 'ariane-'.$folder->getSlug());
    }

    /**
     * @param list<Document> $documents
     */
    private function streamZip(Request $request, ZipArchiveStreamer $zipStreamer, RateLimiterFactoryInterface $zipLimiter, array $documents, string $name): Response
    {
        try {
            $files = $zipStreamer->selectFiles($documents);
        } catch (\LengthException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('document_index'), Response::HTTP_SEE_OTHER);
        }
        if ([] === $files) {
            $this->addFlash('error', 'zip.error.empty');

            return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('document_index'), Response::HTTP_SEE_OTHER);
        }
        if (!$zipLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            $this->addFlash('error', 'zip.error.rate_limited');

            return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('document_index'), Response::HTTP_SEE_OTHER);
        }

        return $zipStreamer->stream($files, $name);
    }

    /**
     * @return array{type: string, url?: string, text?: string, embed?: string|null}
     */
    private function buildPreview(Document $document): array
    {
        if ($document->isVideoLink()) {
            return ['type' => 'video', 'embed' => \App\Service\VideoLink::embedUrl((string) $document->getExternalUrl())];
        }
        $key = (string) $document->getStorageKey();
        $mime = (string) $document->getMimeType();
        $name = (string) $document->getOriginalFilename();

        return match (true) {
            'application/pdf' === $mime => ['type' => 'pdf', 'url' => $this->storage->temporaryUrl(StorageArea::Published, $key, $name, $mime, self::PREVIEW_TTL)],
            FileType::isOffice($mime) && null !== $document->getPreviewKey() => ['type' => 'pdf', 'url' => $this->storage->temporaryUrl(StorageArea::Published, (string) $document->getPreviewKey(), pathinfo($name, \PATHINFO_FILENAME).'.pdf', 'application/pdf', self::PREVIEW_TTL)],
            FileType::isOffice($mime) => ['type' => 'pending'],
            FileType::isImage($mime) => ['type' => 'image', 'url' => $this->storage->temporaryUrl(StorageArea::Published, $key, $name, $mime, self::PREVIEW_TTL)],
            'text/plain' === $mime => ['type' => 'text', 'text' => $this->readText($key)],
            default => ['type' => 'none'],
        };
    }

    private function readText(string $key): string
    {
        try {
            $text = $this->storage->read(StorageArea::Published, $key, self::TEXT_PREVIEW_BYTES);
        } catch (\Throwable) {
            return '';
        }

        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    private function findDocument(string $id): Document
    {
        return $this->documents->findPublished($id) ?? throw new NotFoundHttpException();
    }
}
