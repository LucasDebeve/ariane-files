<?php

declare(strict_types=1);

namespace App\Service\TextExtraction;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Apache Tika server (the "-full" image ships Tesseract, so scanned PDFs are OCRed in French).
 * Plain text files are read directly.
 */
final class TikaTextExtractor implements TextExtractor
{
    public const MAX_LENGTH = 1_000_000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $tikaUrl,
    ) {
    }

    public function extract($stream, string $mimeType): ?string
    {
        if ('text/plain' === $mimeType) {
            return self::normalize((string) stream_get_contents($stream, self::MAX_LENGTH * 2));
        }
        if ('' === $this->tikaUrl) {
            return null;
        }

        try {
            $response = $this->httpClient->request('PUT', rtrim($this->tikaUrl, '/').'/tika', [
                'headers' => [
                    'Accept' => 'text/plain; charset=UTF-8',
                    'Content-Type' => $mimeType,
                    'X-Tika-OCRLanguage' => 'fra+eng',
                    'X-Tika-PDFOcrStrategy' => 'auto',
                ],
                'body' => $stream,
                'timeout' => 300,
            ]);

            return self::normalize($response->getContent());
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Tika text extraction failed: {message}', ['message' => $e->getMessage()]);

            return null;
        }
    }

    public static function normalize(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = (string) preg_replace('/[^\P{C}\n\t]+/u', ' ', $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        return mb_substr(trim($text), 0, self::MAX_LENGTH);
    }
}
