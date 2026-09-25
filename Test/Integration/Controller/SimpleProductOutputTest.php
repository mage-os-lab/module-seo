<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * A simple product page's product JSON-LD, title and meta tags, pinned whole.
 *
 * Changes to how configurable products are described must leave every other product's output
 * exactly as it was; this is the page they are compared against.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class SimpleProductOutputTest extends AbstractController
{
    use ProductPageOutput;

    private const SKU = 'mageos-seo-simple-output';

    /**
     * @return void
     */
    #[DataFixture(
        ProductFixture::class,
        ['sku' => self::SKU, 'name' => 'MageOS SEO Simple', 'price' => 12.5, 'url_key' => self::SKU],
        'product'
    )]
    public function testTheProductNodeTitleAndMetaTagsAreUnchanged(): void
    {
        $body = $this->productPage('product');
        $url  = 'http://localhost/index.php/' . self::SKU . '.html';

        $node           = $this->productNode($body);
        $node['offers'] = $this->withoutPriceValidUntil($node['offers'] ?? []);

        $this->assertSame(
            [
                '@context' => 'https://schema.org',
                '@type'    => 'Product',
                '@id'      => $url . '#product',
                'name'     => 'MageOS SEO Simple',
                'url'      => $url,
                'sku'      => self::SKU,
                'offers'   => [
                    '@type'         => 'Offer',
                    'url'           => $url,
                    'price'         => '12.50',
                    'priceCurrency' => 'USD',
                    'availability'  => 'https://schema.org/InStock',
                    'itemCondition' => 'https://schema.org/NewCondition',
                ],
                'image'    => 'http://localhost/static/VERSION/frontend/Magento/luma/en_US/Magento_Catalog/images/'
                    . 'product/placeholder/image.jpg',
            ],
            $node,
            'Product JSON-LD'
        );
        $this->assertSame('MageOS SEO Simple', $this->title($body), 'Title');

        $break = "\n        ";
        $this->assertSame(
            [
                '<meta charset="utf-8"/>',
                '<meta name="title" content="MageOS SEO Simple"/>',
                '<meta name="robots" content="INDEX,FOLLOW"/>',
                '<meta name="viewport" content="width=device-width, initial-scale=1"/>',
                '<meta name="format-detection" content="telephone=no"/>',
                '<meta property="og:site_name"' . $break . 'content="Default Store View"/>',
                '<meta property="og:locale"' . $break . 'content="en_US"/>',
                '<meta name="twitter:card"' . $break . 'content="summary_large_image"/>',
                '<meta property="og:type"' . $break . 'content="product"/>',
                '<meta property="og:title"' . $break . 'content="MageOS SEO Simple"/>',
                '<meta property="og:url"' . $break . 'content="' . $url . '"/>',
                '<meta property="og:image"' . $break . 'content="http://localhost/static/VERSION/frontend/Magento/'
                    . 'luma/en_US/Magento_Catalog/images/product/placeholder/image.jpg"/>',
                '<meta property="product:price:amount"' . $break . 'content="12.50"/>',
                '<meta property="product:price:currency"' . $break . 'content="USD"/>',
                '<meta property="product:availability"' . $break . 'content="instock"/>',
            ],
            $this->metaTags($body),
            'Meta tags'
        );
    }

    /**
     * @param string $body
     * @return string
     */
    private function title(string $body): string
    {
        return preg_match('#<title>(.*?)</title>#s', $body, $match) === 1 ? $match[1] : '';
    }

    /**
     * Every meta tag in the page's head, as written — the static-content version aside.
     *
     * @param string $body
     * @return string[]
     */
    private function metaTags(string $body): array
    {
        $body = (string) preg_replace('#/static/version\d+/#', '/static/VERSION/', $body);
        $head = strstr($body, '</head>', true) ?: $body;
        preg_match_all('#<meta\s[^>]*>#', $head, $matches);

        return $matches[0];
    }
}
