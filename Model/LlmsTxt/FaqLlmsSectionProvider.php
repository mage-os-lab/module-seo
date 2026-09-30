<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\LlmsTxt;

use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Aeo\Config;
use MageOS\Seo\Model\Faq\SourcePool;

/**
 * Injects the configured FAQ groups into /llms.txt and /llms-full.txt as a markdown Q&A list.
 *
 * The groups are the store view's **FAQ Groups** setting (Config::getLlmsFaqGroups(), default
 * `global`), read from the FAQ source pool in the setting's order. Other groups stay out: they are
 * usually page-specific (a product's sizing questions), and llms.txt is a site-level summary. None
 * selected, or none with entries, leaves the section out.
 *
 * The output has no heading, so LlmsTxtBuilder places it in the details block before the first H2:
 * in the llms.txt format an H2 section may hold only a list of links, and FAQs are not links.
 * Each question and answer is reduced to one line of plain text so an answer containing HTML or
 * line breaks cannot break the list.
 */
class FaqLlmsSectionProvider implements SectionProviderInterface
{
    private const CONCISE_LIMIT = 5;

    /**
     * @param SourcePool $sourcePool
     * @param StoreManagerInterface $storeManager
     * @param Config $aeoConfig
     */
    public function __construct(
        private readonly SourcePool            $sourcePool,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config                $aeoConfig
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
        foreach ($this->aeoConfig->getLlmsFaqGroups($storeId) as $group) {
            $faqs = [...$faqs, ...$this->sourcePool->getFaqs($group, $storeId)];
        }
        if ($faqs === []) {
            return '';
        }

        if ($limit > 0) {
            $faqs = \array_slice($faqs, 0, $limit);
        }

        $lines = ['Frequently asked questions:', ''];
        foreach ($faqs as $faq) {
            $question = $this->plainText((string) $faq['question']);
            $answer   = $this->plainText((string) $faq['answer']);
            if ($question === '' || $answer === '') {
                continue;
            }
            $lines[] = '- **' . str_replace('*', '\\*', $question) . '** ' . $answer;
        }
        if (\count($lines) === 2) {
            return '';
        }

        return implode("\n", $lines);
    }

    /**
     * Reduce rich FAQ content to one line of plain text.
     *
     * @param string $text
     * @return string
     */
    private function plainText(string $text): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- plain text from admin HTML
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
