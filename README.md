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

The local development environment is managed with DDEV.

After the project infrastructure has been prepared, run:

```bash
ddev start
```

The remaining Drupal installation and project-specific commands will be added here after the initial DDEV environment is created and verified.

### First-time setup

Copy the local environment template:

```bash
cp .env.example .env
```

For this test project, `.env.example` intentionally contains a demo administrator username and password. The Drupal admin username and password are the values of `DRUPAL_ADMIN_USER` and `DRUPAL_ADMIN_PASSWORD` in `.env.example`.

The current demo password is for demonstration purposes only. Change it in `.env` before initialization if needed, and never use this demo password in a real development or production environment. In a real development project, `.env.example` should contain only a placeholder, while the actual password should be supplied through the uncommitted local `.env` file or a proper secret-management system.

Then run the single setup command:

```bash
make setup
```

The setup command starts DDEV, installs Composer dependencies, and installs Drupal if the site is not already installed. The `.env` file is local-only and must not be committed.

Useful commands:

```bash
make start
make stop
make restart
make logs
make status
make shell
make drush
```
