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
 * Verbb Table Maker. Unlike Craft's table field, the columns are part of the
 * value, so the headings are translated along with the text cells.
 */
class TableMaker extends FieldSerializer
{
    private const TEXT_COLUMN_TYPES = ['singleline', 'multiline'];

    public function serialize(ElementInterface $element, Site $sourceSite, Site $targetSite): mixed
    {
        $value = $this->getValue($element);

        if ($value === null) {
            return ['columns' => null, 'rows' => null];
        }

        $columns = [];
        $rows = [];

        foreach ($value['columns'] as $index => $column) {
            $columns[$index] = $column['heading'] ?? null;
        }

        foreach ($value['rows'] as $rowIndex => $row) {
            foreach ($row as $columnIndex => $cell) {
                if ($this->isTextColumn($value['columns'][$columnIndex] ?? null) && is_string($cell)) {
                    $rows[$rowIndex][$columnIndex] = $cell;
                }
            }
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    public function setFieldData(ElementInterface $source, ElementInterface $target, mixed $value): mixed
    {
        $sourceValue = $this->getValue($source);

        if ($sourceValue === null) {
            return null;
        }

        $translatedColumns = $value['columns'] ?? [];
        $translatedRows = $value['rows'] ?? [];

        foreach ($sourceValue['columns'] as $index => $column) {
            if (isset($translatedColumns[$index])) {
                $sourceValue['columns'][$index]['heading'] = $translatedColumns[$index];
            }
        }

        foreach ($sourceValue['rows'] as $rowIndex => $row) {
            foreach ($row as $columnIndex => $cell) {
                if (isset($translatedRows[$rowIndex][$columnIndex])
                    && $this->isTextColumn($sourceValue['columns'][$columnIndex] ?? null)) {
                    $sourceValue['rows'][$rowIndex][$columnIndex] = $translatedRows[$rowIndex][$columnIndex];
                }
            }
        }

        return $sourceValue;
    }

    public function appliesWithoutTranslation(): bool
    {
        // Untranslated headings and cells fall back to the source, so a table
        // without any text still mirrors the source.
        return true;
    }

    /**
     * The field value as positional columns and rows, without the rendered
     * table markup that Table Maker adds to the value and rebuilds on load.
     *
     * @return array{columns: array, rows: array}|null
     */
    private function getValue(ElementInterface $element): ?array
    {
        $value = $this->field->serializeValue($element->getFieldValue($this->field->handle), $element);

        if (!is_array($value)) {
            return null;
        }

        $rows = array_map(
            fn($row) => is_array($row) ? array_values($row) : [],
            array_values($value['rows'] ?? [])
        );

        return [
            'columns' => array_values($value['columns'] ?? []),
            'rows' => $rows,
        ];
    }

    private function isTextColumn(?array $column): bool
    {
        return in_array($column['type'] ?? 'singleline', self::TEXT_COLUMN_TYPES, true);
    }
}
