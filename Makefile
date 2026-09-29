.PHONY: build up setup test test-live test-wp test-beta logs down clean clean-beta shell

BETA = docker compose -f docker-compose.beta.yml -p suggestapi-wordpress-beta

build:
	docker compose build

up:
	docker compose up -d --build

setup:
	./bin/setup.sh

# Full: setup (idempotent) + suite in docker (php, js, live, WP REST, agent tag) + sync
test: setup
	docker compose run --rm --build tests
	./tests/test-sync.sh

# Pre-release track: same suite against wordpress:beta on :8081, isolated project.
test-beta:
	COMPOSE="$(BETA)" WP_URL=http://localhost:8081 ./bin/setup.sh
	COMPOSE="$(BETA)" $(BETA) run --rm --build tests
	COMPOSE="$(BETA)" WP_URL=http://localhost:8081 ./tests/test-sync.sh

# No-WP fast path: lint + live API only
test-live:
	SKIP_WP=1 ./tests/run-all.sh

# WP REST only (needs setup first)
test-wp:
	WP_BASE=http://localhost:8080 ./tests/test-wp-rest.sh

# Frontend search page only (needs setup first)
test-frontend:
	WP_BASE=http://localhost:8080 WP_URL=http://localhost:8080 ./tests/test-frontend.sh

# WooCommerce sync only (needs setup first)
test-sync:
	./tests/test-sync.sh

logs:
	docker compose logs -f

down:
	docker compose down

clean:
	docker compose down -v

down-beta:
	$(BETA) down

clean-beta:
	$(BETA) down -v

shell:
	docker compose run --rm wpcli bash || docker compose exec wordpress bash
