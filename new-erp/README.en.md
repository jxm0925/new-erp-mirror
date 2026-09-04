# Jiantan ERP Rebuild

This repository contains the current ERP rebuild source code.

- `backend/`: Laravel API, migrations, application services, and acceptance documentation.
- `frontend/`: Vue management application.

Install dependencies in each directory. Copy `backend/.env.example` to `backend/.env`, configure MySQL, then run `php artisan migrate --force`. Run `composer reverb` alongside the API to enable real-time WebSocket notifications on port 8083.

Never commit `.env`, dependency directories, logs, or local build output. Database schema changes must be delivered through Laravel migrations only.
