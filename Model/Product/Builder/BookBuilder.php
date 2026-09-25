<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class BookBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'Book';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Book';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'isbn'          => 'ISBN',
            'author'        => 'Author',
            'publisher'     => 'Publisher',
            'bookEdition'   => 'Edition',
            'bookFormat'    => 'Format (Hardcover / Paperback / Digital)',
            'numberOfPages' => 'Number of Pages',
            'inLanguage'    => 'Language',
            'genre'         => 'Genre',
            'gtin13'        => 'EAN / Barcode',
        ];
    }

    /**
     * @inheritdoc
     */
    public function build(
        ProductInterface $product,
        array            $enabledFields,
        array            $overrides
    ): array {
        $schema = $this->buildBase($product);

        if (\in_array('isbn', $enabledFields, true)) {
            $isbn = $overrides['isbn'] ?? $this->attr($product, 'isbn') ?: $this->attr($product, 'barcode');
            if ($isbn !== '') {
                $schema['isbn'] = $isbn;
                // An ISBN-13 is also the book's GTIN — but only when it validates as one
                // (ISBN-10 values or formatted strings must not be emitted as gtin13).
                $schema = $this->applyGtin($schema, (string) $isbn);
            }
        } elseif (\in_array('gtin13', $enabledFields, true)) {
            $gtin = $overrides['gtin13'] ?? $this->attr($product, 'barcode');
            if ($gtin !== '') {
                $schema = $this->applyGtin($schema, (string) $gtin);
            }
        }

        if (\in_array('author', $enabledFields, true)) {
            $author = $overrides['author'] ?? $this->attr($product, 'author');
            if ($author !== '') {
                $schema['author'] = ['@type' => 'Person', 'name' => $author];
            }
        }

        if (\in_array('publisher', $enabledFields, true)) {
            $publisher = $overrides['publisher'] ?? $this->attr($product, 'publisher');
            if ($publisher !== '') {
                $schema['publisher'] = ['@type' => 'Organization', 'name' => $publisher];
            }
        }

        foreach (['bookEdition', 'numberOfPages', 'inLanguage', 'genre'] as $field) {
            if (!\in_array($field, $enabledFields, true)) {
                continue;
            }
            $attrCode = match ($field) {
                'numberOfPages' => 'number_of_pages',
                'inLanguage'    => 'language',
                'bookEdition'   => 'book_edition',
                default         => $field,
            };
            $value = $overrides[$field] ?? $this->attr($product, $attrCode);
            if ($value !== '') {
                $schema[$field] = $value;
            }
        }

        if (\in_array('bookFormat', $enabledFields, true)) {
            $format = $overrides['bookFormat'] ?? $this->attr($product, 'book_format');
            if ($format !== '') {
                // Map common strings to schema.org book format URIs
                $formatMap = [
                    'hardcover' => 'https://schema.org/Hardcover',
                    'paperback' => 'https://schema.org/Paperback',
                    'digital'   => 'https://schema.org/EBook',
                    'ebook'     => 'https://schema.org/EBook',
                    'audiobook' => 'https://schema.org/AudiobookFormat',
                ];
                $normalized = strtolower(trim($format));
                $schema['bookFormat'] = $formatMap[$normalized] ?? $format;
            }
        }

        return $this->applyOverrides($schema, $overrides);
    }

    /**
     * @inheritdoc
     *
     * Multi-type: Book alone is a CreativeWork subtype, which forfeits Product
     * rich-result eligibility; Product must accompany it.
     */
    protected function getSchemaType(): string|array
    {
        return ['Product', 'Book'];
    }
}
