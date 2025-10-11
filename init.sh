#!/bin/bash

# Domain Monitor Docker Initialization Script
set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_header() {
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}========================================${NC}"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    print_error "Docker is not installed. Please install Docker first."
    exit 1
fi

if ! command -v docker-compose &> /dev/null; then
    print_error "Docker Compose is not installed. Please install Docker Compose first."
    exit 1
fi

print_header "Domain Monitor Docker Setup"

# Step 1: Create .env file if it doesn't exist
if [ ! -f .env ]; then
    print_warning ".env file not found, creating from .env.example..."
    if [ -f env.example.txt ]; then
        cp env.example.txt .env
        print_success ".env created from env.example.txt"
    else
        cp docker/.env.example .env
        print_success ".env created from docker/.env.example"
    fi
else
    print_success ".env file already exists"
fi

# Step 2: Create docker directory structure if needed
mkdir -p docker logs

print_success "Created required directories"

# Step 3: Stop existing containers (if any)
print_header "Stopping existing containers..."
docker-compose down 2>/dev/null || true
print_success "Containers stopped"

# Step 4: Build and start containers
print_header "Building and starting Docker containers..."
docker-compose up -d

# Wait for containers to be ready
print_header "Waiting for services to be ready..."
sleep 10

# Check if containers are running
if ! docker-compose ps | grep -q "Up"; then
    print_error "Failed to start containers"
    docker-compose logs
    exit 1
fi

print_success "Containers started successfully"

# Step 5: Wait for MySQL to be ready
print_header "Waiting for MySQL to be ready..."
max_attempts=30
attempt=0

while [ $attempt -lt $max_attempts ]; do
    if docker-compose exec -T db mysqladmin ping -h localhost -u root -p"$(grep MYSQL_ROOT_PASSWORD .env | cut -d '=' -f2)" &> /dev/null; then
        print_success "MySQL is ready"
        break
    fi
    attempt=$((attempt + 1))
    echo "Attempt $attempt/$max_attempts..."
    sleep 2
done

if [ $attempt -eq $max_attempts ]; then
    print_error "MySQL failed to start within timeout"
    docker-compose logs db
    exit 1
fi

# Step 6: Set permissions
print_header "Setting up permissions..."
docker-compose exec -T app chown -R www-data:www-data /var/www/html
docker-compose exec -T app chmod -R 755 /var/www/html
docker-compose exec -T app chmod -R 775 logs
print_success "Permissions set correctly"

# Step 7: Display access information
print_header "Setup Complete!"
echo ""
echo -e "${GREEN}PHP-FPM is running on:${NC}"
APP_PORT=$(grep APP_PORT .env | cut -d '=' -f2)
echo -e "  ${BLUE}localhost:${APP_PORT:-9000}${NC}"
echo ""
echo -e "${GREEN}Database Information:${NC}"
echo -e "  Host: ${BLUE}db${NC} (or localhost:$(grep DB_PORT .env | cut -d '=' -f2) from host machine)"
echo -e "  Database: ${BLUE}$(grep DB_DATABASE .env | cut -d '=' -f2)${NC}"
echo -e "  Username: ${BLUE}$(grep DB_USERNAME .env | cut -d '=' -f2)${NC}"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo "  1. Set up your own web server (Nginx, Apache, Caddy, etc.)"
echo "  2. Configure it to connect to PHP-FPM on localhost:${APP_PORT:-9000}"
echo "  3. Point your domain to the web server"
echo "  4. Access the application and complete web installer"
echo "  5. Save admin credentials from the installer!"
echo ""
echo -e "${YELLOW}Useful Docker Commands:${NC}"
echo "  View logs:           ${BLUE}docker-compose logs -f app${NC}"
echo "  MySQL logs:          ${BLUE}docker-compose logs -f db${NC}"
echo "  Enter app container: ${BLUE}docker-compose exec app sh${NC}"
echo "  Stop services:       ${BLUE}docker-compose down${NC}"
echo "  Restart services:    ${BLUE}docker-compose restart${NC}"
echo ""
echo -e "${YELLOW}Example Nginx upstream:${NC}"
echo "  ${BLUE}upstream php_backend {${NC}"
echo "    ${BLUE}server localhost:${APP_PORT:-9000};${NC}"
echo "  ${BLUE}}${NC}"
echo ""