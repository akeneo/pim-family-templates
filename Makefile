PHP = docker compose run --rm php
NODE = docker compose run --rm node

.PHONY: install
install:
	$(PHP) composer install
	$(NODE) yarn install

.PHONY: tests
tests:
	$(PHP) vendor/bin/phpunit

.PHONY: static
static:
	$(PHP) vendor/bin/phpstan

.PHONY: cs
cs:
	$(PHP) vendor/bin/php-cs-fixer fix --config=.php_cs.php --diff --dry-run

.PHONY: fix-cs
fix-cs:
	$(PHP) vendor/bin/php-cs-fixer fix --config=.php_cs.php --diff

TEMPLATES_DIR = templates
DIST_DIR = dist

.PHONY: generate-templates
generate-templates:
	$(PHP) rm -rf $(TEMPLATES_DIR)
	$(PHP) mkdir $(TEMPLATES_DIR)
	$(PHP) bin/console templates:generate ${SOURCE_FILE} $(TEMPLATES_DIR)
	$(NODE) yarn prettier $(TEMPLATES_DIR) -w

.PHONY: lint-templates
lint-templates:
	$(PHP) bin/console templates:lint $(TEMPLATES_DIR)
	$(NODE) yarn prettier $(TEMPLATES_DIR) -c

.PHONY: minify-templates
minify-templates:
	$(PHP) rm -rf $(DIST_DIR)
	$(PHP) mkdir $(DIST_DIR)
	$(PHP) bin/console templates:minify $(TEMPLATES_DIR) $(DIST_DIR)/minified.json

.PHONY: save-usages
save-usages:
ifeq ($(CI),true)
	docker compose run --rm -e DATADOG_API_KEY -e DATADOG_APP_KEY -e GCP_SERVICE_ACCOUNT php bin/console usages:save
else
	$(PHP) bin/console usages:save
endif
