FROM node:22-alpine AS frontend
WORKDIR /app
# `npm ci` avec le verrou, comme le Dockerfile racine.
#
# Cette image reconstruit les memes assets que l'image `app` et les sert. Avec
# `npm install` sans `package-lock.json`, les deux constructions resolvaient
# leurs dependances separement : rien ne garantissait qu'elles produisent le
# meme bundle, et l'ecart n'aurait ete visible qu'en production.
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.ts tsconfig.json ./
RUN npm run build

FROM caddy:2-alpine
COPY infrastructure/caddy/Caddyfile /etc/caddy/Caddyfile
COPY public /var/www/html/public
COPY --from=frontend /app/public/build /var/www/html/public/build
