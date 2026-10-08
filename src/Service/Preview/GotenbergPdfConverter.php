<?php

declare(strict_types=1);

namespace App\Service\Preview;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gotenberg (LibreOffice route). The container runs without outbound network access.
 */
final class GotenbergPdfConverter implements PdfConverter
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $gotenbergUrl,
    ) {
    }

    public function convert($stream, string $filename): ?string
    {
        if ('' === $this->gotenbergUrl) {
            return null;
        }
        // Gotenberg picks the converter from the extension; the name itself is not trusted.
        $safeName = 'document.'.preg_replace('/[^a-z0-9]/', '', mb_strtolower(pathinfo($filename, \PATHINFO_EXTENSION)));
        $form = new FormDataPart(['files' => new DataPart($stream, $safeName)]);

        try {
            $response = $this->httpClient->request('POST', rtrim($this->gotenbergUrl, '/').'/forms/libreoffice/convert', [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
                'timeout' => 180,
            ]);
            $pdf = $response->getContent();

            return str_starts_with($pdf, '%PDF') ? $pdf : null;
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Gotenberg conversion failed: {message}', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
