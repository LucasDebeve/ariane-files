<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ProposalData;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\ChangeRequestType;
use App\Form\ProposalType;
use App\Repository\DocumentRepository;
use App\Security\Voter\DocumentVoter;
use App\Service\Captcha\CaptchaVerifier;
use App\Service\ProposalService;
use App\Service\Upload\RejectedFileException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Anyone with the share code may propose an addition, a modification or a deletion.
 * Proposals are rate limited (5 per hour and per IP) and protected by a captcha.
 */
#[IsGranted('SHARE_ACCESS')]
final class ProposalController extends AbstractController
{
    public function __construct(
        private readonly ProposalService $proposals,
        private readonly CaptchaVerifier $captcha,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.proposal')]
        private readonly RateLimiterFactoryInterface $proposalLimiter,
        #[Autowire(param: 'app.max_upload_bytes')]
        private readonly int $maxUploadBytes,
    ) {
    }

    #[Route('/proposer', name: 'proposal_add', methods: ['GET', 'POST'])]
    public function add(Request $request): Response
    {
        $data = new ProposalData();
        $data->isAddition = true;

        return $this->handle($request, ChangeRequestType::Addition, $data, null);
    }

    #[Route('/documents/{id}/modifier', name: 'proposal_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, DocumentRepository $documents): Response
    {
        $document = $documents->findPublished($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(DocumentVoter::PROPOSE_CHANGE, $document);

        return $this->handle($request, ChangeRequestType::Modification, ProposalData::fromDocument($document), $document);
    }

    #[Route('/documents/{id}/supprimer', name: 'proposal_delete', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function delete(string $id, Request $request, DocumentRepository $documents): Response
    {
        $document = $documents->findPublished($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(DocumentVoter::PROPOSE_CHANGE, $document);

        return $this->handle($request, ChangeRequestType::Deletion, new ProposalData(), $document);
    }

    #[Route('/proposer/merci', name: 'proposal_thanks', methods: ['GET'])]
    public function thanks(): Response
    {
        return $this->render('proposal/thanks.html.twig');
    }

    private function handle(Request $request, ChangeRequestType $type, ProposalData $data, ?Document $document): Response
    {
        $user = $this->getUser();
        $user = $user instanceof User ? $user : null;
        if (null !== $user && null === $data->proposerName) {
            $data->proposerName = $user->getDisplayName();
        }

        $form = $this->createForm(ProposalType::class, $data, [
            'mode' => $type->value,
            'max_upload_bytes' => $this->maxUploadBytes,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->checkAbuse($request, $form, null !== $user)) {
            try {
                $this->proposals->propose($type, $data, $document, $user);

                return $this->redirectToRoute('proposal_thanks', status: Response::HTTP_SEE_OTHER);
            } catch (RejectedFileException $e) {
                $form->get(ChangeRequestType::Deletion === $type ? 'reason' : 'file')
                    ->addError(new FormError($this->translator->trans($e->getMessage(), $e->getParameters())));
            }
        }

        return $this->render('proposal/form.html.twig', [
            'form' => $form,
            'type' => $type,
            'document' => $document,
            'needsCaptcha' => null === $user,
        ]);
    }

    /**
     * Captcha and rate limit for visitors; certified users are trusted.
     *
     * @param FormInterface<ProposalData> $form
     */
    private function checkAbuse(Request $request, FormInterface $form, bool $isCertified): bool
    {
        if ($isCertified) {
            return true;
        }
        if (!$this->captcha->verify($request->request->getString('altcha') ?: null)) {
            $form->addError(new FormError($this->translator->trans('captcha.invalid')));

            return false;
        }
        if (!$this->proposalLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            $form->addError(new FormError($this->translator->trans('proposal.rate_limited')));

            return false;
        }

        return true;
    }
}
