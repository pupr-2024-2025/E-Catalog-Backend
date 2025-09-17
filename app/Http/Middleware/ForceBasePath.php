<?

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\URL;

class ForceBasePath
{
    public function handle($request, Closure $next)
    {
        URL::forceRootUrl(config('app.url'));
        return $next($request);
    }
}
