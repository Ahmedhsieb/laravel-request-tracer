<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Storage;

/**
 * Payload columns hold JSON. Large payloads are stored as "gz:" + base64(gzip(json)): text columns are portable
 * to MySQL, PostgreSQL, SQLite and SQL Server, binary columns are not (see docs/decisions.md D5).
 */
final class PayloadCodec
{
    private const PREFIX = 'gz:';

    public function __construct(private readonly int $compressOver = 1024) {}

    public function encode(?string $json): ?string
    {
        if ($json === null || $json === '') {
            return $json;
        }
        if (strlen($json) > $this->compressOver && function_exists('gzencode')) {
            $gz = gzencode($json, 6);
            if ($gz !== false) {
                return self::PREFIX.base64_encode($gz);
            }
        }

        return $json;
    }

    /** @return string|null the JSON text */
    public function decode(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return $stored;
        }
        if (str_starts_with($stored, self::PREFIX)) {
            $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
            $json = $raw === false ? false : gzdecode($raw);

            return $json === false ? null : $json;
        }

        return $stored;
    }

    public function decodeValue(?string $stored): mixed
    {
        $json = $this->decode($stored);

        return $json === null ? null : json_decode($json, true);
    }
}
