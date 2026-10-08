<?php

declare(strict_types=1);

namespace App\Service\Antivirus;

/**
 * Minimal clamd client using the INSTREAM command over TCP.
 */
final class ClamAvScanner implements VirusScanner
{
    private const CHUNK_SIZE = 65536;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    public function scan(string $path): void
    {
        $file = @fopen($path, 'r');
        if (false === $file) {
            throw new ScannerUnavailableException('Unable to open the file to scan.');
        }

        $socket = @stream_socket_client(\sprintf('tcp://%s:%d', $this->host, $this->port), $errno, $error, 5);
        if (false === $socket) {
            fclose($file);
            throw new ScannerUnavailableException(\sprintf('clamd unreachable: %s', $error));
        }
        stream_set_timeout($socket, $this->timeoutSeconds);

        try {
            fwrite($socket, "zINSTREAM\0");
            while (!feof($file)) {
                $chunk = (string) fread($file, self::CHUNK_SIZE);
                if ('' === $chunk) {
                    break;
                }
                fwrite($socket, pack('N', \strlen($chunk)).$chunk);
            }
            fwrite($socket, pack('N', 0));
            $response = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($file);
            fclose($socket);
        }

        self::interpret($response);
    }

    /**
     * Parses a clamd reply such as "stream: OK" or "stream: Eicar-Signature FOUND".
     */
    public static function interpret(string $response): void
    {
        if (str_ends_with($response, ': OK')) {
            return;
        }
        if (1 === preg_match('/^stream: (.+) FOUND$/', $response, $matches)) {
            throw new InfectedFileException($matches[1]);
        }

        throw new ScannerUnavailableException(\sprintf('Unexpected clamd response: "%s"', $response));
    }
}
