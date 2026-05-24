# AI Customer Support Workflow Automation

Production-minded learning project for building an AI-enabled customer support SaaS platform.

## Project Structure

```txt
.
├── backend/   # Laravel API backend
└── frontend/  # Vue 3 + TypeScript frontend
```

## Tech Stack

- Backend: Laravel 13, PHP 8.4
- Frontend: Vue 3, TypeScript, Vite, Tailwind CSS
- Database: PostgreSQL
- Cache/Queue: Redis
- Realtime: Laravel Reverb, Laravel Echo, WebSockets

## Quick Start

### 1. Start PostgreSQL and Redis

```bash
docker compose up -d
```

### 2. Backend setup

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
php artisan reverb:start
```

Backend API runs at `http://127.0.0.1:8000` and Reverb runs at `ws://127.0.0.1:8080`.

### 3. Frontend setup

```bash
cd frontend
cp .env.example .env
npm install
npm run dev
```

Frontend will run at `http://127.0.0.1:5173`.

Alternative backend dev mode (starts API + queue + logs + Reverb):

```bash
cd backend
composer run dev
```

## Environment Defaults

Backend `.env` defaults are prepared for:

- PostgreSQL on `127.0.0.1:5432`
- Redis on `127.0.0.1:6379`
- queue driver: `redis`
- cache store: `redis`
- broadcast driver: `reverb`
- reverb websocket server on `127.0.0.1:8080`

Frontend `.env` defaults are prepared for:

- API base URL: `http://127.0.0.1:8000/api/v1`
- Reverb websocket app key/host/port/scheme

## API Response Standard

All API responses use this shape:

```json
{
  "success": true,
  "message": "Human-readable message",
  "data": {},
  "meta": {}
}
```

## Auth, Workspace, and RBAC API

- `POST /api/v1/auth/register`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout` (Sanctum token required)
- `GET /api/v1/auth/me` (Sanctum token required)
- `GET /api/v1/organizations` (Sanctum token required)
- `POST /api/v1/organizations` (Sanctum token required)
- `POST /api/v1/organizations/join` (Sanctum token required, join code)
- `GET /api/v1/organizations/{organization}` (member-only)
- `GET /api/v1/organizations/{organization}/members` (owner/admin)
- `PATCH /api/v1/organizations/{organization}/members/{member}` (owner-only)

## Core Ticket Management API

- `GET /api/v1/organizations/{organization}/tickets`
- `GET /api/v1/organizations/{organization}/tickets/{ticket}`
- `POST /api/v1/organizations/{organization}/tickets`
- `PATCH /api/v1/organizations/{organization}/tickets/{ticket}/status`
- `PATCH /api/v1/organizations/{organization}/tickets/{ticket}/priority`
- `PATCH /api/v1/organizations/{organization}/tickets/{ticket}/assign`
- `POST /api/v1/organizations/{organization}/tickets/{ticket}/notes`
- `POST /api/v1/organizations/{organization}/tickets/{ticket}/messages`

List supports filters:

- `search`
- `status`
- `priority`
- `assignee_id`
- `category`

## Customer & Conversation Management API

- `GET /api/v1/organizations/{organization}/customers`
- `GET /api/v1/organizations/{organization}/customers/{customer}`
- `PATCH /api/v1/organizations/{organization}/customers/{customer}`

Customer list filters:

- `search`
- `source_channel`
- `tag`

## Realtime Ticket Events

Broadcasted events over private channels:

- `ticket.created`
- `ticket.updated`
- `ticket.assigned`
- `ticket.message-created`
- `ticket.resolved`

Channels:

- `private-organizations.{organizationId}.tickets`
- `private-organizations.{organizationId}.tickets.{ticketId}`
- `private-users.{userId}.assignments`

Error responses:

```json
{
  "success": false,
  "message": "Error message",
  "errors": {}
}
```

## Initial Core Tables

- users
- organizations
- organization_user
- customers
- tickets
- ticket_messages
- ticket_notes

## Coding Conventions

See [CODING_CONVENTIONS.md](./CODING_CONVENTIONS.md).

## GitHub Repository Setup

```bash
git init
git add .
git commit -m "chore: initialize project foundation"
git branch -M main
git remote add origin <your-github-repo-url>
git push -u origin main
```
