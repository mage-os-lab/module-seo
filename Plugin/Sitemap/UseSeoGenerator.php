<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Sitemap;

use Magento\Sitemap\Model\Sitemap;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Sitemap\Generator;

/**
 * Hands sitemap generation to this module's generator when it is selected.
 *
 * On `generateXml()`, the one method every route into generation calls — the admin "Generate"
 * button, core's cron, and core's batch cron, whose sitemap class extends the one this plugin is
 * declared on and so inherits it. With Magento's generator selected, core runs untouched.
 */
class UseSeoGenerator
{
    /**
     * @param Config $config
     * @param Generator $generator
     */
    public function __construct(
        private readonly Config    $config,
        private readonly Generator $generator
    ) {
    }

    /**
     * Generate with this module's generator, or let core generate.
     *
     * @param Sitemap $subject
     * @param callable $proceed
     * @return Sitemap
     */
    public function aroundGenerateXml(Sitemap $subject, callable $proceed): Sitemap
    {
        if (!$this->config->isSitemapGeneratorEnabled((int) $subject->getStoreId())) {
            return $proceed();
        }

        $this->generator->generate($subject);

        return $subject;
    }
}
