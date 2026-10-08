<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\ShareAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class AppWebTestCase extends WebTestCase
{
    protected const SHARE_CODE = 'TEST-CODE-2026';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        // Rate limiter state must not leak from one test to another.
        static::getContainer()->get('cache.rate_limiter')->clear();
        static::getContainer()->get(ShareAccess::class)->rotate(null, self::SHARE_CODE);
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function enterShareCode(string $code = self::SHARE_CODE): void
    {
        $this->client->request('GET', '/acces');
        $this->client->submitForm('Accéder aux documents', ['share_code[code]' => $code]);
    }

    protected function createCertified(string $email, bool $admin = false, bool $with2fa = true): User
    {
        $user = (new User())->setEmail($email)->setDisplayName(ucfirst(explode('@', $email)[0]))->setPassword('not-used');
        $user->approve();
        if ($admin) {
            $user->setRoles([User::ROLE_CERTIFIED, User::ROLE_ADMIN]);
        }
        if ($with2fa) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        }
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }
}
