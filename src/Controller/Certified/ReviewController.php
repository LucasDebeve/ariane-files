<?php

declare(strict_types=1);

namespace App\Controller\Certified;

use App\Entity\ChangeRequest;
use App\Entity\User;
use App\Form\ReviewType;
use App\Repository\ChangeRequestRepository;
use App\Repository\FolderRepository;
use App\Security\Voter\ChangeRequestVoter;
use App\Service\ReviewService;
use App\Service\Upload\FileType;
use App\Storage\DocumentStorage;
use App\Storage\StorageArea;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_CERTIFIE')]
#[Route('/certifie')]
final class ReviewController extends AbstractController
{
    public function __construct(
        private readonly ChangeRequestRepository $changeRequests,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'certified_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('certified/index.html.twig', [
            'pending' => $this->changeRequests->findPending(),
            'reviewed' => $this->changeRequests->findReviewed(15),
        ]);
    }

    #[Route('/propositions/{id}', name: 'certified_review', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function review(string $id, Request $request, ReviewService $reviewService, FolderRepository $folders, DocumentStorage $storage): Response
    {
        $changeRequest = $this->find($id);
        $this->denyAccessUnlessGranted(ChangeRequestVoter::VIEW, $changeRequest);
        /** @var User $reviewer */
        $reviewer = $this->getUser();

        $form = $this->createForm(ReviewType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->denyAccessUnlessGranted(ChangeRequestVoter::REVIEW, $changeRequest);
            $comment = $form->get('comment')->getData();
            $decision = $request->request->getString('decision');

            if ('reject' === $decision && (null === $comment || '' === trim((string) $comment))) {
                $form->get('comment')->addError(new FormError($this->translator->trans('review.comment_required')));
            } elseif ('approve' === $decision || 'reject' === $decision) {
                try {
                    if ('approve' === $decision) {
                        $reviewService->approve($changeRequest, $reviewer, $comment);
                        $this->addFlash('success', 'review.flash.approved');
                    } else {
                        $reviewService->reject($changeRequest, $reviewer, (string) $comment);
                        $this->addFlash('success', 'review.flash.rejected');
                    }

                    return $this->redirectToRoute('certified_index', status: Response::HTTP_SEE_OTHER);
                } catch (\LogicException) {
                    $this->addFlash('error', 'review.flash.conflict');

                    return $this->redirectToRoute('certified_review', ['id' => $id], Response::HTTP_SEE_OTHER);
                }
            }
        }

        $file = $changeRequest->getFile();
        $quarantineKey = $changeRequest->getQuarantineKey();
        $fileUrl = null;
        if (null !== $file && null !== $quarantineKey) {
            $fileUrl = $storage->temporaryUrl(StorageArea::Quarantine, $quarantineKey, $file['originalName'], $file['mimeType'], 300);
        }
        $folderId = $changeRequest->getFolderId();

        return $this->render('certified/review.html.twig', [
            'changeRequest' => $changeRequest,
            'form' => $form,
            'canReview' => $this->isGranted(ChangeRequestVoter::REVIEW, $changeRequest),
            'isOwn' => null !== $changeRequest->getProposerUser() && $changeRequest->getProposerUser()->getId() === $reviewer->getId(),
            'proposedFolder' => null !== $folderId ? $folders->find($folderId) : null,
            'fileUrl' => $fileUrl,
            'fileIsPdf' => 'application/pdf' === ($file['mimeType'] ?? null),
            'fileIsImage' => FileType::isImage($file['mimeType'] ?? null),
        ]);
    }

    private function find(string $id): ChangeRequest
    {
        return $this->changeRequests->findByPublicId($id) ?? throw $this->createNotFoundException();
    }
}
