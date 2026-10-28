<?php

declare(strict_types=1);

namespace Watchman\Tests\Feature;

use Closure;
use Illuminate\Http\Request;

/** Test stand-in for an authentication middleware. */
final class PassThroughMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}
