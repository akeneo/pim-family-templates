DOCKER = docker run --rm -v $(PWD):/srv/pim-family-templates -w /srv/pim-family-templates -u www-data akeneo/pim-php-dev:8.1
CONSOLE = $(DOCKER) template-generator/bin/console

.PHONY: templates
templates:
	$(CONSOLE) template-generator:template:generate ${SOURCE_FILE} templates

.PHONY: dist
dist:
	$(DOCKER) rm -rf dist
	$(DOCKER) mkdir dist
	$(CONSOLE) template-generator:template:minify templates dist/minified.json
