<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\View;

use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\View\Result\Layout;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\SchemaRegistry;
use Psr\Log\LoggerInterface;

/**
 * Removes the theme's schema.org microdata from a product page this module describes in JSON-LD
 * (review M9, decision D4a).
 *
 * Magento_Catalog's product page layout puts `itemscope itemtype="http://schema.org/Product"` on the
 * body and the page wrapper, and `itemprop`s on the name, SKU, description, image and price;
 * Magento_Review nests its AggregateRating and Review items in it. Search engines read that as a
 * second Product beside this module's. All of it is removed, not just the outer Product: without it
 * the review items would be left as Reviews of nothing. Attributes only: the elements stay, except
 * the `<meta>` and `<link>` elements that exist only to carry an `itemprop`.
 *
 * Only on a page whose product node this module built (SchemaRegistry has one once the page has
 * rendered), and while Remove the Theme's Product Microdata is on. The response is rewritten after
 * rendering, before the page cache stores it, as core's JsFooterPlugin moves scripts. Script and
 * style elements are left as they are: their text is not markup.
 */
class RemoveThemeMicrodata
{
    /**
     * Elements that only carry an itemprop: removed whole.
     */
    private const PROPERTY_ELEMENT = '#<(?:meta|link)\b[^>]*\bitemprop\s*=\s*(["\'])[^"\']*\1[^>]*>[ \t]*\r?\n?#i';

    /**
     * Microdata attributes with a value.
     */
    private const VALUED_ATTRIBUTE = '#\s+item(?:prop|type|id|ref)\s*=\s*(["\'])[^"\']*\1#i';

    /**
     * itemscope, with or without a value.
     */
    private const ITEMSCOPE = '#\s+itemscope(?:\s*=\s*(["\'])[^"\']*\1)?(?=[\s/>])#i';

    /**
     * @param SchemaRegistry $schemaRegistry
     * @param Config $seoConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SchemaRegistry  $schemaRegistry,
        private readonly Config          $seoConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Strip the microdata from the rendered product page.
     *
     * @param Layout $subject
     * @param Layout $result
     * @param ResponseInterface $httpResponse
     * @return Layout
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function afterRenderResult(Layout $subject, Layout $result, ResponseInterface $httpResponse): Layout
    {
        if (!$httpResponse instanceof HttpResponse
            || !$this->schemaRegistry->has()
            || !$this->seoConfig->isThemeMicrodataRemoved()
        ) {
            return $result;
        }

        $content  = (string) $httpResponse->getContent();
        $stripped = $this->strip($content);
        if ($stripped === null) {
            // A pattern gave up (PCRE's backtrack limit, say): the page goes out as rendered.
            $this->logger->warning(
                'MageOS_Seo: the theme\'s microdata was left on a product page: ' . preg_last_error_msg()
            );
            return $result;
        }
        if ($stripped !== $content) {
            $httpResponse->setContent($stripped);
        }

        return $result;
    }

    /**
     * The HTML without microdata outside its script and style elements, or null when a pattern failed.
     *
     * @param string $html
     * @return string|null
     */
    private function strip(string $html): ?string
    {
        $parts = preg_split('#(<script\b.*?</script>|<style\b.*?</style>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return null;
        }

        foreach ($parts as $index => $part) {
            // Odd parts are the script and style elements the split kept.
            if ($index % 2 === 1) {
                continue;
            }
            $part = preg_replace([self::PROPERTY_ELEMENT, self::VALUED_ATTRIBUTE, self::ITEMSCOPE], '', $part);
            if ($part === null) {
                return null;
            }
            $parts[$index] = $part;
        }

        return implode('', $parts);
    }
}
