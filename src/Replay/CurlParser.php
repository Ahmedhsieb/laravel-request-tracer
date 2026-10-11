<?php

declare(strict_types=1);

namespace RequestTracer\Replay;

/**
 * Reads a cURL command ("Copy as cURL (bash)" in a browser's network tab, Postman, a bug report) into a ReplayRequest.
 * It understands the options that describe a request (method, URL, headers, data, cookies, basic auth) and skips the rest.
 */
final class CurlParser
{
    /** Options that are followed by a value we do not use, so the value is not mistaken for the URL. */
    private const SKIP_WITH_VALUE = [
        '-o', '--output', '-m', '--max-time', '--connect-timeout', '-x', '--proxy', '-w', '--write-out', '--retry', '--cacert',
        '--cert', '--key', '-K', '--config', '-T', '--upload-file', '--resolve', '--interface', '-F', '--form', '--http-version',
    ];

    /** Headers a browser adds that mean nothing to the application. */
    private const DROP_HEADERS = [
        'host', 'content-length', 'accept-encoding', 'connection', 'priority', 'upgrade-insecure-requests', 'te',
    ];

    public static function parse(string $command): ReplayRequest
    {
        $tokens = self::tokenize($command);
        if ($tokens === [] || ! preg_match('~(^|/)curl(\.exe)?$~i', $tokens[0])) {
            throw new \InvalidArgumentException('That does not look like a cURL command (it should start with "curl").');
        }
        array_shift($tokens);

        $method = null;
        $url = null;
        $headers = [];
        $data = [];
        $head = false;

        $reader = new class($tokens)
        {
            public int $i = 0;

            /** The part after "=" in `--header=...`, until it has been taken. */
            public ?string $inline = null;

            /** @param  list<string>  $tokens */
            public function __construct(private readonly array $tokens) {}

            public function token(): ?string
            {
                return $this->tokens[$this->i] ?? null;
            }

            /** The value of the current option: the inline one, or the next word. */
            public function value(): string
            {
                if ($this->inline !== null) {
                    $taken = $this->inline;
                    $this->inline = null;

                    return $taken;
                }

                return $this->tokens[++$this->i] ?? '';
            }
        };

        while (($token = $reader->token()) !== null) {
            $reader->inline = null;
            if (str_starts_with($token, '--') && str_contains($token, '=')) {
                [$token, $reader->inline] = explode('=', $token, 2);
            }

            switch ($token) {
                case '-X': case '--request':
                    $method = strtoupper($reader->value());
                    break;
                case '-H': case '--header':
                    [$name, $headerValue] = array_pad(explode(':', $reader->value(), 2), 2, '');
                    if (trim($name) !== '') {
                        $headers[trim($name)] = trim($headerValue);
                    }
                    break;
                case '-d': case '--data': case '--data-raw': case '--data-binary': case '--data-ascii': case '--data-urlencode':
                    $data[] = $reader->value();
                    break;
                case '-b': case '--cookie':
                    $headers['Cookie'] = trim(($headers['Cookie'] ?? '').'; '.$reader->value(), '; ');
                    break;
                case '-u': case '--user':
                    $headers['Authorization'] = 'Basic '.base64_encode($reader->value());
                    break;
                case '-A': case '--user-agent':
                    $headers['User-Agent'] = $reader->value();
                    break;
                case '-e': case '--referer':
                    $headers['Referer'] = $reader->value();
                    break;
                case '-I': case '--head':
                    $head = true;
                    break;
                case '--url':
                    $url = $reader->value();
                    break;
                default:
                    if (in_array($token, self::SKIP_WITH_VALUE, true)) {
                        $reader->value();
                    } elseif (! str_starts_with($token, '-') && $url === null) {
                        $url = $token;
                    }
            }
            $reader->i++;
        }

        if ($url === null || $url === '') {
            throw new \InvalidArgumentException('The cURL command has no URL.');
        }

        $clean = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if (in_array($lower, self::DROP_HEADERS, true) || str_starts_with($lower, 'sec-')) {
                continue;
            }
            $clean[$name] = $value;
        }

        $body = $data === [] ? null : implode('&', $data);
        $method ??= $head ? 'HEAD' : ($body !== null ? 'POST' : 'GET');

        return new ReplayRequest($method, $url, $clean, $body);
    }

    /**
     * Split like a POSIX shell: single quotes are literal, double quotes allow backslash escapes, `$'...'` understands
     * `\n \t \\ \' \xHH \uHHHH`, and a backslash before a line break joins the lines.
     *
     * @return list<string>
     */
    public static function tokenize(string $command): array
    {
        $command = preg_replace('~\\\\\r?\n~', ' ', $command) ?? $command;
        $tokens = [];
        $current = '';
        $started = false;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($char === "'") {
                $end = strpos($command, "'", $i + 1);
                $end = $end === false ? $length : $end;
                $current .= substr($command, $i + 1, $end - $i - 1);
                $i = $end;
                $started = true;
            } elseif ($char === '$' && ($command[$i + 1] ?? '') === "'") {
                [$text, $i] = self::ansiC($command, $i + 2);
                $current .= $text;
                $started = true;
            } elseif ($char === '"') {
                for ($i++; $i < $length && $command[$i] !== '"'; $i++) {
                    if ($command[$i] === '\\' && in_array($command[$i + 1] ?? '', ['"', '\\', '$', '`'], true)) {
                        $i++;
                    }
                    $current .= $command[$i];
                }
                $started = true;
            } elseif ($char === '\\' && $i + 1 < $length) {
                $current .= $command[++$i];
                $started = true;
            } elseif (ctype_space($char)) {
                if ($started) {
                    $tokens[] = $current;
                    $current = '';
                    $started = false;
                }
            } else {
                $current .= $char;
                $started = true;
            }
        }
        if ($started) {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /** @return array{0: string, 1: int} the text and the index of the closing quote */
    private static function ansiC(string $command, int $i): array
    {
        $out = '';
        $length = strlen($command);
        for (; $i < $length && $command[$i] !== "'"; $i++) {
            if ($command[$i] !== '\\') {
                $out .= $command[$i];

                continue;
            }
            $escape = $command[++$i] ?? '';
            switch ($escape) {
                case 'n': $out .= "\n";
                    break;
                case 't': $out .= "\t";
                    break;
                case 'r': $out .= "\r";
                    break;
                case 'x':
                    $hex = substr($command, $i + 1, 2);
                    $out .= chr((int) hexdec($hex));
                    $i += strlen($hex);
                    break;
                case 'u':
                    $hex = substr($command, $i + 1, 4);
                    $out .= (string) mb_chr((int) hexdec($hex), 'UTF-8');
                    $i += strlen($hex);
                    break;
                default: $out .= $escape;
            }
        }

        return [$out, $i];
    }
}
