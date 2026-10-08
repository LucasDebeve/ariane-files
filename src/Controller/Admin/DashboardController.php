<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\ChangeRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\DownloadStatRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function index(
        DocumentRepository $documents,
        DownloadStatRepository $stats,
        ChangeRequestRepository $changeRequests,
        UserRepository $users,
        #[Autowire(param: 'app.quarantine_quota_bytes')] int $quarantineQuota,
    ): Response {
        $daily = $stats->dailyTotals(30);

        return $this->render('admin/dashboard.html.twig', [
            'documentCount' => $documents->countPublished(),
            'publishedSize' => $documents->sumPublishedSize(),
            'storedSize' => $documents->sumAllStoredSize(),
            'daily' => $daily,
            'dailyMax' => max(1, ...array_values($daily)),
            'downloads30' => array_sum($daily),
            'top' => $stats->topDocuments(30, 10),
            'pendingRequests' => $changeRequests->countPending(),
            'pendingUsers' => $users->countPending(),
            'quarantineSize' => $changeRequests->sumQuarantineSize(),
            'quarantineQuota' => $quarantineQuota,
        ]);
    }
}
