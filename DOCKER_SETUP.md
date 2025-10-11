# Domain Monitor - Docker Setup Guide

Simple PHP Docker container with built-in web server. No reverse proxy needed.

## Quick Start

```bash
chmod +x init.sh
./init.sh
```

Done. PHP runs on port 8000, MySQL on 3306.

## File Structure

```
docker/
├── php.ini          # PHP settings
├── my.cnf           # MySQL configuration
├── entrypoint.sh    # Container startup script
└── .env.example     # Environment template
```

## Configuration

Edit `env.example.txt` before running `init.sh`:

## Access

After setup, visit: **http://localhost:8000**

Complete the web installer and save your admin credentials.

## Docker Commands

```bash
# View logs
docker-compose logs -f app

# Enter container
docker-compose exec app sh

# Stop containers
docker-compose down

# Restart
docker-compose restart

# MySQL access from host
mysql -h localhost -u domain_user -p domain_monitor
```

## Database Backup

```bash
docker-compose exec db mysqldump -u domain_user -p domain_monitor > backup.sql
```

## Database Restore

```bash
docker-compose exec -T db mysql -u domain_user -p domain_monitor < backup.sql
```

## Cron Jobs

Cron runs inside the container automatically. Edit `docker/crontab`:

```bash
# Run domain checks daily at 9 AM
0 9 * * * /usr/bin/php /var/www/html/cron/check_domains.php >> /var/www/html/logs/cron.log 2>&1

# Import TLD Registry weekly on Sunday at 2 AM
0 2 * * 0 /usr/bin/php /var/www/html/cron/import_tld_registry.php >> /var/www/html/logs/cron-tld.log 2>&1
```

## Troubleshooting

### Port Already in Use

Change `APP_PORT` in `.env`:

```ini
APP_PORT=8001
```

Then access: `http://localhost:8001`

### MySQL Won't Connect

```bash
docker-compose logs db
```

### Permission Errors

```bash
docker-compose exec app chown -R nobody:nobody /var/www/html
docker-compose exec app chmod -R 755 /var/www/html
docker-compose exec app chmod -R 775 logs
```

### Application Won't Start

Check PHP logs:

```bash
docker-compose logs app
```

### Composer Dependencies Missing

```bash
docker-compose exec app composer install
```

## Initial Setup

1. Run `./init.sh`
2. Access `http://localhost:8000`
3. Complete web installer
4. Save admin credentials
5. Configure settings in the app

## Production Notes

- Change all default passwords in `env.example.txt`
- Use environment-specific configs
- Enable HTTPS with your own reverse proxy if needed
- Set up automated backups
- Monitor logs regularly

## That's It
Just PHP's built-in server, MySQL, and Composer. Simple and clean.