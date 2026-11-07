.DEFAULT_GOAL := help

-include .env
export DRUPAL_SITE_NAME DRUPAL_SITE_EMAIL DRUPAL_ADMIN_USER DRUPAL_ADMIN_PASSWORD

.PHONY: help setup start stop restart logs status shell install drush

help:
	@printf '%s\n' \
		'make setup   Start DDEV, install dependencies, and install Drupal if needed' \
		'make start   Start DDEV services' \
		'make stop    Stop DDEV services' \
		'make restart Restart DDEV services' \
		'make logs    Show DDEV logs' \
		'make status  Show DDEV service status' \
		'make shell   Open a shell in the web container' \
		'make install Install Drupal if it is not already installed' \
		'make drush   Show Drupal status through Drush'

setup: start
	ddev composer install
	$(MAKE) install

start:
	ddev start

stop:
	ddev stop

restart:
	ddev restart

logs:
	ddev logs

status:
	ddev describe

shell:
	ddev ssh

install:
	@if ddev drush status --field=bootstrap 2>/dev/null | grep -q Successful; then \
		echo 'Drupal is already installed.'; \
	else \
		test -n "$(DRUPAL_ADMIN_PASSWORD)" || (echo 'DRUPAL_ADMIN_PASSWORD is required. Copy .env.example to .env.' && exit 1); \
		ddev drush site:install standard --yes \
			--site-name="$(DRUPAL_SITE_NAME)" \
			--site-mail="$(DRUPAL_SITE_EMAIL)" \
			--account-name="$(DRUPAL_ADMIN_USER)" \
			--account-mail="$(DRUPAL_SITE_EMAIL)" \
			--account-pass="$(DRUPAL_ADMIN_PASSWORD)"; \
	fi

drush:
	ddev drush status

