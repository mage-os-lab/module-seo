<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\System\Message;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use MageOS\Seo\Model\Rebuild\ProblemFormatter;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use MageOS\Seo\Model\Sitemap\RebuildGroup;

/**
 * The admin's System Messages bar, while a pre-generated file is out of date: a sitemap, or a
 * group another module registered (MageOS_Aeo's llms documents).
 *
 * Shown while Model\Rebuild\ProblemLog has problems, and gone once the next rebuild of the group
 * succeeds, as core's "One or more indexers are invalid" is. Only to admins who may manage SEO.
 *
 * Most admins have no server access, so each line says when the files are retried without anyone
 * acting, and what an admin can do in the admin (Generate, for a Site Map) comes before the
 * command a developer runs.
 */
class RebuildProblems implements MessageInterface
{
    private const ACL_RESOURCE = 'MageOS_Seo::seo';

    /**
     * Problems listed; the rest are counted.
     */
    private const LISTED = 5;

    /**
     * @param ProblemLog $problemLog
     * @param ProblemFormatter $formatter
     * @param AuthorizationInterface $authorization
     * @param RebuildGroup $rebuildGroup
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly ProblemLog             $problemLog,
        private readonly ProblemFormatter       $formatter,
        private readonly AuthorizationInterface $authorization,
        private readonly RebuildGroup           $rebuildGroup,
        private readonly Escaper                $escaper
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getIdentity()
    {
        $problems = [];
        foreach ($this->problemLog->all() as $group => $entries) {
            foreach ($entries as $id => $entry) {
                $problems[] = $group . '/' . $id . '/' . $entry['kind'];
            }
        }

        // phpcs:ignore Magento2.Security.InsecureFunction -- an identity, as core's messages use, not a secret
        return md5('MAGEOS_SEO_REBUILD_PROBLEMS|' . implode('|', $problems));
    }

    /**
     * @inheritdoc
     */
    public function isDisplayed()
    {
        return $this->authorization->isAllowed(self::ACL_RESOURCE) && $this->problemLog->all() !== [];
    }

    /**
     * @inheritdoc
     */
    public function getText()
    {
        $lines    = [];
        $count    = 0;
        $groups   = [];
        $stalled  = false;
        $sitemaps = false;
        foreach ($this->problemLog->all() as $group => $entries) {
            foreach ($entries as $id => $entry) {
                $subject  = $group === ProblemLog::GROUP_QUEUE ? (string) $id : (string) $group;
                $stalled  = $stalled || $group === ProblemLog::GROUP_QUEUE;
                $sitemaps = $sitemaps || $this->isSitemapGroup($subject);
                $groups[$subject] = $subject;

                if (++$count <= self::LISTED) {
                    $lines[] = $this->formatter->line((string) $group, (string) $id, $entry, true);
                }
            }
        }
        if ($count > self::LISTED) {
            $lines[] = $this->escaper->escapeHtml((string) __('…and %1 more.', $count - self::LISTED));
        }

        $commands = [];
        foreach ($groups as $group) {
            $command = $this->formatter->command($group);
            if ($command !== null) {
                $commands[] = '<code>' . $this->escaper->escapeHtml($command) . '</code>';
            }
        }

        $after = [(string) __('This message disappears by itself once the files are rebuilt.')];
        if ($sitemaps) {
            $after[] = (string) __('A Site Map can also be generated again under Marketing → Site Map.');
        }
        $after[] = (string) __(
            'To rebuild sooner, a developer with access to the server can run %1.',
            implode(', ', $commands)
        );
        if ($stalled) {
            $after[] = (string) __(
                'The queue is not running: a developer should check that the mageosSeoFeedRegenerate'
                . ' consumer is started.'
            );
        }
        $after[] = (string) __('The system log has the details.');

        return '<strong>' . $this->escaper->escapeHtml((string) __('Some SEO files are out of date.')) . '</strong>'
            . '<ul><li>' . implode('</li><li>', $lines) . '</li></ul>'
            . '<p>' . implode(' ', $after) . '</p>';
    }

    /**
     * @inheritdoc
     */
    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }

    /**
     * Whether the group is a sitemap group, which the admin's Generate button also rebuilds.
     *
     * @param string $group
     * @return bool
     */
    private function isSitemapGroup(string $group): bool
    {
        return $group === RebuildGroup::MISSING || $this->rebuildGroup->typeOf($group) !== null;
    }
}
