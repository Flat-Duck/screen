# Screenshut Telemetry Backend

**Screenshut Telemetry** is a telemetry and social API for the **Screenshut** application, built with Laravel and PostgreSQL.

## 🚀 Overview

The backend serves as the central hub for collecting, processing, and serving analytics data from Screenshut desktop and mobile clients. It handles user authentication, telemetry ingestion, feed generation, and real-time notifications.

### Key Features

- **User Management**: Secure authentication using Laravel Sanctum (email/password + social login).
- **Telemetry Ingestion**: Scalable endpoint for receiving analytics events from clients.
- **Feed Generation**: Algorithm-based feed ordering with ranking and decay factors.
- **Real-time Notifications**: WebSocket integration for instant notifications.
- **Social Features**: Follow/unfollow system, likes, comments, and reposts.
- **Content Moderation**: Integrated system for managing user-generated content.
- **Admin Dashboard**: Dedicated interface for administrators to monitor the platform.

## 🛠️ Tech Stack

- **Core Framework**: [Laravel](https://laravel.com/) 13.x
- **Database**: [PostgreSQL](https://www.postgresql.org/) 17+
- **Authentication**: Laravel Sanctum
- **WebSockets**: Laravel Echo (Pusher)
- **Queue System**: Laravel Queues (Database/Redis)
- **Testing**: Pest/PHPUnit, Larastan, and Pint
- **Development Tools**: Laravel Sail (Docker), LaraLens

## 📁 Project Structure

```
screenshut-telemetry/
├── app/
│   ├── Http/Controllers/  # API and admin controllers
│   ├── Models/              # Eloquent models
│   ├── Services/            # Business logic and services
│   └── Events/              # Event classes for notifications
├── database/
│   ├── migrations/          # Database schema migrations
│   ├── seeders/             # Seeders for test data (PostSeeder, etc.)
│   └── factories/           # Model factories for testing
├── routes/
│   ├── api.php              # API entrypoint
│   └── api_v1.php           # Canonical mobile API routes
├── storage/                 # Application storage (logs, cache)
├── tests/
│   ├── Feature/             # Feature tests
│   ├── Browser/             # Browser tests (Pest/Dusk)
│   └── Unit/                # Unit tests
└── vendor/                  # Composer dependencies
```

## 🔌 API Endpoints

The backend exposes one canonical `/api/v1` mobile API plus an admin dashboard.

### V1 API (Core)

Primary API for client applications.

- `POST /api/v1/auth/register` - User registration
- `POST /api/v1/auth/login` - User login
- `POST /api/v1/devices/enroll` - Installation enrollment and Device credential
- `POST /api/v1/telemetry/events` - Device-authenticated telemetry ingestion
- `PUT /api/v1/devices/push-token` - Set the installation's FCM token
- `GET /api/v1/feed` - Get personalized feed
- `POST /api/v1/posts` - Create a new post
- `POST /api/v1/posts/{id}/like` - Like a post

### Admin Panel

Secure admin interface at `/admin`.

- User management
- Post moderation
- Analytics overview
- Settings configuration

## ⚙️ Installation & Setup

### Prerequisites

- [PHP](https://www.php.net/) 8.2+
- [Composer](https://getcomposer.org/)
- [PostgreSQL](https://www.postgresql.org/) 17+
- [Node.js](https://nodejs.org/) (for frontend assets if applicable)
- [Docker](https://www.docker.com/) (optional, for Sail)

### Quick Start (Using Laravel Sail)

1. Clone the repository:
   ```bash
   git clone <repository-url>
   cd screenshut-telemetry
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Copy environment file:
   ```bash
   cp .env.example .env
   ```

4. Configure environment variables in `.env`:
   ```ini
   APP_NAME="Screenshut Telemetry"
   APP_ENV=local
   APP_DEBUG=true
   APP_URL=http://telm.test

   DB_CONNECTION=pgsql
   DB_HOST=database
   DB_PORT=5432
   DB_DATABASE=screenshut_telemetry
   DB_USERNAME=screenshut
   DB_PASSWORD=secret

   # Redis configuration
   REDIS_HOST=redis
   REDIS_PORT=6379
   REDIS_PASSWORD=null
   REDIS_CLIENT=phpredis
   ```

5. Start the application with Sail:
   ```bash
   ./vendor/bin/sail up -d
   ```

6. Run database migrations:
   ```bash
   ./vendor/bin/sail artisan migrate
   ```

7. (Optional) Seed test data:
   ```bash
   ./vendor/bin/sail artisan db:seed --class=PostSeeder
   ```

## 🧪 Testing

The project uses Pest for testing.

- Run all tests:
  ```bash
  ./vendor/bin/pest
  ```

- Run browser tests:
  ```bash
  ./vendor/bin/pest --browser
  ```

## Production Workers and Scheduler

Copy `.env.production.example` to the server as `.env` before any of this — it documents
every required value and repeats the process list below.

**PHP 8.4.1+ is required** (`composer.json` is `^8.4`; `symfony/filesystem` needs 8.4.1).
A bare `php` on the host may still be 8.2/8.3, so pin the absolute path in every cron and
supervisor unit.

Run the scheduler once per minute, with Laravel's single-server locks backed by a shared
cache — 16 scheduled tasks depend on it:

```bash
* * * * * /usr/bin/php8.4 /path/to/artisan schedule:run >> /dev/null 2>&1
```

Queues are managed by **Horizon**, not by raw `queue:work`. `QUEUE_CONNECTION=redis` and
`config/horizon.php` defines three supervisors (`supervisor-default`, `supervisor-security`,
`supervisor-media`) mapping 1:1 to the queues jobs declare via `onQueue()`, so security
notifications are never delayed behind image processing. Run one process:

```bash
/usr/bin/php8.4 /path/to/artisan horizon
```

Do **not** also run `queue:work` — it would double-consume the same queues alongside Horizon.

Pulse needs two more daemons in production (see Monitoring below):

```bash
/usr/bin/php8.4 /path/to/artisan pulse:work    # drains the Redis ingest stream
/usr/bin/php8.4 /path/to/artisan pulse:check   # per-server CPU/memory/storage stats
```

On deploy, restart the daemons so they pick up new code:

```bash
php artisan horizon:terminate && php artisan pulse:restart
```

`composer dev` boots Horizon locally and consumes `security,media,default` in priority order.
Security email delivery uses a transactional outbox recovered every minute. Abandoned post
staging directories are retained in a cleanup ledger and retried every ten minutes.

## 📈 Monitoring

Two surfaces with a deliberate production/dev split:

| | Pulse | Telescope |
|---|---|---|
| Environment | **production** (and local) | **local/testing only** |
| Installed as | `require` | `require-dev`, `dont-discover` |
| Path | `/pulse` | `/telescope` |
| Gate | `viewPulse` (admin-only) | `viewTelescope` (admin-only) |
| Captures | aggregates: slow queries/requests/jobs, exceptions, queue depth, per-user activity | full request/response bodies and query bindings, every request |

Telescope is registered only by `AppServiceProvider::registerTelescope()`, which returns early
outside `local`/`testing` and is additionally guarded by `class_exists()` so a production
`composer install --no-dev` is a no-op rather than a fatal. `tests/Feature/MonitoringAccessTest.php`
locks both halves of that boundary.

Pulse uses the Redis ingest in production (`PULSE_INGEST_DRIVER=redis`), keeping request
handling off the write path — requests push to a Redis stream and `pulse:work` drains it into
PostgreSQL. Locally it defaults to the `storage` driver, so no daemon is needed. Pulse trims
itself on ingest per `PULSE_STORAGE_KEEP`; it needs no scheduled prune.

Horizon (`/horizon`, `viewHorizon`) covers queue throughput and failed jobs.

All three dashboards share the same admin-only boundary as `viewTelemetry`, because `User` is
*also* the mobile API's end-user principal — without the gates, any registered app user could
browse them by logging into the web dashboard.

## 🔐 Security
- Sanctum handles API authentication
- Password reset via email
- Two-factor authentication support
- Rate limiting on all sensitive endpoints
- CSRF protection for browser requests

## 📈 Telemetry Data Flow

1. **Client**: Screenshut desktop/mobile app collects usage data
2. **Enrollment**: Installation obtains a restricted Device credential from `/api/v1/devices/enroll`
3. **Attribution**: User authentication creates a durable `DeviceSession`
4. **Ingestion**: Device submits bounded and redacted batches to `/api/v1/telemetry/events`
5. **Correlation**: Valid session UUIDs snapshot user, session, and release attribution
6. **Retention**: Scheduled pruning removes telemetry older than 90 days

## 👥 Contributing

1. Create a feature branch: `git checkout -b feature/new-feature`
2. Make your changes
3. Ensure code follows Laravel coding standards
4. Submit a pull request

## 📚 Documentation

- [API Documentation](docs/API_DOCUMENTATION.md) - Detailed API endpoint specifications
- [Database Schema](docs/DATABASE_SCHEMA.md) - Database table structure
- [Architecture](docs/ARCHITECTURE.md) - System architecture overview
- [Development Guide](docs/DEVELOPMENT_GUIDE.md) - Setup and development instructions
- [Social Login](docs/BACKEND_SOCIAL_LOGIN.md) - Social authentication details

## 📄 License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
