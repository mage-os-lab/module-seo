<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Faq;

use MageOS\Seo\Api\FaqSourceProviderInterface;

/**
 * Collect-all pool aggregating FAQ entries for an identifier across all registered sources.
 */
class SourcePool
{
    /**
     * @param array<mixed> $sources
     */
    public function __construct(
        private readonly array $sources = []
    ) {
    }

    /**
     * Aggregate FAQ entries for a group identifier from every source.
     *
     * @param string $identifier
     * @param int $storeId
     * @return array<int, array{question: string, answer: string}>
     */
    public function getFaqs(string $identifier, int $storeId): array
    {
        $faqs = [];
        foreach ($this->sources as $source) {
            if (!$source instanceof FaqSourceProviderInterface) {
                continue;
            }
            foreach ($source->getFaqs($identifier, $storeId) as $faq) {
                if (!empty($faq['question']) && !empty($faq['answer'])) {
                    $faqs[] = $faq;
                }
            }
        }

        return $faqs;
    }

    /**
     * Every group identifier any source has FAQs for, sorted, each once.
     *
     * @return string[]
     */
    public function getIdentifiers(): array
    {
        $identifiers = [];
        foreach ($this->sources as $source) {
            if ($source instanceof FaqSourceProviderInterface) {
                array_push($identifiers, ...array_values($source->getIdentifiers()));
            }
        }

        $identifiers = array_values(array_unique(array_map('strval', $identifiers)));
        sort($identifiers, SORT_STRING);

        return $identifiers;
    }
}
