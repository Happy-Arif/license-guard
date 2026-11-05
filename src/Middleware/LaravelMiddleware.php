<?php

namespace HappyArif\LicenseGuard\Middleware;

use Closure;
use Illuminate\Http\Request;
use HappyArif\LicenseGuard\LicenseGuard;
use Illuminate\Support\Facades\Cache;

class LaravelMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $rawDomain = $request->getHost();
        $domain = preg_replace('/^www\./i', '', $rawDomain);

        $guard = new LicenseGuard();

        // Allow 127.0.0.1 and ::1 in development before blocking public IPs.
        if ($guard->isLocalEnvironment($domain)) {
            return $next($request);
        }

        if (filter_var($rawDomain, FILTER_VALIDATE_IP)) {
            abort(403, 'Direct IP access is not allowed.');
        }

        // Activation callback: never overwrite a legitimate license with an
        // arbitrary ?token unless the central server explicitly accepts it.
        $callbackToken = $request->query('token');
        if (is_string($callbackToken) && $callbackToken !== '') {
            $status = $guard->verificationStatus($domain, $callbackToken);
            if ($status === 'valid') {
                $guard->saveActivation($callbackToken);
                return redirect()->to($request->url());
            }

            abort($status === 'unavailable' ? 503 : 422,
                'License activation could not be verified. Your previous license has not been changed.');
        }

        $token = $guard->getToken();
        if (!$guard->isActivated() || !$token) {
            return $this->redirectToActivation($domain);
        }

        // Different tokens/domains cannot accidentally share a cached result.
        $cacheKey = 'happyarif_license_status_' . hash('sha256', $domain . '|' . $token);
        $status = Cache::get($cacheKey);

        if (!in_array($status, ['valid', 'invalid', 'unavailable'], true)) {
            $status = $guard->verificationStatus($domain, $token);

            // Avoid keeping a transient failure or rejection cached for 24h.
            Cache::put($cacheKey, $status, $status === 'valid' ? 86400 : 300);
            if ($status === 'valid') {
                $guard->markVerified();
            }
        }

        if ($status === 'invalid') {
            // Do not delete persisted credentials on a remote rejection.
            // A recovered/re-enabled license will work after the short TTL.
            return $this->redirectToActivation($domain);
        }

        // Existing activated installations stay usable during API outages.
        // Fresh installations MUST still pass the callback verification above.
        return $next($request);
    }

    private function redirectToActivation(string $domain)
    {
        return redirect()->away('https://app.happyarif.com/license-activation?' . http_build_query([
            'domain' => $domain,
            'callback' => url('/'),
        ]));
    }
}
