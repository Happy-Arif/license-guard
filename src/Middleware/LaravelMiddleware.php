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
        $domain = preg_replace('/^www\./', '', $rawDomain);
        
        if (filter_var($rawDomain, FILTER_VALIDATE_IP)) {
            abort(403, 'Direct IP access is not allowed.');
        }
        
        $guard = new LicenseGuard(storage_path('app/.license_secure.json'));

        if ($guard->isLocalEnvironment($domain)) {
            return $next($request);
        }

        if ($request->has('token')) {
            $guard->saveActivation($request->token);
            
            return redirect()->to($request->url());
        }

        if (!$guard->isActivated() || !$guard->getToken()) {
            $callbackUrl = urlencode(url('/')); 
            return redirect()->away("https://app.happyarif.com/license-activation?domain={$domain}&callback={$callbackUrl}");
        }

        $isValid = Cache::remember('license_validity_' . $domain, 86400, function () use ($guard, $domain) {
            return $guard->validateWithServer($domain, $guard->getToken());
        });

        if (!$isValid) {
            Cache::forget('license_validity_' . $domain);
            
            if (!filter_var($rawDomain, FILTER_VALIDATE_IP)) {
                Cache::forget('is_activated');
                Cache::forget('license_token');
                $guard->clearActivation();
            }
            
            $callbackUrl = urlencode(url('/'));
            return redirect()->away("https://app.happyarif.com/license-activation?domain={$domain}&callback={$callbackUrl}");
        }

        return $next($request);
    }
}
