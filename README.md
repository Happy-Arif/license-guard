# HappyArif License Guard

License verification for Laravel, native PHP and WordPress-style PHP integrations.

## Laravel: database-backed activations

**New in this version:** Laravel installs save the activation record to their application database, not `storage/app/.happyarif_license.json`, once the package migration has run. Native PHP users keep the legacy JSON file behavior. There is no extra Laravel library to install.

### Upgrade an existing Laravel installation

1. Back up the database and, if it exists, `storage/app/.happyarif_license.json` **before upgrading**. Do **not** remove the existing license file yet.
2. Upgrade this package (`composer update happyarif/license-guard` if it is in your Composer requirements). If you maintain the package in a private Git repository, publish the new package version first.
3. Run Laravel's package migrations:

   ```bash
   php artisan migrate --force
   php artisan optimize:clear
   ```

4. Open the application once. If `happyarif_licenses` has no row yet, the guard copies the existing activated license from the JSON file into the database **automatically**. The token is encrypted using Laravel's `APP_KEY`.
5. Confirm the row exists in `happyarif_licenses` (row `id = 1`) and the application opens normally. Only after confirming a backup is available should you consider deleting the old JSON file; it is not required for normal operation.

**Important:** Keep `APP_KEY` stable and backed up. The encrypted DB token cannot be decrypted with a different application key. Back up the database across deployments, and use persistent database storage (not a temporary/container-local SQLite file) if the server filesystem is ephemeral.

On a fresh Laravel install, migrate first, then activate once. Until the migration is available, the guard uses the original JSON file, so upgrading can be done without interrupting currently activated installs.

### How verification works

- `LicenseGuard::saveActivation()`, `isActivated()`, `getToken()`, and `clearActivation()` use the DB on migrated Laravel apps.
- The central API still verifies license validity; **this does not remove licensing checks**.
- A `?token=` activation callback is only saved after the central server explicitly confirms `"valid": true`. If the central server is temporarily unreachable, an activation attempt returns HTTP 503 instead of replacing an existing token.
- Successful server checks are cached for 24 hours. Explicit invalid responses are cached for 5 minutes and show the activation page, **but do not delete the stored license**. Network failures or malformed server responses are also cached for 5 minutes; an already activated installation remains usable while the verification service is unavailable.
- If a license is confirmed invalid, the app remains blocked until it is valid again or a valid token is activated. Persistent storage is **not** a bypass of an invalid/revoked license.
- `clearActivation()` is reserved for an explicit deactivation/reset; it removes the DB record and any legacy file.

### Database structure

Automatic package migration: `database/migrations/2026_01_01_000000_create_happyarif_licenses_table.php`

Table: `happyarif_licenses`

| Column | Purpose |
| --- | --- |
| `id` | Single-installation record, always `1` |
| `license_token` | Encrypted token (Laravel `APP_KEY`) |
| `is_activated` | Whether an activation is stored |
| `activated_at` | Local activation timestamp |
| `last_verified_at` | Last successful central-server check |
| `created_at`, `updated_at` | Timestamps |

This package stores **one license per Laravel application/database**, as with the original one-file approach. If multiple distinct applications share the same DB and table namespace, they need separate databases or an application-specific design to avoid sharing the same activation.

### Troubleshooting

- **Still redirects after upgrade?** Make sure `php artisan migrate --status` shows the package migration as run and check whether the database table contains row `id = 1`. If the original file was lost before migration, the token cannot be reconstructed and must be recovered/re-activated via the license provider.
- **500 error related to DB?** Verify `.env` DB settings and permissions. A database outage is surfaced as an application error, not misdiagnosed as a missing license.
- **Decryption error?** Restore the original `APP_KEY` and database backup; changing `APP_KEY` makes old encrypted tokens unreadable.
- **Application goes to activation even with a DB record?** The central API may be explicitly rejecting the license. Verify its response/status, active domain, and token. The DB token is intentionally not deleted automatically.

## Installation (Laravel)

Add a VCS repository in your Laravel application's `composer.json` if required:

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/Happy-Arif/license-guard.git" }
]
```

Then run:

```bash
composer require happyarif/license-guard
php artisan migrate --force
```

Laravel auto-discovers `HappyArif\LicenseGuard\\LicenseGuardServiceProvider`. The provider registers the middleware and package migration.

## Native PHP / WordPress integrations

`new HappyArif\LicenseGuard\\LicenseGuard($customStoragePath)` remains file-backed when running outside a bootstrapped Laravel application, with the same public activation methods. JSON read/write failures are no longer suppressed.
