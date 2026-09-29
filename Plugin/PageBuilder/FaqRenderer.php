<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\PageBuilder;

use Magento\Framework\Filter\Template as FrameworkTemplateFilter;
use Magento\Framework\View\LayoutInterface;
use MageOS\Seo\Block\Widget\FaqList;

/**
 * Server-side renderer for the FAQ Page Builder content type.
 *
 * Plugged onto Magento\Framework\Filter\Template::filter() (the same point Page Builder uses) so it
 * runs after Page Builder has processed the stored content. Replaces each FAQ placeholder div with
 * the FaqList widget output — reusing the collector, renderer and FAQPage schema parity, and adding
 * no hard dependency on Magento_PageBuilder (the plugin simply no-ops when no placeholder is present).
 */
class FaqRenderer
{
    private const CONTENT_TYPE_MARKER = 'data-content-type="mageos_faq"';

    /**
     * @param LayoutInterface $layout
     */
    public function __construct(
        private readonly LayoutInterface $layout
    ) {
    }

    /**
     * Replace FAQ content-type placeholders with rendered FaqList output.
     *
     * @param FrameworkTemplateFilter $subject
     * @param string $result
     * @return string
     */
    public function afterFilter(FrameworkTemplateFilter $subject, string $result): string
    {
        if (!str_contains($result, self::CONTENT_TYPE_MARKER)) {
            return $result;
        }

        $rendered = preg_replace_callback(
            '/<div([^>]*data-content-type="mageos_faq"[^>]*)><\/div>/s',
            fn (array $matches): string => $this->renderBlock($matches[1]),
            $result
        );

        return $rendered ?? $result;
    }

    /**
     * Render the FaqList block for a placeholder's attributes.
     *
     * @param string $attributes
     * @return string
     */
    private function renderBlock(string $attributes): string
    {
        $identifier = $this->attribute($attributes, 'data-identifier');
        if ($identifier === '') {
            return '';
        }
        $heading = $this->attribute($attributes, 'data-heading');

        try {
            $block = $this->layout->createBlock(
                FaqList::class,
                'mageos_faq_pb_' . substr(hash('sha256', $identifier . '|' . $heading), 0, 16),
                ['data' => ['identifier' => $identifier, 'heading' => $heading]]
            );
            return $block->toHtml();
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- never break rendering
            return '';
        }
    }

    /**
     * Extract and HTML-decode a data attribute value from a div's attribute string.
     *
     * @param string $attributes
     * @param string $name
     * @return string
     */
    private function attribute(string $attributes, string $name): string
    {
        if (!preg_match('/' . preg_quote($name, '/') . '="([^"]*)"/', $attributes, $match)) {
            return '';
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- decode stored HTML entities; FaqList re-escapes on output
        return trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
