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
 * Redactor, CKEditor, TinyMCE and friends.
 *
 * The markup goes to the provider intact — every provider is instructed to
 * preserve tags — and reference tags are re-pointed at the target site
 * afterwards.
 */
class RichText extends FieldSerializer
{
    private ?Site $sourceSite = null;
    private ?Site $targetSite = null;

    public function serialize(ElementInterface $element, Site $sourceSite, Site $targetSite): mixed
    {
        $this->sourceSite = $sourceSite;
        $this->targetSite = $targetSite;

        return ['html' => $this->getHtml($element)];
    }

    public function setFieldData(ElementInterface $source, ElementInterface $target, mixed $value): mixed
    {
        // No translation means there was nothing to translate (an empty or
        // blank value), so the target mirrors the source rather than keeping
        // whatever it had before.
        $html = $value['html'] ?? $this->getHtml($source) ?? '';

        if (!is_string($html)) {
            return null;
        }

        $sourceSite = $this->sourceSite ?? $source->getSite();
        $targetSite = $this->targetSite ?? $target->getSite();

        return $this->translateReferenceTags($html, $sourceSite, $targetSite);
    }

    public function appliesWithoutTranslation(): bool
    {
        return true;
    }

    private function getHtml(ElementInterface $element): ?string
    {
        $value = $this->field->serializeValue($element->getFieldValue($this->field->handle), $element);

        if (is_array($value)) {
            // CKEditor stores nested entries alongside the markup.
            $value = $value['value'] ?? null;
        }

        return is_string($value) ? $value : null;
    }
}
