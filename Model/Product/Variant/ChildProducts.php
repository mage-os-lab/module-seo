<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Variant;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Pricing\Price\ConfigurableOptionsFilterInterface;
use Magento\ConfigurableProduct\Pricing\Price\ConfigurableOptionsProviderInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use MageOS\Seo\Model\ResourceModel\VariantLinks;
use Psr\Log\LoggerInterface;

/**
 * The children a configurable product's structured data describes.
 *
 * Core's own answer, not a second query: `ConfigurableOptionsProviderInterface` is where core
 * prices a configurable from, it returns the used products after `ConfigurableOptionsFilterInterface`
 * (on the storefront, enabled children, and in-stock ones unless out-of-stock products are shown),
 * and it keeps them for the request, so the product page's own load is reused. A module that
 * narrows what the storefront sells registers a filter there, and the JSON-LD follows.
 *
 * A feed reads a page of products at once instead (prefetch()): one collection for all their
 * variants (ChildLoader), where core's provider would load each configurable's whole children,
 * gallery and configurable attributes included, ten queries apiece. One page is held at a time.
 *
 * Both are filtered by the storefront's filters (`$optionsFilter`, Seo's `StorefrontOptionsFilter`
 * in di.xml: core's status and stock-status filters). Core registers them in the frontend area only,
 * so a feed built in the queue or cron area would otherwise describe disabled and out-of-stock
 * variants. A module that adds a filter to core's frontend composite adds it there too.
 */
class ChildProducts implements ResetAfterRequestInterface
{
    /**
     * The sellable children of the configurables last prefetched, by configurable entity ID.
     *
     * @var array<int, ProductInterface[]>
     */
    private array $prefetched = [];

    /**
     * @param ConfigurableOptionsProviderInterface $optionsProvider
     * @param ConfigurableOptionsFilterInterface $optionsFilter
     * @param VariantLinks $variantLinks
     * @param ChildLoader $childLoader
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConfigurableOptionsProviderInterface $optionsProvider,
        private readonly ConfigurableOptionsFilterInterface   $optionsFilter,
        private readonly VariantLinks                         $variantLinks,
        private readonly ChildLoader                          $childLoader,
        private readonly LoggerInterface                      $logger
    ) {
    }

    /**
     * The configurable's sellable children; empty for any other product type.
     *
     * From the page last prefetched when the configurable is on it, otherwise from core's provider.
     *
     * @param ProductInterface $product
     * @return ProductInterface[]
     */
    public function get(ProductInterface $product): array
    {
        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return [];
        }

        $productId = (int) $product->getId();
        if (\array_key_exists($productId, $this->prefetched)) {
            return $this->prefetched[$productId];
        }

        // Core's provider filters only in the frontend area; the storefront filter here makes a feed,
        // built in the queue or cron area, sell what the storefront sells. On a page it repeats core's.
        return array_values($this->optionsFilter->filter($product, $this->optionsProvider->getProducts($product)));
    }

    /**
     * Read the sellable children of a page of products' configurables at once.
     *
     * Replaces the page read before. Configurables that share a variant are loaded apart. A load
     * that fails is logged, and its configurables are left to core's provider: still right, slower.
     *
     * @param ProductInterface[] $products
     * @return void
     */
    public function prefetch(array $products): void
    {
        $this->prefetched = [];

        $byStore = [];
        foreach ($products as $product) {
            // Only the catalogue model carries the store and data a collection filter reads.
            if ($product instanceof Product && $product->getTypeId() === Configurable::TYPE_CODE) {
                $byStore[(int) $product->getStoreId()][(int) $product->getId()] = $product;
            }
        }
        if ($byStore === []) {
            return;
        }

        try {
            $links = $this->variantLinks->forParents(array_merge(...array_map('array_keys', array_values($byStore))));
        } catch (\Exception $e) {
            $this->logFailure(array_merge(...array_values($byStore)), $e);
            return;
        }

        foreach ($byStore as $storeId => $parents) {
            foreach ($this->apart(array_keys($parents), $links) as $parentIds) {
                $batch = array_intersect_key($parents, array_flip($parentIds));
                try {
                    $children = $this->childLoader->load($batch, $storeId);
                } catch (\Exception $e) {
                    $this->logFailure($batch, $e);
                    continue;
                }
                foreach ($batch as $parentId => $parent) {
                    $this->prefetched[$parentId] = array_values(
                        $this->optionsFilter->filter($parent, $children[$parentId] ?? [])
                    );
                }
            }
        }
    }

    /**
     * Configurables grouped so that no group shares a variant.
     *
     * Each joins the first group it shares none with. On a catalogue whose variants each belong to
     * one configurable, that is one group.
     *
     * @param int[] $parentIds
     * @param array<int,int[]> $links configurable entity ID => its variants' entity IDs
     * @return int[][]
     */
    private function apart(array $parentIds, array $links): array
    {
        $groups = [];
        foreach ($parentIds as $parentId) {
            $variants = array_fill_keys($links[$parentId] ?? [], true);
            foreach ($groups as $index => $group) {
                if (array_intersect_key($group['variants'], $variants) === []) {
                    $groups[$index]['parents'][] = $parentId;
                    $groups[$index]['variants'] += $variants;
                    continue 2;
                }
            }
            $groups[] = ['parents' => [$parentId], 'variants' => $variants];
        }

        return array_column($groups, 'parents');
    }

    /**
     * Log a page read that failed, with the configurables it left to core's provider.
     *
     * @param ProductInterface[] $parents
     * @param \Exception $e
     * @return void
     */
    private function logFailure(array $parents, \Exception $e): void
    {
        $this->logger->error(
            \sprintf(
                'MageOS_Seo: could not read the variants of configurables "%s" at once, so each is read'
                . ' on its own: %s',
                implode('", "', array_map(static fn (ProductInterface $p): string => (string) $p->getSku(), $parents)),
                $e->getMessage()
            ),
            ['exception' => $e]
        );
    }

    /**
     * @inheritdoc
     */
    public function _resetState(): void // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- framework interface
    {
        $this->prefetched = [];
    }
}
