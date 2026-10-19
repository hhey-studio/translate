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

/**
 * Plain text fields.
 */
class Text extends FieldSerializer
{
    public function serialize(ElementInterface $element, Site $sourceSite, Site $targetSite): mixed
    {
        $value = parent::serialize($element, $sourceSite, $targetSite);

        return ['text' => is_string($value) ? $value : null];
    }

    public function setFieldData(ElementInterface $source, ElementInterface $target, mixed $value): mixed
    {
        // No translation means there was nothing to translate (an empty or
        // blank value), so the target mirrors the source rather than keeping
        // whatever it had before.
        return $value['text'] ?? $source->getFieldValue($this->field->handle) ?? '';
    }

    public function appliesWithoutTranslation(): bool
    {
        return true;
    }
}
