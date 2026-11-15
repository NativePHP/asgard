<?php

namespace Workbench\App;

use Closure;
use Illuminate\Http\Request;

/** Refuses the bearer token conformance-reject with 401, as FIXTURES.md asks. */
class RejectBearer
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->header('Authorization') === 'Bearer conformance-reject') {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        return $next($request);
    }
}
