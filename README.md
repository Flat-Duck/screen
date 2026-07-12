# Screenshut Telemetry Backend

**Screenshut Telemetry** is a comprehensive analytics and telemetry platform for the **Screenshut** application, built with Laravel and MySQL.

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

- **Core Framework**: [Laravel](https://laravel.com/) 12.x
- **Database**: [MySQL](https://www.mysql.com/) 8.0+
- **Authentication**: Laravel Sanctum
- **WebSockets**: Laravel Echo (Pusher)
- **Queue System**: Laravel Queues (Database/Redis)
- **Testing**: Pest, Laravel Dusk
- **Development Tools**: Laravel Sail (Docker), LaraLens

## 📁 Project Structure

```
screenshut-telemetry/
├── app/
│   ├── Http/Controllers/  # All API controllers (V1, V2, Admin)
│   ├── Models/              # Eloquent models
│   ├── Services/            # Business logic and services
│   └── Events/              # Event classes for notifications
├── database/
│   ├── migrations/          # Database schema migrations
│   ├── seeders/             # Seeders for test data (PostSeeder, etc.)
│   └── factories/           # Model factories for testing
├── routes/
│   ├── api.php              # V1 API routes
│   ├── api_v2.php           # V2 API routes
│   └── admin.php            # Admin panel routes
├── storage/                 # Application storage (logs, cache)
├── tests/
│   ├── Feature/             # Feature tests
│   ├── Browser/             # Browser tests (Pest/Dusk)
│   └── Unit/                # Unit tests
└── vendor/                  # Composer dependencies
```

## 🔌 API Endpoints

The backend exposes two major API versions plus an admin panel.

### V1 API (Core)

Primary API for client applications.

- `POST /api/v1/auth/register` - User registration
- `POST /api/v1/auth/login` - User login
- `GET /api/v1/feed` - Get personalized feed
- `POST /api/v1/posts` - Create a new post
- `POST /api/v1/posts/{id}/like` - Like a post

### V2 API (Advanced Features)

Enhanced API with additional features.

- `POST /api/v2/telemetry/report` - Submit telemetry data
- `GET /api/v2/client/stats` - Get client statistics
- `POST /api/v2/account/upgrade` - Handle subscription upgrades
- `POST /api/v2/social/google` - Social login handler

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
- [MySQL](https://www.mysql.com/) 8.0+
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

## 🔐 Security

- Sanctum handles API authentication
- Password reset via email
- Two-factor authentication support
- Rate limiting on all sensitive endpoints
- CSRF protection for browser requests

## 📈 Telemetry Data Flow

1. **Client**: Screenshut desktop/mobile app collects usage data
2. **Ingestion**: Client sends data to `/api/v2/telemetry/report`
3. **Processing**: Queued job processes and stores data in `telemetry_events` table
4. **Aggregation**: Hourly jobs aggregate data into `daily_stats`, `monthly_stats`, etc.
5. **Ranking**: Feed ranking algorithm scores posts based on engagement and recency
6. **Delivery**: Clients fetch personalized feeds from `/api/v1/feed`

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
