# Drupal Market Feed

## Project description

This is a Drupal custom module that will ingest stock price data, store the latest price per symbol, and display the stored prices through Drupal.

## Technology stack

- Drupal 11
- PHP 8.3+
- MariaDB
- DDEV
- Composer

## How to run

The local development environment is managed with DDEV. Before starting, install:

- [Docker Desktop](https://www.docker.com/products/docker-desktop/)
- [DDEV](https://ddev.com/get-started/)

### Start the project for the first time

Open a terminal and run these commands one at a time:

1. Download the project:

   ```bash
   git clone https://github.com/rhewins/drupal-market-feed.git
   ```

2. Enter the project folder:

   ```bash
   cd drupal-market-feed
   ```

3. Create the local configuration file:

   ```bash
   cp .env.example .env
   ```

   For this test project, `.env.example` contains the demo Drupal administrator username and password. The values are named `DRUPAL_ADMIN_USER` and `DRUPAL_ADMIN_PASSWORD`.

   `MARKET_FEED_BATCH_SIZE` controls how many valid rows are written per database transaction. The default is `500`; it must be between `1` and `5000`.

4. Start and install the project:

   ```bash
   make setup
   ```

   The setup command starts Drupal and MariaDB, installs the required dependencies, and installs Drupal automatically.

5. Enable the Market Feed module:

   ```bash
   ddev drush en market_feed -y
   ```

6. Confirm that the module is enabled:

   ```bash
   ddev drush pm:list --type=module --status=enabled | grep market_feed
   ```

7. Open the URL shown by DDEV, usually:

   [http://drupal-market-feed.ddev.site](http://drupal-market-feed.ddev.site)

Use the administrator username and password from `.env` to sign in.

The current demo password is for demonstration purposes only. Change it in `.env` before initialization if needed, and never use this demo password in a real development or production environment. In a real development project, `.env.example` should contain only a placeholder, while the actual password should be supplied through the uncommitted local `.env` file or a proper secret-management system.

If you change `MARKET_FEED_BATCH_SIZE` in `.env` after the project is running, restart the project so PHP receives the new value:

```bash
make restart
```

Restarting stops and starts the containers without deleting the Drupal database or module files.

### Useful commands

```bash
make start
make stop
make restart
make logs
make status
make shell
make drush
```
