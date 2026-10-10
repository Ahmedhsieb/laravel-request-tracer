<?php

declare(strict_types=1);

namespace RequestTracer\Console;

use Illuminate\Console\Command;
use RequestTracer\Recording\TokenService;

/** Issues a signed recording token from the command line (the dashboard can do the same). */
final class TokenCommand extends Command
{
    protected $signature = 'request-tracer:token
                            {--ttl=300 : Lifetime in seconds (max 3600)}
                            {--user= : Only record requests of this authenticated user id}
                            {--route= : Only record requests whose path matches this pattern (e.g. api/orders*)}';

    protected $description = 'Issue a signed token for the X-Request-Tracer header';

    public function handle(TokenService $tokens): int
    {
        $user = $this->option('user');
        $route = $this->option('route');
        $issued = $tokens->issue((int) $this->option('ttl'), is_string($user) && $user !== '' ? $user : null, is_string($route) && $route !== '' ? $route : null);
        $header = (string) config('request-tracer.triggers.header', 'X-Request-Tracer');

        $this->line($issued['token']);
        $this->newLine();
        $this->components->info(sprintf('Send it as the "%s" header. Valid until %s.', $header, date('Y-m-d H:i:s', $issued['expires_at'])));

        return self::SUCCESS;
    }
}
