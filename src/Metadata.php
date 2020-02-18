<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper;

use JsonSerializable;
use PhpCfdi\CfdiSatScraper\Exceptions\InvalidArgumentException;

class Metadata implements JsonSerializable
{
    /** @var array<string, string> */
    private $data;

    /**
     * Metadata constructor.
     * $uuid will be converted to lower case.
     * If $data contains a key with 'uuid' will be ignored.
     *
     * @param string $uuid
     * @param array<string, string> $data
     * @throws InvalidArgumentException when UUID is empty
     */
    public function __construct(string $uuid, array $data = [])
    {
        if ('' === $uuid) {
            throw InvalidArgumentException::emptyInput('UUID');
        }
        $this->data = ['uuid' => strtolower($uuid)] + $data;
    }

    public function uuid(): string
    {
        return $this->data['uuid'];
    }

    public function get(string $key): string
    {
        return strval($this->data[$key] ?? '');
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
