<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\TestFramework\Fixture\DataFixtureStorageManager;

/**
 * Renders a fixture product's page and reads its product JSON-LD node, for controller tests.
 *
 * Expects to be used in a Magento\TestFramework\TestCase\AbstractController.
 */
trait ProductPageOutput
{
    /**
     * Render a fixture product's page and return its HTML.
     *
     * @param string $fixture The fixture's name in the data fixture storage
     * @return string
     */
    private function productPage(string $fixture): string
    {
        $productId = (int) DataFixtureStorageManager::getStorage()->get($fixture)->getId();

        $this->dispatch('catalog/product/view/id/' . $productId);

        return (string) $this->getResponse()->getBody();
    }

    /**
     * The JSON-LD node describing the product: a Product, or a ProductGroup for a configurable.
     *
     * The static-content version in asset URLs differs from one deployment to the next, so it is
     * replaced by a fixed token.
     *
     * @param string $body
     * @return array<string,mixed>
     */
    private function productNode(string $body): array
    {
        $body = (string) preg_replace('#/static/version\d+/#', '/static/VERSION/', $body);
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $body, $matches);
        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);
            foreach (\is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded] as $node) {
                $types = \is_array($node) ? (array) ($node['@type'] ?? []) : [];
                if (array_intersect(['Product', 'ProductGroup'], $types) !== []) {
                    return $node;
                }
            }
        }

        return [];
    }

    /**
     * Assert an offer's priceValidUntil is a date and remove it, so the rest can be pinned whole.
     *
     * The date lies some months ahead of today: its shape is pinned, not its value.
     *
     * @param array<string,mixed> $offer
     * @return array<string,mixed>
     */
    private function withoutPriceValidUntil(array $offer): array
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $offer['priceValidUntil'] ?? '');
        unset($offer['priceValidUntil']);

        return $offer;
    }
}
