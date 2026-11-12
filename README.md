# SweetSen Core

Senior citizen services platform for LGUs: beneficiary registry, programs, applications, QR credentials, release desk, sponsors and reporting. WordPress plugin by Spindiv Kinetics.

## Layout

- `sweetsen-core.php` — plugin bootstrap (autoloader, boot hook, WP-CLI command).
- `sweetsen-core/src/` — code by area: `Domain`, `Http` (REST `sweetsen/v1`), `Portal` (front-end console), `Roles`, `Tenancy`, `Support`, `Seed`, `Cli`.
- `sweetsen-core/migrations/` — numbered database migrations.

## Install

Currently runs as a Novamira sandbox build (`wp-content/novamira-sandbox/`). For a normal install, place this folder in `wp-content/plugins/` (or load it from `mu-plugins`).

Private files (uploads, Google sign-in config) live outside the web root in `../sweetsen-private/` and are never committed.

## Checks

```
wp sweetsen selftest
```
