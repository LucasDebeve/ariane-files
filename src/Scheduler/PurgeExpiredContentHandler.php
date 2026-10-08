<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Service\Purger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PurgeExpiredContentHandler
{
    public function __construct(private readonly Purger $purger)
    {
    }

    public function __invoke(PurgeExpiredContent $message): void
    {
        $this->purger->purgeExpired();
    }
}
