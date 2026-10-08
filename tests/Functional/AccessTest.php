<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\UserStatus;

final class AccessTest extends AppWebTestCase
{
    public function testVisitorWithoutCodeIsSentToTheAccessPage(): void
    {
        $this->client->request('GET', '/documents');

        self::assertResponseRedirects('/acces');
    }

    public function testWrongCodeIsRefused(): void
    {
        $this->enterShareCode('WRONG-CODE');
        $this->client->followRedirect();

        self::assertSelectorTextContains('[role=status]', 'Ce code n\'est pas valide');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/acces');
    }

    public function testValidCodeGrantsAccessCaseInsensitively(): void
    {
        $this->enterShareCode('test code 2026');
        self::assertResponseRedirects('/');

        $this->client->request('GET', '/documents');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString("script-src 'self' 'nonce-", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testRotatingTheCodeRevokesVisitorSessions(): void
    {
        $this->enterShareCode();
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        static::getContainer()->get(\App\Security\ShareAccess::class)->rotate();

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/acces');
    }

    public function testShareCodeAttemptsAreRateLimited(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->enterShareCode('WRONG-'.$i);
        }
        $this->enterShareCode();
        $this->client->followRedirect();

        self::assertSelectorTextContains('[role=status]', 'Trop de tentatives');
    }

    public function testCertifiedAndAdminAreasRequireLogin(): void
    {
        $this->enterShareCode();

        $this->client->request('GET', '/certifie');
        self::assertResponseRedirects('/connexion');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/connexion');
    }

    public function testCertifiedUserCannotOpenAdministration(): void
    {
        $this->client->loginUser($this->createCertified('alex@example.org'));

        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCertifiedUserWithout2faMustConfigureIt(): void
    {
        $this->client->loginUser($this->createCertified('sam@example.org', with2fa: false));

        $this->client->request('GET', '/certifie');
        self::assertResponseRedirects('/compte/2fa');
        $this->client->followRedirect();
        self::assertSelectorExists('img[alt*="QR code"]');
    }

    public function testRegistrationCreatesPendingAccountThatCannotLogIn(): void
    {
        $this->client->request('GET', '/inscription');
        $this->client->submitForm('Envoyer ma demande', [
            'registration[displayName]' => 'Dominique Martin',
            'registration[email]' => 'Dominique@Example.org',
            'registration[plainPassword][first]' => 'une phrase de passe solide 2026',
            'registration[plainPassword][second]' => 'une phrase de passe solide 2026',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Demande envoyée');

        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'dominique@example.org']);
        self::assertNotNull($user);
        self::assertSame(UserStatus::Pending, $user->getStatus());
        self::assertNotContains(User::ROLE_CERTIFIED, $user->getRoles());
        self::assertStringStartsWith('$argon2id$', $user->getPassword());

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => 'dominique@example.org', 'password' => 'une phrase de passe solide 2026']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'en attente d\'approbation');
    }
}
