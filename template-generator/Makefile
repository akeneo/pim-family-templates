.PHONY: tests
tests:
	docker compose run --rm php vendor/bin/phpunit

.PHONY: static
static:
	docker compose run --rm php vendor/bin/phpstan
