<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Supplies FAQ entries for a group identifier.
 *
 * Sources form a collect-all pool so FAQs can come from the module's own table, from product
 * attributes, or from a third-party FAQ extension. A bridge registers its source in the pool via
 * its own di.xml — MageOS_Seo is never modified.
 */
interface FaqSourceProviderInterface
{
    /**
     * Return FAQ entries for a group identifier and store.
     *
     * Each entry: ['question' => string, 'answer' => string].
     *
     * @param string $identifier
     * @param int $storeId
     * @return array<int, array{question: string, answer: string}>
     */
    public function getFaqs(string $identifier, int $storeId): array;

    /**
     * The group identifiers this source has FAQs for, in any store view.
     *
     * Offered as the groups a store can choose from — the llms documents' FAQ Groups setting, for
     * one — so a source's FAQs can be picked by group without knowing where they come from. A
     * source that cannot list its groups returns [].
     *
     * @return string[]
     */
    public function getIdentifiers(): array;
}
