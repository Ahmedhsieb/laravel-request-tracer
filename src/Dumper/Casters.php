<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Dumper;

use Ahmedhsieb\RequestTracer\Support\Paths;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\VarDumper\Caster\Caster;

/**
 * Symfony casters that make Eloquent models, collections, exceptions and HTTP messages dump the way a developer expects
 * (and never leak hidden attributes or non-allowlisted headers).
 */
final class Casters
{
    /**
     * @param  array<string, mixed>  $captureConfig  the `capture` section of the config
     * @return array<string, callable>
     */
    public static function make(DumpLimits $limits, array $captureConfig = []): array
    {
        $requestHeaders = array_map(static fn ($h): string => strtolower((string) $h), array_values((array) ($captureConfig['request_headers'] ?? [])));
        $responseHeaders = array_map(static fn ($h): string => strtolower((string) $h), array_values((array) ($captureConfig['response_headers'] ?? [])));
        $bodyMax = (int) ($captureConfig['body_max_bytes'] ?? 4096);
        $maxItems = $limits->maxItems;

        $empty = static fn (): array => [];

        return [
            \Closure::class => $empty,
            \PDO::class => $empty,
            \PDOStatement::class => $empty,
            \Generator::class => $empty,
            Container::class => $empty,
            Connection::class => $empty,
            LazyCollection::class => $empty,

            Model::class => static function (Model $model) use ($maxItems): array {
                $hidden = array_flip($model->getHidden());
                $visible = array_flip($model->getVisible());
                $attributes = [];
                $count = 0;
                foreach ($model->getAttributes() as $name => $value) {
                    if (++$count > $maxItems * 4) {
                        break;
                    }
                    $attributes[$name] = (isset($hidden[$name]) || ($visible !== [] && ! isset($visible[$name]))) ? new MaskedValue : $value;
                }

                return [
                    Caster::PREFIX_VIRTUAL.'key' => $model->getKey(),
                    Caster::PREFIX_VIRTUAL.'exists' => $model->exists,
                    Caster::PREFIX_VIRTUAL.'attributes' => $attributes,
                    Caster::PREFIX_VIRTUAL.'relations' => array_keys($model->getRelations()),
                ];
            },

            Collection::class => static function (Collection $collection) use ($maxItems): array {
                $all = $collection->all();

                return [
                    Caster::PREFIX_VIRTUAL.'count' => count($all),
                    Caster::PREFIX_VIRTUAL.'items' => array_slice($all, 0, $maxItems, true),
                ];
            },

            \Throwable::class => static function (\Throwable $e): array {
                $out = [
                    Caster::PREFIX_VIRTUAL.'message' => $e->getMessage(),
                    Caster::PREFIX_VIRTUAL.'code' => $e->getCode(),
                    Caster::PREFIX_VIRTUAL.'file' => Paths::relative($e->getFile()),
                    Caster::PREFIX_VIRTUAL.'line' => $e->getLine(),
                ];
                $previous = $e->getPrevious();
                if ($previous !== null) {
                    $out[Caster::PREFIX_VIRTUAL.'previous'] = $previous::class.': '.$previous->getMessage();
                }

                return $out;
            },

            Request::class => static function (Request $request) use ($requestHeaders): array {
                try {
                    $body = $request->isJson() ? $request->json()->all() : $request->request->all();
                } catch (\Throwable) {
                    $body = [];
                }

                return [
                    Caster::PREFIX_VIRTUAL.'method' => $request->getMethod(),
                    Caster::PREFIX_VIRTUAL.'path' => '/'.ltrim($request->path(), '/'),
                    Caster::PREFIX_VIRTUAL.'query' => $request->query->all(),
                    Caster::PREFIX_VIRTUAL.'input' => $body,
                    Caster::PREFIX_VIRTUAL.'files' => array_keys($request->files->all()),
                    Caster::PREFIX_VIRTUAL.'headers' => self::allowlist($request->headers->all(), $requestHeaders),
                ];
            },

            Response::class => static function (Response $response) use ($responseHeaders, $bodyMax): array {
                $out = [
                    Caster::PREFIX_VIRTUAL.'status' => $response->getStatusCode(),
                    Caster::PREFIX_VIRTUAL.'headers' => self::allowlist($response->headers->all(), $responseHeaders),
                ];
                if ($response instanceof RedirectResponse) {
                    $out[Caster::PREFIX_VIRTUAL.'target'] = $response->getTargetUrl();
                } elseif ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
                    $out[Caster::PREFIX_VIRTUAL.'content'] = '[streamed body]';
                } elseif ($response instanceof JsonResponse) {
                    $decoded = json_decode((string) $response->getContent(), true);
                    $out[Caster::PREFIX_VIRTUAL.'data'] = json_last_error() === JSON_ERROR_NONE ? $decoded : mb_strcut((string) $response->getContent(), 0, $bodyMax);
                } else {
                    $content = (string) $response->getContent();
                    $out[Caster::PREFIX_VIRTUAL.'content'] = strlen($content) > $bodyMax ? mb_strcut($content, 0, $bodyMax, 'UTF-8') : $content;
                }

                return $out;
            },
        ];
    }

    /**
     * @param  array<string, array<int, string|null>|string>  $headers
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    public static function allowlist(array $headers, array $allowed): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $name = strtolower((string) $name);
            if (in_array($name, $allowed, true)) {
                $out[$name] = is_array($values) ? implode(', ', array_map('strval', $values)) : (string) $values;
            }
        }

        return $out;
    }
}
