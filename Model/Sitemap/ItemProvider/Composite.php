<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\ItemProvider;

use Magento\Sitemap\Model\ItemProvider\Composite as CoreComposite;
use Magento\Sitemap\Model\ItemProvider\ItemProviderInterface as CoreItemProviderInterface;

/**
 * Core's provider composite, able to hand its providers out one by one.
 *
 * Core's composite keeps its providers private and only ever returns all their items merged into
 * one list. The generator in this module needs them individually — to stream each one and to file
 * its items by type — so this class is the preference for core's `ItemProviderInterface`, where
 * core itself points at its composite.
 *
 * Providers are registered on core's composite, as they always have been: type configuration is
 * inherited, so whatever is registered there — core's four (replaced by this module's wrappers in
 * di.xml), and any other module's — arrives here too. `getItems()` is core's, unchanged, so core's
 * generator sees the same list it always did.
 */
class Composite extends CoreComposite
{
    /**
     * @var array<string,CoreItemProviderInterface>
     */
    private array $providers;

    /**
     * @param array<string,CoreItemProviderInterface> $itemProviders
     */
    public function __construct($itemProviders = [])
    {
        parent::__construct($itemProviders);
        $this->providers = $itemProviders;
    }

    /**
     * Every registered provider, keyed by the name it was registered under.
     *
     * @return array<string,CoreItemProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }
}
