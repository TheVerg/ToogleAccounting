# Docker deployment

The default Compose stack is intended for production. It runs immutable application images with compiled Vue assets and production-only Composer packages.

## Architecture

- `web`: Nginx; the only published container.
- `app`: PHP-FPM application runtime.
- `queue`: restartable Laravel queue worker.
- `scheduler`: Laravel scheduler worker.
- `mysql`: private MySQL 8.4 database with a persistent volume.
- `backup`: on-demand maintenance profile that exports the database and private attachments.

TLS must terminate in a host reverse proxy or load balancer in front of `HTTP_PORT`. Do not expose port 8080 directly to the internet.

## First deployment

Create the server-only environment file:

```bash
cp .env.production.example .env.production
php artisan key:generate --show
```

Paste the generated key into `APP_KEY`, set the real HTTPS `APP_URL`, replace both database passwords, and configure working SMTP credentials. Set a unique `ADMIN_INITIAL_PASSWORD` before the first seed; the administrator is forced to replace this temporary password at first login. Keep `.env.production` readable only by the deployment account.

Validate and build:

```bash
docker compose --env-file .env.production config --quiet
docker compose --env-file .env.production build --pull
```

Start the database first, run migrations as an explicit release operation, then start the application:

```bash
docker compose --env-file .env.production up -d mysql
docker compose --env-file .env.production run --rm app php artisan migrate --force
docker compose --env-file .env.production up -d
docker compose --env-file .env.production ps
```

Check `http://127.0.0.1:8080/up` from the server and the HTTPS `/up` URL externally.

## HTTPS

Point the domain to the server and proxy HTTPS traffic to `127.0.0.1:8080`. For example, a host-installed Caddy configuration is:

```caddyfile
accounting.example.com {
    encode zstd gzip
    reverse_proxy 127.0.0.1:8080
}
```

Keep ports 80/443 open for the TLS proxy. Restrict 8080 and never open MySQL port 3306 publicly.

## Updating

Take a backup before every deployment. Then fetch the reviewed release and run:

```bash
docker compose --env-file .env.production --profile maintenance run --rm backup
docker compose --env-file .env.production build --pull
docker compose --env-file .env.production run --rm app php artisan migrate --force
docker compose --env-file .env.production up -d --remove-orphans
docker compose --env-file .env.production exec app php artisan about --only=environment,cache,drivers
```

If migrations need zero-downtime sequencing, use Laravel maintenance mode or a blue-green deployment. Never run seeders against production unless a reviewed release explicitly requires one.

## Backups

Create a backup:

```bash
docker compose --env-file .env.production --profile maintenance run --rm backup
```

Each run creates `backups/<UTC timestamp>/` containing:

- `database.sql.gz`
- `storage-app.tar.gz` for private attachments and public storage
- `SHA256SUMS` for integrity verification

Copy backups to encrypted off-site storage. A backup left only on the application server does not protect against server or disk loss. Schedule the command daily using the host's cron/systemd timer and monitor failures. Keep at least daily, weekly, and monthly generations according to your retention obligations.

Test restoration regularly in a separate staging environment:

```bash
sha256sum -c SHA256SUMS
gunzip -c database.sql.gz | docker compose --env-file .env.production exec -T mysql mysql -u holdco -p holdco
docker compose --env-file .env.production stop app queue scheduler
tar -xzf storage-app.tar.gz -C /path/to/restored/storage/app
```

The restore example is intentionally manual because it overwrites data. Confirm the target database, credentials, and storage volume before running it.

## Logs and operations

```bash
docker compose --env-file .env.production logs -f web app queue scheduler
docker compose --env-file .env.production exec app php artisan queue:failed
docker compose --env-file .env.production restart queue scheduler
```

Container logs go to stdout/stderr. Configure Docker log rotation or ship them to your monitoring service. Monitor `/up`, container restarts, failed jobs, database disk usage, backup age, TLS expiry, and HTTP 5xx rates.

## Local development

The development override restores the Laravel and Vite development servers and binds MySQL only to localhost:

```bash
APP_ENV_FILE=.env.docker docker compose --env-file .env.docker -f docker-compose.yml -f docker-compose.dev.yml up --build app queue scheduler mysql vite
```

Open `http://localhost:8000`. Development credentials in `.env.docker` must never be reused in production.
