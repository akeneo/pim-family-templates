DOCKER = docker compose run --rm php

.PHONY: install
install:
	$(DOCKER) composer install

.PHONY: tests
tests:
	$(DOCKER) vendor/bin/phpunit

.PHONY: static
static:
	$(DOCKER) vendor/bin/phpstan

.PHONY: cs
cs:
	$(DOCKER) vendor/bin/php-cs-fixer fix --config=.php_cs.php --diff --dry-run

.PHONY: fix-cs
fix-cs:
	$(DOCKER) vendor/bin/php-cs-fixer fix --config=.php_cs.php --diff

.PHONY: templates
templates:
	$(DOCKER) rm -rf templates
	$(DOCKER) mkdir templates
	$(DOCKER) bin/console templates:generate ${SOURCE_FILE} templates

.PHONY: dist
dist:
	$(DOCKER) rm -rf dist
	$(DOCKER) mkdir dist
	$(DOCKER) bin/console templates:minify templates dist/minified.json
