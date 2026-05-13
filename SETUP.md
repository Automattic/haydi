# Setup

Needs: [Docker](https://www.docker.com/) + Node 18+ + PHP 8.2+ + Composer.

```bash
nvm use
npm install
composer install
npm run env:start
```

→ http://localhost:9888/wp-admin — login `admin` / `password`

1. Activate **Haydi** in Plugins
2. Go to **Tools → Haydi**, configure your AI connector in Settings → Connectors
3. Start chatting

## Running tests

```bash
# PHP unit tests (no running WordPress required)
vendor/bin/phpunit

# JS unit tests (no browser required)
npm run test:unit

# Integration tests (requires env:start)
npm test
```

## Environment commands

```bash
npm run env:stop     # pause
npm run env:destroy  # wipe everything
npm run env:logs     # PHP/WP logs
```
