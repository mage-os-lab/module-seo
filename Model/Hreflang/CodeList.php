<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Hreflang;

use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;

/**
 * Checks a store view's list of hreflang codes, and sorts what can be kept from what cannot.
 *
 * Shared by the admin field (Model\Config\Backend\HreflangCodes), which refuses a list with any
 * problem, and the MageOS_Hreflang migration, which keeps the good codes and reports the rest: both
 * must mean the same thing by "valid".
 */
class CodeList
{
    /**
     * @param CodeValidator $codeValidator
     * @param CountryCollectionFactory $countryCollectionFactory
     */
    public function __construct(
        private readonly CodeValidator            $codeValidator,
        private readonly CountryCollectionFactory $countryCollectionFactory
    ) {
    }

    /**
     * Normalise a list of codes and separate the usable ones from the problems.
     *
     * `codes` keeps the entered order, each code once. A code is left out of it — and listed, as
     * entered, in `rejected` — when it is malformed or names a region that is not an ISO 3166-1
     * country. A code entered twice is kept once and reported in `duplicates`, since the admin
     * refuses it while the migration can simply keep one.
     *
     * @param string[] $entered
     * @return array{
     *     codes: string[],
     *     rejected: string[],
     *     malformed: string[],
     *     duplicates: string[],
     *     unknown_regions: string[]
     * }
     */
    public function check(array $entered): array
    {
        $wellFormed = []; // [entered, normalised] pairs, in entered order
        $malformed  = [];
        foreach ($entered as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }
            $candidate = $this->codeValidator->normalise($code);
            if (!$this->codeValidator->isValid($candidate)) {
                $malformed[] = $code;
                continue;
            }
            $wellFormed[] = [$code, $candidate];
        }

        $normalised = array_column($wellFormed, 1);
        $duplicates = array_keys(array_filter(
            array_count_values($normalised),
            static fn (int $count): bool => $count > 1
        ));

        $unknownRegions = $this->unknownRegions($normalised);
        $codes          = [];
        $rejected       = $malformed;
        foreach ($wellFormed as [$asEntered, $code]) {
            if (\in_array($this->codeValidator->region($code), $unknownRegions, true)) {
                $rejected[] = $asEntered;
            } elseif (!\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return [
            'codes'           => $codes,
            'rejected'        => $rejected,
            'malformed'       => $malformed,
            'duplicates'      => $duplicates,
            'unknown_regions' => $unknownRegions,
        ];
    }

    /**
     * Regions used by the codes that Magento's country directory does not know.
     *
     * @param string[] $codes
     * @return string[]
     */
    private function unknownRegions(array $codes): array
    {
        $regions = array_values(array_unique(array_filter(array_map(
            fn (string $code): ?string => $this->codeValidator->region($code),
            $codes
        ))));

        if ($regions === []) {
            return [];
        }

        $collection = $this->countryCollectionFactory->create();
        $collection->addFieldToFilter('country_id', ['in' => $regions]);

        $known = [];
        foreach ($collection as $country) {
            $known[] = (string) $country->getId();
        }

        return array_values(array_diff($regions, $known));
    }
}
