<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Category;

/**
 * Resolves a category's SEO settings field by field from an ordered list of candidate rows.
 *
 * Each field is taken from the first source that has a value for it, independently of the
 * others — so a category can take its schema template from its parent and its robots directive
 * from its grandparent. The previous behaviour copied one ancestor's whole row and only when
 * that ancestor had a schema template, which made a template code decide whether an unrelated
 * setting was inherited at all.
 *
 * Which sources are offered, and in what order, is decided by the configured
 * CategoryConfigSourceOrderInterface. This class knows nothing about categories or store views;
 * it is handed rows in precedence order and reports what each field resolves to.
 *
 * **A field is absent only when it is null or an empty string.** `item_list_enabled` is the one
 * that makes this matter: 0 means "this category switches the ItemList off", which must beat an
 * ancestor's 1, and anything testing it with empty() would discard it and inherit the opposite
 * of what the merchant asked for. That test lives here, once, rather than at each caller.
 */
class InheritanceResolver
{
    /**
     * The fields a category may inherit.
     *
     * An explicit list, not "every column": entity_id, category_id, store_id and updated_at
     * identify a row rather than configure a category, and a column added later must be
     * considered before it starts being inherited.
     */
    public const INHERITABLE_FIELDS = [
        'schema_template',
        'enabled_fields',
        'override_fields',
        'item_list_enabled',
        'robots_meta',
    ];

    /**
     * Resolve the settings from candidate rows given most specific first.
     *
     * The returned row is the most specific source's, with each inheritable field it does not
     * set filled in from the nearest source that does. When no source sets a field it is absent
     * from the result, so callers can still tell "not configured" from "configured as empty".
     *
     * @param mixed[][] $sources Candidate rows, most specific first
     * @return mixed[]
     */
    public function resolve(array $sources): array
    {
        $sources = array_values(array_filter($sources, static fn (array $row): bool => $row !== []));

        if ($sources === []) {
            return [];
        }

        $resolved = $sources[0];

        foreach (self::INHERITABLE_FIELDS as $field) {
            if ($this->holdsAValue($resolved, $field)) {
                continue;
            }

            foreach ($sources as $source) {
                if ($this->holdsAValue($source, $field)) {
                    $resolved[$field] = $source[$field];
                    break;
                }
            }
        }

        return $resolved;
    }

    /**
     * Whether a row says something about a field.
     *
     * @param mixed[] $row
     * @param string $field
     * @return bool
     */
    private function holdsAValue(array $row, string $field): bool
    {
        if (!\array_key_exists($field, $row)) {
            return false;
        }

        // Not empty(): 0 and "0" are answers, and item_list_enabled = 0 is the whole point.
        return $row[$field] !== null && $row[$field] !== '';
    }
}
