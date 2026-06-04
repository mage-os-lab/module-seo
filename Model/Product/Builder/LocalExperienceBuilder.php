<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class LocalExperienceBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'LocalExperience';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Experience / Voucher / Workshop';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'organizer'           => 'Organiser (overridden by SellersSeo bridge)',
            'availabilityStarts'  => 'Availability Starts (YYYY-MM-DD)',
            'availabilityEnds'    => 'Availability Ends (YYYY-MM-DD)',
            'location'            => 'Location / Venue',
            'duration'            => 'Duration',
            'gtin13'              => 'GTIN / EAN',
        ];
    }

    /**
     * @inheritdoc
     */
    public function build(ProductInterface $product, array $enabledFields, array $overrides, array $variantData): array
    {
        $schema = $this->buildBase($product, $variantData);

        if (\in_array('availabilityStarts', $enabledFields, true)) {
            $starts = $overrides['availabilityStarts'] ?? $this->attr($product, 'availability_starts');
            if ($starts !== '') {
                $schema['offers']['availabilityStarts'] = $starts;
            }
        }

        if (\in_array('availabilityEnds', $enabledFields, true)) {
            $ends = $overrides['availabilityEnds'] ?? $this->attr($product, 'availability_ends');
            if ($ends !== '') {
                $schema['offers']['availabilityEnds'] = $ends;
            }
        }

        if (\in_array('location', $enabledFields, true)) {
            $location = $overrides['location'] ?? $this->attr($product, 'location') ?: $this->attr($product, 'venue');
            if ($location !== '') {
                $schema['location'] = ['@type' => 'Place', 'name' => $location];
            }
        }

        if (\in_array('duration', $enabledFields, true)) {
            $duration = $overrides['duration'] ?? $this->attr($product, 'duration');
            if ($duration !== '') {
                $schema['duration'] = $duration;
            }
        }

        // organizer — expected from SellersSeo bridge via overrides
        if (\in_array('organizer', $enabledFields, true) && !empty($overrides['organizer'])) {
            $schema['organizer'] = $overrides['organizer'];
        }

        if (\in_array('gtin13', $enabledFields, true)) {
            $gtin = $overrides['gtin13'] ?? $this->attr($product, 'barcode');
            if ($gtin !== '') {
                $schema['gtin13'] = $gtin;
            }
        }

        return $this->applyOverrides($schema, $overrides);
    }
}
