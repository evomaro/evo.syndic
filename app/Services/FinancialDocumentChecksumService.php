<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

class FinancialDocumentChecksumService
{
    public const VERSION = 'sha256-document-bytes-v1';

    public function checksum(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    public function matches(string $expected, string $bytes): bool
    {
        return hash_equals($expected, $this->checksum($bytes));
    }

    public function evidenceFingerprint(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    public function canonicalJson(array $payload): string
    {
        return json_encode(
            $this->normalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z');
        }

        if ($value instanceof JsonSerializable) {
            return $this->normalize($value->jsonSerialize());
        }

        if (is_float($value)) {
            throw new InvalidArgumentException('Checksum evidence must use integers for financial values.');
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->normalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item) => $this->normalize($item), $value);
    }
}
