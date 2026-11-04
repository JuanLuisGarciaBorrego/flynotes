COMPOSE_FILE ?= compose.yaml
PHP_SERVICE ?= php

DC := docker compose --file $(COMPOSE_FILE)
PHP := $(DC) exec $(PHP_SERVICE)
CONSOLE := $(PHP) php bin/console

.DEFAULT_GOAL := help

.PHONY: help install check-env build start stop down restart logs php console composer dependencies database migration migrate assets test cs-check cs-fix

help: ## Show available commands
	@awk 'BEGIN {FS = ":.*?## "}; /^[a-zA-Z0-9_-]+:.*?## / {printf "%-16s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: check-env build start dependencies database migrate assets ## Set up FlyNotes from a clean clone
	@printf '\nFlyNotes is ready: http://localhost:8000\n'

check-env: ## Check that local configuration exists
	@test -f .env.local || { echo 'Missing .env.local. Follow the Quick Start in README.md.'; exit 1; }

build: ## Build Docker images
	$(DC) build

start: ## Start FrankenPHP and PostgreSQL; wait until healthy
	$(DC) up -d --wait

stop: ## Stop containers
	$(DC) stop

down: ## Remove containers and network (keep PostgreSQL data)
	$(DC) down

restart: ## Restart containers
	$(DC) restart

logs: ## Follow container logs
	$(DC) logs -f

php: ## Open a shell inside the PHP container
	$(PHP) bash

console: ## Run a Symfony command: make console CMD="about"
	$(CONSOLE) $(CMD)

composer: ## Run Composer: make composer CMD="install"
	$(PHP) composer $(CMD)

dependencies: ## Install PHP dependencies from composer.lock
	$(DC) exec -T $(PHP_SERVICE) composer install --no-interaction --prefer-dist

database: ## Create the database if it does not exist
	$(CONSOLE) doctrine:database:create --if-not-exists

migration: ## Generate a Doctrine migration
	$(CONSOLE) doctrine:migrations:diff

migrate: ## Apply Doctrine migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

assets: ## Build Tailwind CSS for the local frontend
	$(CONSOLE) tailwind:build

test: ## Run PHPUnit in the test environment (test database must be initialized)
	$(DC) exec -e APP_ENV=test $(PHP_SERVICE) php vendor/bin/phpunit

cs-check: ## Check PHP code style
	$(PHP) php vendor/bin/php-cs-fixer check --diff

cs-fix: ## Fix PHP code style
	$(PHP) php vendor/bin/php-cs-fixer fix
