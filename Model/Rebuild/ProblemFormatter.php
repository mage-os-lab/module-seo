<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Rebuild;

use Magento\Framework\Escaper;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\Rebuild\GroupDescriptionInterface;
use MageOS\Seo\Api\Sitemap\ItemProviderInterface;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
use MageOS\Seo\Model\Sitemap\SitemapFile;

/**
 * A rebuild problem (see ProblemLog) in words an admin can act on: the System Messages bar shows it
 * as HTML, the inbox as plain text.
 *
 * A line names the files by their label, says which store or Site Map, since when (in the admin's
 * language and the configured timezone), when it is retried without anyone acting (RetrySchedule)
 * and why. The group name only appears in the command a developer runs; see command().
 *
 * @phpstan-import-type Entry from ProblemLog
 */
class ProblemFormatter
{
    /**
     * @param HandlerPool $handlerPool
     * @param RebuildGroup $rebuildGroup
     * @param RetrySchedule $retrySchedule
     * @param StoreManagerInterface $storeManager
     * @param SitemapFile $sitemapFile
     * @param TimezoneInterface $timezone
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly HandlerPool           $handlerPool,
        private readonly RebuildGroup          $rebuildGroup,
        private readonly RetrySchedule         $retrySchedule,
        private readonly StoreManagerInterface $storeManager,
        private readonly SitemapFile           $sitemapFile,
        private readonly TimezoneInterface     $timezone,
        private readonly Escaper               $escaper
    ) {
    }

    /**
     * One problem as a line: HTML-escaped for the bar, or plain text.
     *
     * @param string $group
     * @param string $id
     * @param Entry $entry
     * @param bool $html
     * @return string
     */
    public function line(string $group, string $id, array $entry, bool $html): string
    {
        $text = fn (string|Phrase $value): string => $html
            ? $this->escaper->escapeHtml((string) $value)
            : (string) $value;

        if ($entry['kind'] === ProblemLog::KIND_STALLED) {
            // Under ProblemLog::GROUP_QUEUE, keyed by the group whose request stalled.
            $label = $text($this->label($id));

            return implode(' ', [
                $entry['since'] === null
                    ? __('%1: changes are waiting, because the queue that rebuilds them is not running.', $label)
                    : __(
                        '%1: changes since %2 are waiting, because the queue that rebuilds them is not running.',
                        $label,
                        $text($this->date($entry['since']))
                    ),
                $this->stalledRetry($id, $text),
            ]);
        }

        $label = $text($this->label($group));
        $where = $text($this->where($group, $id));
        $since = $text($this->date((int) $entry['since']));

        return implode(' ', [
            $entry['kind'] === ProblemLog::KIND_DEGRADED
                ? __('%1 for %2 is incomplete (since %3).', $label, $where, $since)
                : __(
                    '%1 for %2 could not be rebuilt (since %3). The previous version is still online.',
                    $label,
                    $where,
                    $since
                ),
            $this->retry($group, $text),
            __('Reason: %1', $text($this->reason($entry))),
        ]);
    }

    /**
     * What the group's files are called, for an admin.
     *
     * @param string $group
     * @return string
     */
    public function label(string $group): string
    {
        if ($group === RebuildGroup::MISSING) {
            return (string) __('Site Map (first build)');
        }

        $type = $this->rebuildGroup->typeOf($group);
        if ($type !== null) {
            return (string) match ($type) {
                RebuildGroup::ALL_TYPES                => __('Site Map (all files)'),
                ItemProviderInterface::TYPE_PAGES      => __('Site Map CMS pages'),
                ItemProviderInterface::TYPE_CATEGORIES => __('Site Map categories'),
                ItemProviderInterface::TYPE_PRODUCTS   => __('Site Map products'),
                ItemProviderInterface::TYPE_OTHER      => __('Site Map other links'),
                default                                => __('Site Map %1', $type),
            };
        }

        $handler = $this->handlerPool->get($group);

        return $handler instanceof GroupDescriptionInterface ? (string) $handler->getLabel($group) : $group;
    }

    /**
     * The command a developer runs to rebuild the group now, or null when there is none.
     *
     * The stalled-queue group has none of its own: its entries name the groups whose requests stalled.
     *
     * @param string $group
     * @return string|null
     */
    public function command(string $group): ?string
    {
        if ($group === ProblemLog::GROUP_QUEUE) {
            return null;
        }

        return 'bin/magento seo:rebuild -g '
            . (preg_match('/^[a-z0-9_-]+$/', $group) === 1 ? $group : "'" . $group . "'");
    }

    /**
     * The store or Site Map an ID of the group names.
     *
     * @param string $group
     * @param string $id
     * @return Phrase|string
     */
    private function where(string $group, string $id): Phrase|string
    {
        $sitemaps = $group === RebuildGroup::MISSING || $this->rebuildGroup->typeOf($group) !== null;
        if ($id === ProblemLog::ALL) {
            return $sitemaps ? __('all Site Maps') : __('every store');
        }
        if (!ctype_digit($id)) {
            return $id;
        }

        return $sitemaps ? $this->sitemap((int) $id) : $this->store((int) $id);
    }

    /**
     * A store view, by its name.
     *
     * @param int $storeId
     * @return Phrase
     */
    private function store(int $storeId): Phrase
    {
        try {
            $name = (string) $this->storeManager->getStore($storeId)->getName();
        } catch (\Exception) {
            $name = '';
        }

        // A store view deleted since the problem was recorded keeps its ID.
        return $name !== '' ? __('store "%1"', $name) : __('store ID %1', $storeId);
    }

    /**
     * A Site Map entry, by its file as Marketing → Site Map lists it.
     *
     * @param int $sitemapId
     * @return Phrase
     */
    private function sitemap(int $sitemapId): Phrase
    {
        try {
            $path = $this->sitemapFile->pathOf($sitemapId);
        } catch (\Exception) {
            $path = null;
        }

        // An entry deleted since the problem was recorded keeps its ID.
        return $path !== null ? __('"%1"', $path) : __('Site Map ID %1', $sitemapId);
    }

    /**
     * When a failed or incomplete group is retried without anyone acting.
     *
     * @param string $group
     * @param callable $text Escapes what is put in the line, for the bar
     * @return Phrase
     */
    private function retry(string $group, callable $text): Phrase
    {
        $next = $this->retrySchedule->nextAttempt($group);

        return $next === null
            ? __('It will be retried automatically when the content changes.')
            : __('It will be retried automatically at %1.', $text($this->date($next)));
    }

    /**
     * When the changes a stalled queue holds still reach the files.
     *
     * @param string $group
     * @param callable $text Escapes what is put in the line, for the bar
     * @return Phrase
     */
    private function stalledRetry(string $group, callable $text): Phrase
    {
        $next = $this->retrySchedule->nextAttempt($group);

        return $next === null
            ? __('They will be included once the queue runs again.')
            : __('They will still be included at the next scheduled rebuild, at %1.', $text($this->date($next)));
    }

    /**
     * Why: a failure's message as it was thrown, or a builder's reason in the admin's language.
     *
     * @param Entry $entry
     * @return string
     */
    private function reason(array $entry): string
    {
        // Through a variable: given a literal key, i18n:collect-phrases takes it for a phrase.
        $text = $entry['reason'];

        return $entry['arguments'] === null ? $text : (string) new Phrase($text, $entry['arguments']);
    }

    /**
     * A time as the admin reads it: their language, the configured timezone.
     *
     * @param int $timestamp
     * @return string
     */
    private function date(int $timestamp): string
    {
        return $this->timezone->formatDateTime(
            new \DateTimeImmutable('@' . $timestamp),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );
    }
}
