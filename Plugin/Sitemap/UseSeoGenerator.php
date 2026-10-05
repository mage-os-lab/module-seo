<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Sitemap;

use Magento\Sitemap\Model\Sitemap;
use MageOS\Seo\Exception\SitemapRebuildInProgressException;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use MageOS\Seo\Model\Sitemap\Generator;
use MageOS\Seo\Model\Sitemap\RebuildGroup;

/**
 * Hands sitemap generation to this module's generator when it is selected.
 *
 * On `generateXml()`, the one method every route into generation calls — the admin "Generate"
 * button, core's cron, and core's batch cron, whose sitemap class extends the one this plugin is
 * declared on and so inherits it. With Magento's generator selected, core runs untouched.
 *
 * Each of these writes the whole sitemap, so its result settles that sitemap's rebuild problems in
 * every sitemap group (see ProblemLog): this is how core's cron and the Generate button clear the
 * admin's message, as the queue's rebuilds do.
 */
class UseSeoGenerator
{
    /**
     * @param Config $config
     * @param Generator $generator
     * @param ProblemLog $problemLog
     * @param RebuildGroup $rebuildGroup
     */
    public function __construct(
        private readonly Config       $config,
        private readonly Generator    $generator,
        private readonly ProblemLog   $problemLog,
        private readonly RebuildGroup $rebuildGroup
    ) {
    }

    /**
     * Generate with this module's generator, or let core generate.
     *
     * @param Sitemap $subject
     * @param callable $proceed
     * @throws \Throwable What the generator threw, once recorded
     * @return Sitemap
     */
    public function aroundGenerateXml(Sitemap $subject, callable $proceed): Sitemap
    {
        if (!$this->config->isSitemapGeneratorEnabled((int) $subject->getStoreId())) {
            return $proceed();
        }

        try {
            $this->generator->generate($subject);
        } catch (SitemapRebuildInProgressException $e) {
            // Another process is writing it, and will record its own result.
            throw $e;
        } catch (\Throwable $e) {
            $this->settle($subject, $e->getMessage());
            throw $e;
        }
        $this->settle($subject, null);

        return $subject;
    }

    /**
     * Record the whole sitemap's result; a failure under the all-types group.
     *
     * @param Sitemap $sitemap
     * @param string|null $failure
     * @return void
     */
    private function settle(Sitemap $sitemap, ?string $failure): void
    {
        $this->problemLog->rebuiltWhole(
            $this->rebuildGroup->forType(RebuildGroup::ALL_TYPES),
            (int) $sitemap->getId(),
            $failure,
            fn (string $group): bool => $group === RebuildGroup::MISSING
                || $this->rebuildGroup->typeOf($group) !== null
        );
    }
}
