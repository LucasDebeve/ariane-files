<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ProposalData;
use App\Entity\ChangeRequest;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\ChangeRequestType;
use App\Repository\ChangeRequestRepository;
use App\Service\Antivirus\InfectedFileException;
use App\Service\Antivirus\ScannerUnavailableException;
use App\Service\Antivirus\VirusScanner;
use App\Service\Upload\FileInspector;
use App\Service\Upload\RejectedFileException;
use App\Storage\DocumentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns a visitor's proposal into a pending change request, with the uploaded
 * file checked (signature, size, antivirus) and stored in quarantine.
 */
final class ProposalService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChangeRequestRepository $changeRequests,
        private readonly FileInspector $inspector,
        private readonly VirusScanner $virusScanner,
        private readonly DocumentStorage $storage,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'app.quarantine_quota_bytes')]
        private readonly int $quarantineQuota,
    ) {
    }

    /**
     * @throws RejectedFileException
     */
    public function propose(ChangeRequestType $type, ProposalData $data, ?Document $target, ?User $user): ChangeRequest
    {
        $payload = match ($type) {
            ChangeRequestType::Deletion => ['reason' => trim((string) $data->reason)],
            default => array_filter([
                'title' => trim((string) $data->title),
                'description' => null !== $data->description ? trim($data->description) : null,
                'folderId' => $data->folder?->getId(),
                'tags' => TagResolver::parse($data->tags),
                'videoUrl' => null !== $data->videoUrl && '' !== trim($data->videoUrl) ? trim($data->videoUrl) : null,
                'reason' => null !== $data->reason && '' !== trim($data->reason) ? trim($data->reason) : null,
            ], static fn ($value): bool => null !== $value),
        };
        if (isset($payload['videoUrl']) && !VideoLink::isValid($payload['videoUrl'])) {
            throw new RejectedFileException('proposal.video.https');
        }

        $quarantineKey = null;
        $size = 0;
        if (ChangeRequestType::Deletion !== $type && null !== $data->file) {
            $inspected = $this->inspector->inspect($data->file->getPathname(), $data->file->getClientOriginalName());
            $this->assertQuota($inspected->size);
            $this->scan($inspected->path, (string) $data->proposerName);
            $quarantineKey = $this->storage->putInQuarantine($inspected->path);
            $size = $inspected->size;
            $payload['file'] = [
                'originalName' => $inspected->originalName,
                'mimeType' => $inspected->mimeType,
                'size' => $inspected->size,
                'sha256' => $inspected->sha256,
            ];
            unset($payload['videoUrl']);
        }

        $request = new ChangeRequest($type, (string) $data->proposerName, $payload, $target, $user);
        $request->setQuarantineFile($quarantineKey, $size);
        $this->entityManager->persist($request);
        $this->auditLogger->log('change_request.submitted', $request, ['type' => $type->value], $request->getProposerName());
        $this->entityManager->flush();

        return $request;
    }

    private function assertQuota(int $size): void
    {
        if ($this->changeRequests->sumQuarantineSize() + $size > $this->quarantineQuota) {
            throw new RejectedFileException('upload.error.quarantine_full');
        }
    }

    private function scan(string $path, string $proposerName): void
    {
        try {
            $this->virusScanner->scan($path);
        } catch (InfectedFileException $e) {
            $this->auditLogger->log('upload.infected', null, ['signature' => $e->signature], $proposerName);
            $this->entityManager->flush();
            throw new RejectedFileException('upload.error.infected');
        } catch (ScannerUnavailableException $e) {
            $this->logger->error('Antivirus unavailable: {message}', ['message' => $e->getMessage()]);
            throw new RejectedFileException('upload.error.scanner_unavailable');
        }
    }
}
