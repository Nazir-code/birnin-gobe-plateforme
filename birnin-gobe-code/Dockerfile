

# syntax=docker/dockerfile:1.7
FROM composer:2 AS composer
WORKDIR /app
# Le verrou est copie avec le manifeste : sans lui, `composer install` resout les
# dependances a neuf a chaque construction et l'image ne reproduit pas ce qui a
# ete teste. La plateforme cible est declaree dans composer.json, donc la
# resolution ne depend pas des extensions presentes dans ce conteneur de build.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

FROM node:22-alpine AS frontend
WORKDIR /app
# Meme raison cote Node : `npm ci` installe exactement le verrou, la ou
# `npm install` peut le contourner et faire deriver les versions.
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.ts tsconfig.json ./
RUN npm run build

FROM php:8.4-fpm-alpine AS runtime
RUN apk add --no-cache icu-dev libzip-dev postgresql-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install intl pdo_pgsql pcntl bcmath opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS
WORKDIR /var/www/html
COPY --from=composer /app /var/www/html
COPY --from=frontend /app/public/build /var/www/html/public/build
# `storage/app/private` doit exister DANS l'image, et pas seulement a
# l'execution : docker-compose y monte un volume nomme pour rendre les pieces de
# candidature durables, et un volume dont le point de montage n'existe pas dans
# l'image est cree par le demon en root:root. PHP-FPM tourne en www-data : le
# televersement echouerait alors sur un refus de permission, en production
# seulement. Creer le dossier avant le `chown` fait heriter au volume le bon
# proprietaire.
RUN mkdir -p storage/app/private/documents     && chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php-fpm", "-F"]
