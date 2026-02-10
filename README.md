# MicroTasker (Complete Runnable System)

MicroTasker is a daily work tracker for small teams.

## Stack used in this implementation
- Backend/UI runtime: PHP 8.2+ (no external package installs required)
- Database: SQLite (`storage/microtasker.sqlite`)
- Frontend: server-rendered HTML with mobile-first UX and bottom nav
- Security: password hashing, CSRF token checks, team-level authorization guards

> You asked for a fully completed system and allowed any framework. This repository now includes a complete, runnable app with all MVP features.

## Features implemented
- Authentication: register/login/logout
- Teams:
  - create team
  - role-based membership (`admin`, `member`)
  - invite link generation (optionally email-bound)
  - invite acceptance
  - team switching
- Today Board:
  - tasks with fields: title, description, assigned user, status, priority, due date, completed_at, created_by
  - grouped by status (`todo`, `doing`, `done`)
  - filters: My Tasks, Status, Priority
  - quick one-tap status updates
  - mobile Add Task FAB + drawer-style panel
- Daily Check-in:
  - per-team daily entries (`yesterday`, `today`, `blockers`)
  - duplicate prevention per user/team/day (edit existing allowed)
  - team feed for current day
- Team Summary:
  - completed tasks today
  - blockers list
  - still in progress list
  - team health indicator (`doing count + blocker count`)
- Settings: profile display + logout from top bar
- Demo seed data auto-created on first boot

## Project structure
- `public/index.php` — front controller, routing, UI rendering
- `app/bootstrap.php` — DB connection, migrations, seeding, auth/session helpers, authorization helpers
- `tests/run.php` — automated CLI tests

## Run locally
```bash
php -S 127.0.0.1:8080 -t public
```

Open: `http://127.0.0.1:8080`

Demo account:
- `admin@demo.test` / `password`
- `member@demo.test` / `password`

## Run tests
```bash
php tests/run.php
```

## Notes
- SQLite is local default. For production, place app behind nginx/apache + PHP-FPM, secure session cookies, and move DB to managed SQLite/MySQL equivalent if desired.
- Existing `.env.example` and bootstrap helper script are retained from previous work but are not required for this self-contained implementation.
