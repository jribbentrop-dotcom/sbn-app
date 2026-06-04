#!/bin/bash
# Install nginx, PHP 8.3, git, node, composer on Rocky Linux 9

# Enable EPEL + Remi (PHP 8.3)
dnf install -y epel-release
dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm
dnf module reset php -y
dnf module enable php:remi-8.3 -y

# Install everything
dnf install -y nginx git unzip curl

dnf install -y php php-fpm php-cli php-mbstring php-xml php-sqlite3 \
    php-pdo php-tokenizer php-json php-ctype php-bcmath php-curl \
    php-zip php-gd php-intl php-fileinfo php-opcache

# Node 20 via NodeSource
curl -fsSL https://rpm.nodesource.com/setup_20.x | bash -
dnf install -y nodejs

# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Start + enable services
systemctl enable --now nginx php-fpm

echo "=== Done ==="
php -v | head -1
nginx -v 2>&1
node -v
composer --version | head -1
