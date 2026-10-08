<?php

declare(strict_types=1);

namespace App\Service\Upload;

/**
 * The message is a translation key (domain "messages").
 */
final class RejectedFileException extends \RuntimeException
{
    /**
     * @param array<string, string|int> $parameters
     */
    public function __construct(string $translationKey, private readonly array $parameters = [])
    {
        parent::__construct($translationKey);
    }

    /**
     * @return array<string, string|int>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
