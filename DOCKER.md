# Docker Setup

This project can run locally with Docker Compose. The setup starts:

- `app`: Laravel/PHP 8.3 on `http://localhost:8000`
- `vite`: Vite dev server on `http://localhost:5173`
- `mysql`: MySQL 8.4 on local port `3306`
- `queue`: Laravel queue listener

## Start The App

```bash
docker compose up --build
```

Open:

```text
http://localhost:8000
```

The app container will install missing Composer dependencies, generate an app key when needed, and run migrations automatically.

## Useful Commands

Run Artisan:

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
docker compose exec app php artisan test
```

Run Composer:

```bash
docker compose exec app composer install
```

Run NPM:

```bash
docker compose exec vite npm run build
```

Stop containers:

```bash
docker compose down
```

Remove containers and local Docker database/storage volumes:

```bash
docker compose down -v
```

## Environment

Docker uses `.env.docker`. The default database credentials are only for local development:

```text
DB_HOST=mysql
DB_DATABASE=holdco
DB_USERNAME=holdco
DB_PASSWORD=holdco
```
