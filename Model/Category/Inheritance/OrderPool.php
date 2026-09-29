<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Category\Inheritance;

use MageOS\Seo\Api\CategoryConfigSourceOrderInterface;

/**
 * The source-order strategies this installation knows about, keyed by their configuration value.
 *
 * Populated from di.xml, so a third party adds a strategy by registering it here: it becomes
 * selectable in Stores → Configuration without any change to the admin field, because the source
 * model that renders the dropdown reads this pool.
 */
class OrderPool
{
    /**
     * Typed as mixed[] because that is what arrives.
     *
     * The array is assembled by di.xml, which names classes as strings and enforces nothing about
     * them: a module can register something that does not implement the interface, or a class
     * that once did and no longer does, and object-manager configuration will happily build it.
     * Declaring the element type here would describe an intention rather than a fact, and would
     * make the checks below look redundant when they are the only thing standing between a
     * mis-registered strategy and a fatal in the middle of a page render.
     *
     * @param mixed[] $strategies Keyed by the configuration value
     */
    public function __construct(
        private readonly array $strategies = []
    ) {
    }

    /**
     * The strategy registered under a code.
     *
     * @param string $code
     * @return CategoryConfigSourceOrderInterface
     * @throws \RuntimeException When nothing is registered under that code
     */
    public function get(string $code): CategoryConfigSourceOrderInterface
    {
        $strategy = $this->strategies[$code] ?? null;

        if (!$strategy instanceof CategoryConfigSourceOrderInterface) {
            // Deliberately loud. Falling back to the default would resolve every category's
            // settings by a rule the merchant did not choose, and silently: the pages would
            // render, with different content, and nothing would say why. Configuration naming a
            // strategy that is not installed is a deployment fault.
            throw new \RuntimeException(sprintf(
                'MageOS_Seo: no category inheritance strategy is registered as "%s". Registered: %s.',
                $code,
                $this->codes() === [] ? 'none' : implode(', ', $this->codes())
            ));
        }

        return $strategy;
    }

    /**
     * Every registered strategy, keyed by code.
     *
     * @return array<string, CategoryConfigSourceOrderInterface>
     */
    public function getAll(): array
    {
        $registered = [];

        foreach ($this->strategies as $code => $strategy) {
            if ($strategy instanceof CategoryConfigSourceOrderInterface) {
                $registered[(string) $code] = $strategy;
            }
        }

        return $registered;
    }

    /**
     * The codes of every registered strategy.
     *
     * @return string[]
     */
    private function codes(): array
    {
        return array_keys($this->getAll());
    }
}
