<?php
/**
 * Translate plugin for Craft CMS 5.x
 *
 * @link      https://enupal.com
 * @copyright Copyright (c) 2018 Enupal
 */

namespace enupal\translate\serializers;

use craft\base\ElementInterface;
use craft\models\Site;
use enupal\translate\base\FieldSerializer;
use enupal\translate\Translate as TranslatePlugin;

/**
 * Craft's Content Block field — a single nested element rather than a list.
 */
class ContentBlock extends FieldSerializer
{
    public function serialize(ElementInterface $element, Site $sourceSite, Site $targetSite): mixed
    {
        $block = $element->getFieldValue($this->field->handle);

        if (!$block instanceof ElementInterface) {
            return ['fields' => null];
        }

        return [
            'fields' => TranslatePlugin::$app->content->serializeElementFields($block, $sourceSite, $targetSite),
        ];
    }

    public function setFieldData(ElementInterface $source, ElementInterface $target, mixed $value): mixed
    {
        $block = $source->getFieldValue($this->field->handle);

        // Craft hands back an unsaved block when the source never had one;
        // writing that would only create an empty block in every site.
        if (!$block instanceof ElementInterface || !$block->id) {
            return null;
        }

        $serialized = $this->field->serializeValue($block, $source);
        $translated = TranslatePlugin::$app->content->setElementFieldsTranslations($block, $target, $value['fields'] ?? []);

        // Craft only reads the nested values from the 'fields' key, so the
        // translations have to go in there rather than next to it.
        return [
            'fields' => array_merge(is_array($serialized) ? ($serialized['fields'] ?? []) : [], $translated),
        ];
    }

    public function appliesWithoutTranslation(): bool
    {
        return true;
    }
}
