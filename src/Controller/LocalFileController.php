<?php

declare(strict_types=1);

namespace App\Controller;

use App\Storage\ContentDisposition;
use App\Storage\DocumentStorage;
use App\Storage\StorageArea;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Development/test stand-in for S3 presigned URLs (production serves files from files.* via Garage).
 */
final class LocalFileController extends AbstractController
{
    #[Route('/_fichiers/{area}/{key}', name: 'local_file', requirements: ['key' => '[A-Za-z0-9\-/\.]+'], methods: ['GET'])]
    public function serve(
        string $area,
        string $key,
        Request $request,
        UriSigner $uriSigner,
        DocumentStorage $storage,
        #[Autowire(param: 'kernel.environment')] string $environment,
    ): StreamedResponse {
        if ('prod' === $environment || !$uriSigner->checkRequest($request)) {
            throw $this->createNotFoundException();
        }
        $storageArea = StorageArea::tryFrom($area) ?? throw $this->createNotFoundException();
        if (str_contains($key, '..') || !$storage->exists($storageArea, $key)) {
            throw $this->createNotFoundException();
        }

        $response = new StreamedResponse(static function () use ($storage, $storageArea, $key): void {
            $stream = $storage->readStream($storageArea, $key);
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', $request->query->getString('type', 'application/octet-stream'));
        $response->headers->set('Content-Disposition', ContentDisposition::attachment($request->query->getString('name', 'document')));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
