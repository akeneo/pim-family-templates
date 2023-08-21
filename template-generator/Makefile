.PHONY: tests
tests:
	docker compose run --rm php vendor/bin/phpunit

.PHONY: static
static:
	docker compose run --rm php vendor/bin/phpstan

.PHONY: cs
cs:
	docker compose run --rm php vendor/bin/php-cs-fixer fix --config=.php_cs.php --diff --dry-run

.PHONY: fix-cs
fix-cs:
	docker compose run --rm php vendor/bin/php-cs-fixer fix --config=.php_cs.php --diff
