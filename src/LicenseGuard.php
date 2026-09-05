<?php

namespace HappyArif\LicenseGuard;

class LicenseGuard
{
    protected string $storagePath;
    protected string $centralServer = 'https://app.happyarif.com';

    public function __construct(?string $customStoragePath = null)
    {
        $this->storagePath = $customStoragePath ?? sys_get_temp_dir() . '/.happyarif_license.json';
    }

    // ১. লোকালহোস্ট বাইপাস চেক
    public function isLocalEnvironment(string $domain): bool
    {
        $locals = ['localhost', '127.0.0.1', '::1'];
        
        if (in_array($domain, $locals) || str_ends_with($domain, '.test') || str_ends_with($domain, '.local')) {
            return true;
        }

        return false;
    }

    public function saveActivation(string $token): void
    {
        $data = [
            'is_activated' => true,
            'license_token' => $token,
            'activated_at' => date('Y-m-d H:i:s')
        ];

        @file_put_contents($this->storagePath, json_encode($data));
    }

    public function isActivated(): bool
    {
        if (!file_exists($this->storagePath)) {
            return false;
        }

        $content = @file_get_contents($this->storagePath);
        $data = json_decode($content, true);

        return $data['is_activated'] ?? false;
    }

    public function getToken(): ?string
    {
        if (!file_exists($this->storagePath)) {
            return null;
        }

        $content = @file_get_contents($this->storagePath);
        $data = json_decode($content, true);

        return $data['license_token'] ?? null;
    }

    public function clearActivation(): void
    {
        if (file_exists($this->storagePath)) {
            @unlink($this->storagePath);
        }
    }

    public function validateWithServer(string $domain, string $token): bool
    {
        try {
            $ch = curl_init("{$this->centralServer}/api/verify-token");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'domain_name' => $domain,
                'token' => $token
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                return $result['valid'] ?? false;
            }

            return false;
        } catch (\Exception $e) {
            return true;
        }
    }
}