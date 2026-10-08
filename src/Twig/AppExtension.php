<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Repository\ChangeRequestRepository;
use App\Security\CspNonce;
use App\Service\Upload\FileType;
use App\Service\VideoLink;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppExtension extends AbstractExtension
{
    private ?int $pendingCount = null;

    public function __construct(
        private readonly CspNonce $nonce,
        private readonly ChangeRequestRepository $changeRequests,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->nonce->get(...)),
            new TwigFunction('pending_reviews_count', $this->pendingReviewsCount(...)),
            new TwigFunction('file_accept', FileType::acceptAttribute(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('bytes', self::formatBytes(...)),
            new TwigFilter('file_family', FileType::family(...)),
            new TwigFilter('video_embed', VideoLink::embedUrl(...)),
            new TwigFilter('video_platform', VideoLink::platform(...)),
            new TwigFilter('repeat', static fn (string $value, int $times): string => str_repeat($value, max(0, $times))),
        ];
    }

    public function pendingReviewsCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted(User::ROLE_CERTIFIED)) {
            return 0;
        }

        return $this->pendingCount ??= $this->changeRequests->countPending();
    }

    public static function formatBytes(int|string|null $bytes): string
    {
        $bytes = (float) $bytes;
        $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        $i = 0;
        while ($bytes >= 1024 && $i < \count($units) - 1) {
            $bytes /= 1024;
            ++$i;
        }
        $formatted = 0 === $i ? (string) (int) $bytes : number_format($bytes, $bytes < 10 ? 1 : 0, ',', ' ');

        return $formatted.' '.$units[$i];
    }
}
