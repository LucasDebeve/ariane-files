<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Document;
use App\Entity\Folder;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form model shared by the addition, modification and deletion proposals.
 */
final class ProposalData
{
    #[Assert\NotBlank(message: 'proposal.proposer_name.required')]
    #[Assert\Length(min: 2, max: 100)]
    public ?string $proposerName = null;

    #[Assert\NotBlank(groups: ['addition', 'modification'])]
    #[Assert\Length(max: 200, groups: ['addition', 'modification'])]
    public ?string $title = null;

    #[Assert\Length(max: 5000, groups: ['addition', 'modification'])]
    public ?string $description = null;

    public ?Folder $folder = null;

    #[Assert\Length(max: 500, groups: ['addition', 'modification'])]
    public ?string $tags = null;

    public ?UploadedFile $file = null;

    #[Assert\Url(requireTld: true, groups: ['addition', 'modification'])]
    #[Assert\Length(max: 500, groups: ['addition', 'modification'])]
    public ?string $videoUrl = null;

    #[Assert\NotBlank(groups: ['deletion'], message: 'proposal.reason.required')]
    #[Assert\Length(max: 2000)]
    public ?string $reason = null;

    public bool $isAddition = false;

    public static function fromDocument(Document $document): self
    {
        $data = new self();
        $data->title = $document->getTitle();
        $data->description = $document->getDescription();
        $data->folder = $document->getFolder();
        $data->tags = '' !== $document->getTagsText() ? implode(', ', $document->getTags()->map(static fn ($t) => $t->getName())->toArray()) : null;
        $data->videoUrl = $document->getExternalUrl();

        return $data;
    }

    #[Assert\Callback(groups: ['addition', 'modification'])]
    public function validateContent(ExecutionContextInterface $context): void
    {
        $hasVideo = null !== $this->videoUrl && '' !== trim($this->videoUrl);
        if (null !== $this->file && $hasVideo) {
            $context->buildViolation('proposal.file_or_video')->atPath('videoUrl')->addViolation();
        }
        if ($this->isAddition && null === $this->file && !$hasVideo) {
            $context->buildViolation('proposal.file_or_video.required')->atPath('file')->addViolation();
        }
        if ($hasVideo && !str_starts_with(mb_strtolower(trim((string) $this->videoUrl)), 'https://')) {
            $context->buildViolation('proposal.video.https')->atPath('videoUrl')->addViolation();
        }
    }
}
