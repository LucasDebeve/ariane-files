<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AuditController extends AbstractController
{
    private const PER_PAGE = 50;

    #[Route('/admin/journal', name: 'admin_audit', methods: ['GET'])]
    public function index(Request $request, AuditLogRepository $logs): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('admin/audit.html.twig', [
            'logs' => $logs->findPage($page, self::PER_PAGE),
            'page' => $page,
            'hasNext' => $logs->count([]) > $page * self::PER_PAGE,
        ]);
    }
}
