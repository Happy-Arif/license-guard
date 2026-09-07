<?php

namespace HappyArif\LicenseGuard;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process; // এটি নতুন যুক্ত হয়েছে
use ZipArchive;
use Exception;

class AppUpdater
{
    protected LicenseGuard $guard;
    protected string $centralServer = 'https://app.happyarif.com';

    public function __construct()
    {
        $this->guard = new LicenseGuard(storage_path('app/.license_secure.json'));
    }

    public function checkUpdate(string $currentVersion)
    {
        $token = $this->guard->getToken();
        $domain = preg_replace('/^www\./', '', request()->getHost());

        if (!$token) return false;

        $response = Http::timeout(10)->post("{$this->centralServer}/api/update/check", [
            'domain_name' => $domain,
            'token' => $token,
            'current_version' => $currentVersion
        ]);

        return $response->successful() ? $response->json() : false;
    }

    public function installUpdate()
    {
        $token = $this->guard->getToken();
        $domain = preg_replace('/^www\./', '', request()->getHost());

        if (!$token) throw new Exception("No active license found.");

        // ১. জিপ ফাইল ডাউনলোড
        $response = Http::timeout(300)->post("{$this->centralServer}/api/update/download", [
            'domain_name' => $domain,
            'token' => $token
        ]);

        if (!$response->successful()) throw new Exception("Update failed. Invalid license.");

        $zipPath = storage_path('app/temp_update.zip');
        file_put_contents($zipPath, $response->body());

        // ২. ফাইল এক্সট্র্যাক্ট করা
        $zip = new ZipArchive;
        if ($zip->open($zipPath) === true) {
            $zip->extractTo(base_path());
            $zip->close();
            File::delete($zipPath);
        } else {
            throw new Exception("Failed to extract update package.");
        }

        // ৩. নতুন ডিপেনডেন্সি ইন্সটল করা (Composer)
        $process = new Process(['composer', 'install', '--no-dev', '--optimize-autoloader']);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(300); // বড় প্যাকেজ থাকলে যেন টাইমআউট না হয়
        $process->run();

        // কম্পোজার ফেইল করলে লগে এরর সেভ করে এক্সেপশন থ্রো করবে
        if (!$process->isSuccessful()) {
            Log::error('Composer Update Error: ' . $process->getErrorOutput());
            throw new Exception("Update files extracted, but failed to install new composer dependencies. Please check server logs.");
        }

        // ৪. ডাটাবেস আপডেট ও ক্যাশ ক্লিয়ার করা
        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('optimize:clear');

        return true;
    }
}
