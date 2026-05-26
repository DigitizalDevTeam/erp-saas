<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class FilterRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // DataTables sends a large read-only param set; skip expensive per-field regex.
        if ($request->is('productservice/datatable')) {
            return $next($request);
        }

        $input = $request->all();
        array_walk_recursive($input, function (&$value) {
            if (is_string($value)) {
                $value = htmlspecialchars_decode($value);
                $value = preg_replace('/<\s*script\b[^>]*>(.*?)<\s*\/\s*script\s*>/is', '', $value);
                $value = str_replace(['&lt;', '&gt;', 'javascript', 'script','alert'], '', $value);
            }
        });
        $request->merge($input);
        return $next($request);
    }
}
