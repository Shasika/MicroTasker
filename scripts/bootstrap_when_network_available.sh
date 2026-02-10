#!/usr/bin/env bash
set -euo pipefail

if ! command -v composer >/dev/null 2>&1; then
  echo "composer is required"
  exit 1
fi

composer create-project laravel/laravel . "^12.0"
composer require laravel/breeze --dev
php artisan breeze:install vue --inertia
npm install

cp .env.example .env || true
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan test
