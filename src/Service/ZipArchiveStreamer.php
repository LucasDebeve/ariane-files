<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Storage\DocumentStorage;
use App\Storage\StorageArea;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\String\Slugger\AsciiSlugger;
use ZipStream\ZipStream;

/**
 * Streams a ZIP archive built on the fly from S3 objects (no temporary file).
 */
final class ZipArchiveStreamer
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly DownloadTracker $downloadTracker,
        #[Autowire(param: 'app.zip_max_files')]
        private readonly int $maxFiles,
        #[Autowire(param: 'app.zip_max_bytes')]
        private readonly int $maxBytes,
    ) {
    }

    /**
     * @param list<Document> $documents
     *
     * @return list<Document> the files that fit within the archive limits
     */
    public function selectFiles(array $documents): array
    {
        $selected = [];
        $bytes = 0;
        foreach ($documents as $document) {
            if (null === $document->getStorageKey() || !$document->isPublished()) {
                continue;
            }
            if (\count($selected) >= $this->maxFiles || $bytes + (int) $document->getSize() > $this->maxBytes) {
                throw new \LengthException('zip.error.too_large');
            }
            $bytes += (int) $document->getSize();
            $selected[] = $document;
        }

        return $selected;
    }

    /**
     * @param list<Document> $documents already filtered by {@see selectFiles()}
     */
    public function stream(array $documents, string $archiveName): StreamedResponse
    {
        $archiveName = (new AsciiSlugger())->slug($archiveName)->lower()->toString() ?: 'documents';
        $response = new StreamedResponse(function () use ($documents): void {
            $zip = new ZipStream(sendHttpHeaders: false, defaultEnableZeroHeader: true, enableZip64: true);
            $used = [];
            foreach ($documents as $document) {
                $stream = $this->storage->readStream(StorageArea::Published, (string) $document->getStorageKey());
                $zip->addFileFromStream(fileName: $this->entryName($document, $used), stream: $stream);
                if (\is_resource($stream)) {
                    fclose($stream);
                }
                $this->downloadTracker->track($document);
            }
            $zip->finish();
        });
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $archiveName.'.zip'));
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * @param array<string, true> $used
     */
    private function entryName(Document $document, array &$used): string
    {
        $folder = $document->getFolder()?->getName();
        $name = (string) $document->getOriginalFilename();
        $base = str_replace(['/', '\\', '..'], '-', null !== $folder ? $folder.'/'.$name : $name);
        $candidate = $base;
        for ($i = 2; isset($used[mb_strtolower($candidate)]); ++$i) {
            $extension = pathinfo($base, \PATHINFO_EXTENSION);
            $candidate = mb_substr($base, 0, -mb_strlen($extension) - 1).' ('.$i.').'.$extension;
        }
        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }
}
