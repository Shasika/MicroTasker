# MicroTasker — Production-ready Daily Work Tracker (Self-contained PHP)

MicroTasker is a daily-focused team tracker for small teams.

## What this delivers
This repository includes a fully runnable application with:
- Secure auth flows (register/login/logout, hashed passwords, CSRF)
- Team management (create/switch teams, roles admin/member)
- Invite links (optionally email-bound)
- Today Board (status columns, filters, quick actions)
- Task detail drawer/edit flow (mobile-friendly)
- Daily check-in (yesterday/today/blockers), duplicate prevention with edit support
- Team summary (completed today, blockers, in-progress, health indicator)
- Settings (profile update + password update)
- SQLite local persistence with automatic schema bootstrap + demo seed data

## Stack
- Runtime: PHP 8.2+
- DB: SQLite (`storage/microtasker.sqlite`)
- Frontend: server-rendered with custom CSS/JS (mobile-first)

## Quick start (local)
```bash
php -S 127.0.0.1:8080 -t public
```
Open: `http://127.0.0.1:8080`

Demo users:
- `admin@demo.test` / `password`
- `member@demo.test` / `password`

## Tests
```bash
php tests/run.php
```

## Project structure
- `app/bootstrap.php` — database connection, schema bootstrap, seed data, auth/security/helpers
- `public/index.php` — routing/controller logic + page rendering
- `public/assets/app.css` — design system and responsive styles
- `public/assets/app.js` — drawer interactions for task details/create panel
- `tests/run.php` — automated data/flow checks

## Production notes
- Put behind nginx/apache + PHP-FPM.
- Ensure HTTPS, secure cookies, and regular backups for DB.
- This app is self-contained and does not require composer/npm installs.
