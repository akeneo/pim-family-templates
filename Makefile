.PHONY: templates
templates:
	docker run --rm -v $(PWD):/srv/pim-family-templates -w /srv/pim-family-templates -u www-data akeneo/pim-php-dev:8.1 template-generator/bin/console template-generator:template:generate ${SOURCE_FILE} ${OUTPUT_FILE}
