FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=America/Sao_Paulo \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

# Sistema base + PHP 8.3 (nativo no Ubuntu 24.04), PostgreSQL 16, Redis, Node 22 e Composer.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git gnupg unzip sudo tzdata procps \
        php8.3-cli php8.3-common php8.3-pgsql php8.3-mbstring php8.3-xml \
        php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd \
        php8.3-redis php8.3-sqlite3 \
        postgresql postgresql-contrib redis-server \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && curl -sS https://getcomposer.org/installer \
        | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

# O usuário "ubuntu" (uid 1000) já existe na imagem oficial. Garante sudo sem senha
# para que o script de start consiga iniciar PostgreSQL e Redis.
RUN echo 'ubuntu ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/90-ubuntu \
    && chmod 0440 /etc/sudoers.d/90-ubuntu

USER ubuntu
WORKDIR /home/ubuntu
