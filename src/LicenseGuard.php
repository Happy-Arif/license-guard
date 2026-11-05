<?php

namespace HappyArif\LicenseGuard;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class LicenseGuard
{
    private const TABLE = 'happyarif_licenses';

    protected string $storagePath;
    protected string $centralServer = 'https://app.happyarif.com';
    private ?bool $useDatabase = null;
    private bool $activationLoaded = false;
    private ?array $activationData = null;

    public function __construct(?string $customStoragePath = null)
    {
        if ($customStoragePath) {
            $this->storagePath = $customStoragePath;
        } elseif (function_exists('storage_path')) {
            $this->storagePath = storage_path('app/.happyarif_license.json');
        } else {
            $this->storagePath = __DIR__ . '/.happyarif_license.json';
        }
    }

    public function isLocalEnvironment(string $domain): bool
    {
        $locals = ['localhost', '127.0.0.1', '::1'];

        return in_array($domain, $locals, true)
            || str_ends_with($domain, '.test')
            || str_ends_with($domain, '.local');
    }

    /**
     * On Laravel, the database is the source of truth after the migration exists.
     * Prior to migration (and outside Laravel), retain the original file behavior.
     */
    private function usesDatabase(): bool
    {
        if ($this->useDatabase !== null) {
            return $this->useDatabase;
        }

        if (!function_exists('app') || !class_exists(DB::class) || !class_exists(Schema::class)) {
            return $this->useDatabase = false;
        }

        if (!app()->bound('db')) {
            return $this->useDatabase = false;
        }

        // Deliberately do not suppress database exceptions: a database outage
        // must not be mistaken for a missing license and trigger activation.
        return $this->useDatabase = Schema::hasTable(self::TABLE);
    }

    private function readFileActivation(): ?array
    {
        if (!is_file($this->storagePath)) {
            return null;
        }

        $content = file_get_contents($this->storagePath);
        if ($content === false) {
            throw new RuntimeException('Unable to read the local license file.');
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    private function saveDatabaseActivation(string $token, ?string $activatedAt = null): void
    {
        $now = date('Y-m-d H:i:s');

        // Upsert initializes created_at on first save but preserves it on updates.
        DB::table(self::TABLE)->upsert(
            [[
                'id' => 1,
                'license_token' => Crypt::encryptString($token),
                'is_activated' => true,
                'activated_at' => $activatedAt ?: $now,
                'last_verified_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['id'],
            ['license_token', 'is_activated', 'activated_at', 'last_verified_at', 'updated_at']
        );
    }

    /** Import a valid-looking legacy file once, without deleting the backup. */
    private function importLegacyActivation(): void
    {
        $legacy = $this->readFileActivation();
        if (empty($legacy['is_activated']) || !is_string($legacy['license_token'] ?? null)
            || $legacy['license_token'] === '') {
            return;
        }

        $this->saveDatabaseActivation(
            $legacy['license_token'],
            is_string($legacy['activated_at'] ?? null) ? $legacy['activated_at'] : null
        );
    }

    private function readActivation(): ?array
    {
        if ($this->activationLoaded) {
            return $this->activationData;
        }

        if (!$this->usesDatabase()) {
            $this->activationData = $this->readFileActivation();
            $this->activationLoaded = true;
            return $this->activationData;
        }

        $row = DB::table(self::TABLE)->where('id', 1)->first();
        if ($row === null) {
            $this->importLegacyActivation();
            $row = DB::table(self::TABLE)->where('id', 1)->first();
        }

        if ($row === null) {
            $this->activationLoaded = true;
            return $this->activationData = null;
        }

        $this->activationData = [
            'is_activated' => (bool) $row->is_activated,
            'license_token' => Crypt::decryptString($row->license_token),
            'activated_at' => $row->activated_at,
        ];
        $this->activationLoaded = true;

        return $this->activationData;
    }

    public function saveActivation(string $token): void
    {
        if ($token === '') {
            throw new RuntimeException('The license token must not be empty.');
        }

        if ($this->usesDatabase()) {
            $this->saveDatabaseActivation($token);
        } else {
            $data = [
                'is_activated' => true,
                'license_token' => $token,
                'activated_at' => date('Y-m-d H:i:s'),
            ];
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            if (file_put_contents($this->storagePath, $json, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the local license file.');
            }
        }

        $this->activationLoaded = false;
        $this->activationData = null;
    }

    public function isActivated(): bool
    {
        return (bool) ($this->readActivation()['is_activated'] ?? false);
    }

    public function getToken(): ?string
    {
        $token = $this->readActivation()['license_token'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    /** Only call when a user explicitly removes / resets their activation. */
    public function clearActivation(): void
    {
        if ($this->usesDatabase()) {
            DB::table(self::TABLE)->where('id', 1)->delete();
        }

        // Also erase the legacy file on an *explicit* reset. Otherwise the
        // automatic migration could silently restore a license the owner cleared.
        if (is_file($this->storagePath) && !unlink($this->storagePath)) {
            throw new RuntimeException('Unable to remove the local license file.');
        }

        $this->activationLoaded = false;
        $this->activationData = null;
    }

    /** Last known successful remote verification is stored for diagnostics. */
    public function markVerified(): void
    {
        if ($this->usesDatabase()) {
            DB::table(self::TABLE)->where('id', 1)->update([
                'last_verified_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Returns valid, invalid, or unavailable. Only a response that explicitly
     * rejects the token is considered invalid; timeouts and malformed server
     * responses must not erase a client's saved activation.
     */
    public function verificationStatus(string $domain, string $token): string
    {
        if (!function_exists('curl_init')) {
            return 'unavailable';
        }

        try {
            $ch = curl_init("{$this->centralServer}/api/verify-token");
            if ($ch === false) {
                return 'unavailable';
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'domain_name' => $domain,
                    'token' => $token,
                ], JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_USERAGENT => 'HappyArif-LicenseGuard/1.1',
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                return 'unavailable';
            }

            // A proxy, rate limiter or server error is not proof of invalidity.
            if (!in_array($httpCode, [200, 401, 403, 422], true)) {
                return 'unavailable';
            }

            $result = json_decode($response, true);
            if (!is_array($result)) {
                return 'unavailable';
            }

            if (($result['valid'] ?? null) === true) {
                return 'valid';
            }
            if (($result['valid'] ?? null) === false) {
                return 'invalid';
            }

            return 'unavailable';
        } catch (\Throwable $e) {
            return 'unavailable';
        }
    }

    /** Backwards-compatible bool API (old code allowed existing activation on outages). */
    public function validateWithServer(string $domain, string $token): bool
    {
        return $this->verificationStatus($domain, $token) !== 'invalid';
    }
}
