<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\RobotsMeta\DirectiveComposer;
use MageOS\Seo\Model\RobotsMeta\Resolver;

/**
 * Single applier for the robots meta pool.
 *
 * Runs once per frontend page on layout_generate_blocks_after — after the controller has populated
 * the registry/layer and before the head is rendered — so any page type with a matching provider is
 * covered automatically, with no per-controller plugin. When no provider has an opinion the page
 * keeps Magento's default robots behaviour.
 *
 * The resolved directive is composed with what is already on the page rather than written over
 * it: restrictions another module added survive. See DirectiveComposer for the rule.
 */
class ApplyRobotsMeta implements ObserverInterface
{
    /**
     * The config path core seeds the page's robots value from; see PageConfig::getRobots().
     */
    private const CORE_DEFAULT_ROBOTS = 'design/search_engine_robots/default_robots';

    /**
     * @param Resolver $resolver
     * @param PageConfig $pageConfig
     * @param StoreManagerInterface $storeManager
     * @param DirectiveComposer $composer
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly Resolver              $resolver,
        private readonly PageConfig            $pageConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly DirectiveComposer     $composer,
        private readonly ScopeConfigInterface  $scopeConfig
    ) {
    }

    /**
     * Apply the resolved robots meta value to the current frontend page.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $robots  = $this->resolver->resolve($storeId);
            if ($robots === null || $robots === '') {
                return;
            }

            $coreDefault = (string) $this->scopeConfig->getValue(
                self::CORE_DEFAULT_ROBOTS,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );

            $this->pageConfig->setRobots(
                $this->composer->compose($robots, (string) $this->pageConfig->getRobots(), $coreDefault)
            );
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- never break rendering
        }
    }
}
