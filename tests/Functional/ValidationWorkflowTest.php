<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AuditLog;
use App\Entity\ChangeRequest;
use App\Entity\Document;
use App\Enum\ChangeRequestStatus;
use App\Enum\ChangeRequestType;
use App\Enum\DocumentStatus;
use App\Repository\ChangeRequestRepository;
use App\Service\Purger;
use App\Storage\DocumentStorage;
use App\Storage\StorageArea;
use App\Tests\Fixtures;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * End-to-end: a visitor proposes a document, a certified user validates it,
 * then it can be found, previewed and downloaded; it is later deleted through
 * the same circuit and restored from the trash by the administrator.
 */
final class ValidationWorkflowTest extends AppWebTestCase
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function reload(string $class, mixed $id): object
    {
        $this->em()->clear();
        $entity = $this->em()->find($class, $id);
        self::assertNotNull($entity);

        return $entity;
    }

    public function testFullLifecycleOfADocument(): void
    {
        // 1. A visitor with the share code proposes a PDF.
        $this->enterShareCode();
        $crawler = $this->client->request('GET', '/proposer');
        $form = $crawler->selectButton('Envoyer la proposition')->form([
            'proposal[title]' => 'Grille d\'observation des veillées',
            'proposal[description]' => 'Pour évaluer les animateurs stagiaires.',
            'proposal[tags]' => 'veillée, évaluation',
            'proposal[proposerName]' => 'Camille',
        ]);
        $pdf = Fixtures::pdf('Veillées');
        $fileField = $form['proposal[file]'];
        self::assertInstanceOf(FileFormField::class, $fileField);
        $fileField->upload(Fixtures::named($pdf, 'grille-veillees.pdf'));
        $this->client->submit($form);
        self::assertResponseRedirects('/proposer/merci');

        /** @var ChangeRequest $request */
        $request = static::getContainer()->get(ChangeRequestRepository::class)->findPending()[0];
        self::assertSame(ChangeRequestType::Addition, $request->getType());
        self::assertSame('Camille', $request->getProposerName());
        self::assertNull($request->getProposerUser());
        self::assertSame(hash('sha256', $pdf), $request->getFile()['sha256'] ?? null);
        $storage = static::getContainer()->get(DocumentStorage::class);
        self::assertTrue($storage->exists(StorageArea::Quarantine, (string) $request->getQuarantineKey()));

        // Nothing is published before validation.
        $this->client->request('GET', '/documents?q=veillées');
        self::assertSelectorTextContains('[role=status]', 'Aucun résultat');

        // 2. A certified user reviews and approves it.
        $reviewer = $this->createCertified('valideur@example.org');
        $this->client->loginUser($reviewer);
        $this->client->request('GET', '/certifie');
        self::assertSelectorTextContains('main', 'Grille d\'observation des veillées');

        $this->client->request('GET', '/certifie/propositions/'.$request->getId());
        $this->client->submitForm('Valider et publier', ['review[comment]' => 'Très utile, merci !']);
        self::assertResponseRedirects('/certifie');

        $request = $this->reload(ChangeRequest::class, $request->getId());
        self::assertSame(ChangeRequestStatus::Approved, $request->getStatus());
        self::assertSame('valideur@example.org', $request->getReviewer()?->getEmail());
        $document = $request->getDocument();
        self::assertInstanceOf(Document::class, $document);
        self::assertTrue($document->isPublished());
        self::assertSame(['veillée', 'évaluation'], $document->getTags()->map(fn ($t) => $t->getName())->toArray());
        self::assertNull($request->getQuarantineKey());
        self::assertTrue($storage->exists(StorageArea::Published, (string) $document->getStorageKey()));
        self::assertNotNull($this->em()->getRepository(AuditLog::class)->findOneBy(['action' => 'change_request.approved']));

        // 3. The document is searchable (accent and typo tolerant) and downloadable.
        $this->client->request('GET', '/documents?q=veillees');
        self::assertSelectorTextContains('main', 'Grille d\'observation des veillées');
        $this->client->request('GET', '/documents?q=observaton');
        self::assertSelectorTextContains('main', 'Grille d\'observation des veillées');

        $this->client->request('GET', '/documents/'.$document->getId().'/telecharger');
        self::assertResponseRedirects();
        $fileUrl = (string) $this->client->getResponse()->headers->get('Location');
        $this->client->request('GET', $fileUrl);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->client->request('GET', $fileUrl.'tampered');
        self::assertResponseStatusCodeSame(404);

        self::assertSame(1, $this->reload(Document::class, $document->getId())->getDownloadCount());

        // 4. Deletion goes through the same validation and lands in the trash.
        $this->client->request('GET', '/documents/'.$document->getId().'/supprimer');
        $this->client->submitForm('Envoyer la proposition', ['proposal[reason]' => 'Version obsolète']);
        self::assertResponseRedirects('/proposer/merci');
        $deletion = static::getContainer()->get(ChangeRequestRepository::class)->findPending()[0];
        self::assertSame($reviewer->getId(), $deletion->getProposerUser()?->getId());

        // The proposer cannot validate their own proposal...
        $this->client->request('GET', '/certifie/propositions/'.$deletion->getId());
        self::assertSelectorTextContains('aside', 'un autre formateur certifié doit la valider');
        // Forge a request with a valid CSRF token (generated when the reviewer approved earlier).
        $token = $this->client->getRequest()->getSession()->get('_csrf/review');
        self::assertIsString($token);
        $this->client->request('POST', '/certifie/propositions/'.$deletion->getId(), ['review' => ['comment' => '', '_token' => $token], 'decision' => 'approve']);
        self::assertResponseStatusCodeSame(403);

        // ...but the administrator can.
        $admin = $this->createCertified('admin@example.org', admin: true);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/certifie/propositions/'.$deletion->getId());
        $this->client->submitForm('Valider la suppression');
        self::assertResponseRedirects('/certifie');

        self::assertSame(DocumentStatus::Trashed, $this->reload(Document::class, $document->getId())->getStatus());
        $this->client->request('GET', '/documents/'.$document->getId());
        self::assertResponseStatusCodeSame(404);

        // 5. The administrator restores it from the trash.
        $this->client->request('GET', '/admin/corbeille');
        $this->client->submitForm('Restaurer');
        self::assertTrue($this->reload(Document::class, $document->getId())->isPublished());
    }

    public function testRejectionRequiresACommentAndKeepsTheFileForSevenDays(): void
    {
        $this->enterShareCode();
        $this->client->request('GET', '/proposer');
        $this->client->submitForm('Envoyer la proposition', [
            'proposal[title]' => 'Vidéo de jeu',
            'proposal[videoUrl]' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'proposal[proposerName]' => 'Lou',
        ]);
        self::assertResponseRedirects('/proposer/merci');
        $request = static::getContainer()->get(ChangeRequestRepository::class)->findPending()[0];

        $this->client->loginUser($this->createCertified('valideur@example.org'));
        $this->client->request('GET', '/certifie/propositions/'.$request->getId());
        $this->client->submitForm('Refuser', ['review[comment]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Un commentaire est obligatoire');

        $this->client->submitForm('Refuser', ['review[comment]' => 'Hors sujet']);
        self::assertResponseRedirects('/certifie');
        $request = $this->reload(ChangeRequest::class, $request->getId());
        self::assertSame(ChangeRequestStatus::Rejected, $request->getStatus());
        self::assertSame('Hors sujet', $request->getReviewComment());
    }

    public function testInvalidUploadIsRefusedWithoutCreatingAProposal(): void
    {
        $this->enterShareCode();
        $crawler = $this->client->request('GET', '/proposer');
        $form = $crawler->selectButton('Envoyer la proposition')->form([
            'proposal[title]' => 'Faux PDF',
            'proposal[proposerName]' => 'Mallory',
        ]);
        $fileField = $form['proposal[file]'];
        self::assertInstanceOf(FileFormField::class, $fileField);
        $fileField->upload(Fixtures::named("MZ\x90\x00binary", 'facture.pdf'));
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'ne correspond pas à son extension');
        self::assertSame(0, static::getContainer()->get(ChangeRequestRepository::class)->countPending());
    }

    public function testPurgeRemovesExpiredTrash(): void
    {
        $document = (new Document())->setTitle('Ancien');
        $storage = static::getContainer()->get(DocumentStorage::class);
        $storage->write(StorageArea::Published, 'old-key', 'x');
        $document->attachFile('old-key', 'ancien.txt', 'text/plain', 1, hash('sha256', 'x'));
        $document->moveToTrash();
        $this->em()->persist($document);
        $this->em()->flush();

        $result = static::getContainer()->get(Purger::class)->purgeExpired(new \DateTimeImmutable('+31 days'));

        self::assertSame(1, $result['documents']);
        self::assertFalse($storage->exists(StorageArea::Published, 'old-key'));
    }
}
