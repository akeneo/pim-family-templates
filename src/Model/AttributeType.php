<?php

declare(strict_types=1);

namespace Akeneo\PimFamilyTemplates\Model;

enum AttributeType: string
{
    case ATTRIBUTE_TYPE_IMAGE = 'pim_catalog_image';
    case ATTRIBUTE_TYPE_TEXT = 'pim_catalog_text';
    case ATTRIBUTE_TYPE_IDENTIFIER = 'pim_catalog_identifier';
    case ATTRIBUTE_TYPE_METRIC = 'pim_catalog_metric';
    case ATTRIBUTE_TYPE_NUMBER = 'pim_catalog_number';
    case ATTRIBUTE_TYPE_PRICE_COLLECTION = 'pim_catalog_price_collection';
    case ATTRIBUTE_TYPE_BOOLEAN = 'pim_catalog_boolean';
    case ATTRIBUTE_TYPE_DATE = 'pim_catalog_date';
    case ATTRIBUTE_TYPE_MULTISELECT = 'pim_catalog_multiselect';
    case ATTRIBUTE_TYPE_SIMPLESELECT = 'pim_catalog_simpleselect';
    case ATTRIBUTE_TYPE_TEXTAREA = 'pim_catalog_textarea';
    case ATTRIBUTE_TYPE_FILE = 'pim_catalog_file';

    public static function getChoices(): array
    {
        return array_column(self::cases(), 'value');
    }
}
