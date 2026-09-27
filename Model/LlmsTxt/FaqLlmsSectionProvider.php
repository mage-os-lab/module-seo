<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\LlmsTxt;

use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Faq\SourcePool;

/**
 * Injects the configured FAQ groups into /llms.txt and /llms-full.txt as a markdown Q&A section.
 *
 * The groups are the store view's **FAQ Groups** setting (Config::getLlmsFaqGroups(), default
 * `global`), read from the FAQ source pool in the setting's order. Other groups stay out: they are
 * usually page-specific (a product's sizing questions), and llms.txt is a site-level summary. None
 * selected, or none with entries, leaves the section out.
 */
class FaqLlmsSectionProvider implements SectionProviderInterface
{
    private const CONCISE_LIMIT = 5;

    /**
     * @param SourcePool $sourcePool
     * @param StoreManagerInterface $storeManager
     * @param Config $seoConfig
     */
    public function __construct(
        private readonly SourcePool            $sourcePool,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config                $seoConfig
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getConciseSection(): string
    {
        return $this->render(self::CONCISE_LIMIT);
    }

    /**
     * @inheritdoc
     */
    public function getFullSection(): string
    {
        return $this->render(0);
    }

    /**
     * Render the FAQ markdown section, optionally limited to the first $limit entries of all groups.
     *
     * @param int $limit 0 = no limit
     * @return string
     */
    private function render(int $limit): string
    {
        $storeId = (int) $this->storeManager->getStore()->getId();

        $faqs = [];
        foreach ($this->seoConfig->getLlmsFaqGroups($storeId) as $group) {
            $faqs = [...$faqs, ...$this->sourcePool->getFaqs($group, $storeId)];
        }
        if ($faqs === []) {
            return '';
        }

        if ($limit > 0) {
            $faqs = \array_slice($faqs, 0, $limit);
        }

        $lines = ['## Frequently Asked Questions', ''];
        foreach ($faqs as $faq) {
            $lines[] = '**' . $faq['question'] . '**';
            $lines[] = $faq['answer'];
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }
}
