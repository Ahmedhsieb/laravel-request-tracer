<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\DeepTrace;

/**
 * Probe id -> {file, line, kind, snippet, function}. Written once per compiled file (next to it), read only for the probes
 * that were hit, so the mapping is stored once per file instead of once per hit.
 *
 * A probe id is `(fileKey << 16) | local`, where fileKey is derived from the file's path.
 */
final class ProbeMap
{
    /** @var array<int, array{path: string, probes: array<int, array<string, mixed>>}|null> */
    private array $files = [];

    public function __construct(private readonly string $cachePath) {}

    public static function fileName(string $cachePath, int $fileKey): string
    {
        return rtrim($cachePath, '/\\').DIRECTORY_SEPARATOR.dechex($fileKey).'.map.php';
    }

    /** @return array{path: string, line: int, kind: string, snippet: string, name: string|null, function: array{0: string, 1: int, 2: int}|null}|null */
    public function resolve(int $probeId): ?array
    {
        $fileKey = $probeId >> 16;
        $local = $probeId & 0xFFFF;

        if (! array_key_exists($fileKey, $this->files)) {
            $path = self::fileName($this->cachePath, $fileKey);
            $map = is_file($path) ? include $path : null;
            $this->files[$fileKey] = is_array($map) && isset($map['path'], $map['probes']) && is_string($map['path']) && is_array($map['probes'])
                ? ['path' => $map['path'], 'probes' => $map['probes']]
                : null;
        }
        $file = $this->files[$fileKey];
        $probe = $file['probes'][$local] ?? null;
        if ($file === null || $probe === null) {
            return null;
        }

        /** @var array{0: string, 1: int, 2: int}|null $function */
        $function = $probe['f'] ?? null;

        return [
            'path' => $file['path'],
            'line' => (int) ($probe['l'] ?? 0),
            'kind' => (string) ($probe['k'] ?? ProbeKind::BLOCK),
            'snippet' => (string) ($probe['s'] ?? ''),
            'name' => isset($probe['n']) ? (string) $probe['n'] : null,
            'function' => $function,
        ];
    }
}
