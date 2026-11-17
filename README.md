<<<<<<< HEAD
# oms
=======
# StoreFeeder OMS

Multi-tenant order management at the project root (flat layout — no `/public` folder).

## Layout

```text
index.php         # front controller
assets/           # CSS / JS
bootstrap/        # app boot + routes
config/           # app + database
src/              # PHP application
resources/        # views + nav
storage/          # migrations, runtime
bin/              # migrate / seed / clean_data
```

## Local (WAMP)

- URL: `http://localhost/storefeeder/login`
- DB: edit `config/database.php` (default DB name `storefeeder`)

```bash
php bin/migrate.php
php bin/seed_admin.php
```

Login after seed: `admin@storefeeder.local` / `admin123`

## IONOS

1. Point the subdomain document root at this folder (not a `public` subfolder).
2. Ensure `.htaccess` is uploaded (`FallbackResource /index.php`).
3. Set DB credentials in `config/database.php`.

## Useful commands

```bash
php bin/clean_data.php          # wipe business data, keep schema + admin
php bin/seed_admin.php          # reset admin user
php bin/seed_all_integrations.php
php bin/sync.php                # due order and message sync (--force, --tenant=ID)
```
>>>>>>> 5738c38 (.)
