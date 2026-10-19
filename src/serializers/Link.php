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
 * Link fields. The visible strings are translated and element links are
 * re-pointed at the target site.
 *
 * Craft pins the site when an element is picked ({entry:12@1:url}), so a link
 * copied across as-is keeps resolving to the source site's URL.
 */
class Link extends FieldSerializer
{
    private const TEXT_KEYS = ['label', 'title', 'ariaLabel'];

    public function serialize(ElementInterface $element, Site $sourceSite, Site $targetSite): mixed
    {
        $value = parent::serialize($element, $sourceSite, $targetSite);

        if (!is_array($value)) {
            return ['label' => null];
        }

        $strings = [];

        foreach (self::TEXT_KEYS as $key) {
            $strings[$key] = $value[$key] ?? null;
        }

        return $strings;
    }

    public function setFieldData(ElementInterface $source, ElementInterface $target, mixed $value): mixed
    {
        $serialized = $this->field->serializeValue($source->getFieldValue($this->field->handle), $source);

        if (!is_array($serialized)) {
            return null;
        }

        $value = is_array($value) ? $value : [];

        foreach (self::TEXT_KEYS as $key) {
            if (!empty($value[$key])) {
                $serialized[$key] = $value[$key];
            }
        }

        if (isset($serialized['value']) && is_string($serialized['value'])) {
            $serialized['value'] = $this->translateReferenceTags($serialized['value'], $source->getSite(), $target->getSite());
        }

        return $serialized;
    }

    public function appliesWithoutTranslation(): bool
    {
        return true;
    }
}
