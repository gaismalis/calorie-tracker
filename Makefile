# Local development. Run `make help` for the list of commands.
# Only Docker resource used: this project's Postgres (compose project "calorie-tracker").

PORT ?= 8000
CONSOLE = php bin/console

.DEFAULT_GOAL := help
.PHONY: help setup start server worker db stop migrate test test-db provider

help: ## Show available commands
	@grep -hE '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  make %-10s %s\n", $$1, $$2}'

setup: ## First-time setup: dependencies, database, migrations, test database
	composer install
	$(MAKE) db migrate test-db
	@test -f .env.local || printf 'NUTRITION_PROVIDER=random\n' > .env.local
	@echo "\nReady. Run: make start"

start: db migrate ## Start Postgres, migrations, the app (http://127.0.0.1:8000, PORT=... to change) and the background worker
	@$(MAKE) --no-print-directory provider
	@echo "App: http://127.0.0.1:$(PORT)  + background worker  (Ctrl+C stops both)"
	@trap 'kill 0' INT TERM EXIT; \
		$(CONSOLE) messenger:consume async --quiet & \
		php -S 127.0.0.1:$(PORT) -t public

server: ## Run only the PHP dev server (Ctrl+C to stop)
	@$(MAKE) --no-print-directory provider
	@echo "App: http://127.0.0.1:$(PORT)  (Ctrl+C to stop)"
	php -S 127.0.0.1:$(PORT) -t public

worker: ## Run only the background worker that retries meal estimates (-vv shows what it does)
	$(CONSOLE) messenger:consume async -vv

db: ## Start the Postgres container
	docker compose up -d --wait

stop: ## Stop the Postgres container (data is kept)
	docker compose stop

migrate: ## Apply database migrations
	$(CONSOLE) doctrine:migrations:migrate -n --allow-no-migration

test-db: ## Create and migrate the test database
	$(CONSOLE) doctrine:database:create --env=test --if-not-exists
	$(CONSOLE) doctrine:migrations:migrate -n --allow-no-migration --env=test

test: db ## Run the test suite
	php bin/phpunit

provider: ## Show which nutrition provider is active
	@printf 'Nutrition provider: '; $(CONSOLE) debug:dotenv 2>/dev/null | awk '$$1=="NUTRITION_PROVIDER"{print $$2; found=1} END{if(!found) print "?"}'
