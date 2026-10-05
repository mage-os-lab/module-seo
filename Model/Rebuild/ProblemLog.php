<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Rebuild;

use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * What is wrong with the pre-generated files right now: the sitemaps and every group registered in
 * HandlerPool (MageOS_Aeo's llms documents, for one).
 *
 * The rebuilds run in the queue consumer and the cron, where a failure only reaches the log, so a
 * file could stay out of date for weeks with nobody knowing. This keeps the current problems where
 * the admin shows them (Model\System\Message\RebuildProblems, and once each in the inbox).
 *
 * A problem is per group and per ID, a store view or a sitemap:
 * - **failed**: the rebuild of that ID threw. The previous file is still served;
 * - **degraded**: the file was written, but with something missing. A builder reports it with
 *   degraded() while the rebuild runs;
 * - **stalled**: the queue has not picked up a rebuild for over an hour (group `queue`).
 *
 * Each rebuild covers its whole group, so its result replaces the group's problems: a clean
 * rebuild clears them. A rebuild is bracketed by rebuilding() and rebuilt() (or abandoned()), and
 * brackets nest: the consumer brackets the group it hands a handler, the handler may bracket the
 * same group itself, and only the outermost bracket writes, with what both found. degraded() outside
 * any bracket, from a storefront request say, is ignored: the log has it.
 *
 * A failure's reason is kept as thrown: GroupHandlerInterface::rebuild() returns plain messages. A
 * degradation's reason is a phrase, kept untranslated and translated when the admin is shown it.
 *
 * Stored in one flag. Its read-modify-write is guarded by a lock, because the consumer, the cron
 * and the command can finish at the same moment, and it is written only when something changed.
 *
 * @phpstan-type Entry array{kind: string, reason: string, arguments: string[]|null, since: int|null}
 * @phpstan-type NewEntry array{kind: string, reason: string, arguments: string[]|null, since?: int|null}
 */
class ProblemLog implements ResetAfterRequestInterface
{
    public const FLAG = 'mageos_seo_rebuild_problems';

    public const KIND_FAILED   = 'failed';
    public const KIND_DEGRADED = 'degraded';
    public const KIND_STALLED  = 'stalled';

    /**
     * The group stalled rebuild requests are kept under, keyed by the group that stalled.
     */
    public const GROUP_QUEUE = 'queue';

    /**
     * The ID of a problem with the whole group, rather than one store view or sitemap.
     */
    public const ALL = 'all';

    private const LOCK = 'mageos_seo_rebuild_problems';

    private const LOCK_TIMEOUT = 10;

    /**
     * Rebuilds open in this process, per group: bracket depth, failures and degradations so far.
     *
     * @var array<string, array{depth: int, failures: array<string, string>, degraded: array<string, Phrase>}>
     */
    private array $open = [];

    /**
     * @param FlagManager $flagManager
     * @param LockManagerInterface $lockManager
     * @param NotifierInterface $notifier
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param ProblemFormatter $formatter
     */
    public function __construct(
        private readonly FlagManager          $flagManager,
        private readonly LockManagerInterface $lockManager,
        private readonly NotifierInterface    $notifier,
        private readonly DateTime             $dateTime,
        private readonly LoggerInterface      $logger,
        private readonly ProblemFormatter     $formatter
    ) {
    }

    /**
     * A rebuild of the group starts in this process.
     *
     * @param string $group
     * @return void
     */
    public function rebuilding(string $group): void
    {
        if (isset($this->open[$group])) {
            $this->open[$group]['depth']++;
            return;
        }
        $this->open[$group] = ['depth' => 1, 'failures' => [], 'degraded' => []];
    }

    /**
     * The file for an ID of a group being rebuilt was written, but with something missing.
     *
     * @param string $group
     * @param int|string $id A store view or sitemap ID, or self::ALL
     * @param Phrase $reason What is missing, and why, as a sentence
     * @return void
     */
    public function degraded(string $group, int|string $id, Phrase $reason): void
    {
        if (isset($this->open[$group])) {
            $this->open[$group]['degraded'][(string) $id] = $reason;
        }
    }

    /**
     * Something that degrades whichever groups are being rebuilt now as a whole, such as their storage.
     *
     * @param Phrase $reason
     * @return void
     */
    public function degradedWhileRebuilding(Phrase $reason): void
    {
        foreach (array_keys($this->open) as $group) {
            $this->degraded($group, self::ALL, $reason);
        }
    }

    /**
     * A rebuild of the group finished; for the outermost bracket, its result replaces the group's.
     *
     * @param string $group
     * @param array<int|string,string> $failures Error message per store view or sitemap ID that failed
     * @return void
     */
    public function rebuilt(string $group, array $failures): void
    {
        // A result with no bracket open is a bracket of its own.
        $open = $this->open[$group] ?? ['depth' => 1, 'failures' => [], 'degraded' => []];
        foreach ($failures as $id => $message) {
            $open['failures'][(string) $id] = (string) $message;
        }
        if (--$open['depth'] > 0) {
            $this->open[$group] = $open;
            return;
        }
        unset($this->open[$group]);

        $entries = [];
        foreach ($open['failures'] as $id => $message) {
            $entries[$id] = $this->failed($message);
        }
        foreach ($open['degraded'] as $id => $reason) {
            $entries[$id] ??= [
                'kind'      => self::KIND_DEGRADED,
                'reason'    => $reason->getText(),
                // Keys kept: a named placeholder (%name) is looked up by its key.
                'arguments' => array_map('strval', $reason->getArguments()),
            ];
        }

        $this->change(fn (): array => [$group => $entries]);
    }

    /**
     * A rebuild of the group stopped without a result, so nothing is recorded.
     *
     * Another process holds the group's lock, and will record its own result.
     *
     * @param string $group
     * @return void
     */
    public function abandoned(string $group): void
    {
        if (isset($this->open[$group]) && --$this->open[$group]['depth'] <= 0) {
            unset($this->open[$group]);
        }
    }

    /**
     * One ID was rebuilt whole, outside the group rebuilds.
     *
     * A sitemap written by core's cron or the admin's Generate button, say: that settles its problems
     * in every group $settles selects. A failure is recorded under $group.
     *
     * @param string $group
     * @param int|string $id
     * @param string|null $failure The error message, or null when it was rebuilt
     * @param callable $settles Given a group, whether its problem with the ID is settled by this
     * @return void
     */
    public function rebuiltWhole(string $group, int|string $id, ?string $failure, callable $settles): void
    {
        $id = (string) $id;

        $this->change(function (array $state) use ($group, $id, $failure, $settles): array {
            $changes = [];
            foreach ($state as $each => $entries) {
                if (($each === $group || $settles((string) $each)) && isset($entries[$id])) {
                    unset($entries[$id]);
                    $changes[$each] = $entries;
                }
            }
            if ($failure !== null) {
                $changes[$group] = ($changes[$group] ?? $state[$group] ?? []) + [$id => $this->failed($failure)];
            }

            return $changes;
        });
    }

    /**
     * A rebuild of the group was requested an hour or more ago and never picked up.
     *
     * @param string $group
     * @param int|null $queuedAt When the request was queued, or null when that is unknown
     * @return void
     */
    public function stalled(string $group, ?int $queuedAt): void
    {
        $this->change(function (array $state) use ($group, $queuedAt): array {
            $entries         = $state[self::GROUP_QUEUE] ?? [];
            $entries[$group] = [
                'kind'      => self::KIND_STALLED,
                'reason'    => '',
                'arguments' => null,
                'since'     => $queuedAt,
            ];

            return [self::GROUP_QUEUE => $entries];
        });
    }

    /**
     * The queue is being processed again: whatever had stalled is being picked up.
     *
     * @return void
     */
    public function resumed(): void
    {
        if (($this->all()[self::GROUP_QUEUE] ?? []) !== []) {
            $this->change(fn (): array => [self::GROUP_QUEUE => []]);
        }
    }

    /**
     * The current problems: group => ID => kind, reason and since when.
     *
     * The reason is a failure's message, or with arguments, the text of a phrase to translate. Since
     * when is a timestamp: for a stalled request, when it was queued, or null when that is unknown.
     *
     * @return array<string, array<int|string, Entry>> IDs that are numbers are integer keys, as in any PHP array
     */
    public function all(): array
    {
        try {
            $state = $this->flagManager->getFlagData(self::FLAG);
        } catch (\Exception $e) {
            $this->logger->error('MageOS_Seo: could not read the rebuild problems: ' . $e->getMessage());
            return [];
        }

        return \is_array($state) ? $state : [];
    }

    /**
     * @inheritdoc
     */
    public function _resetState(): void
    {
        $this->open = [];
    }

    /**
     * A failure's entry.
     *
     * @param string $message
     * @return array{kind: string, reason: string, arguments: null}
     */
    private function failed(string $message): array
    {
        return ['kind' => self::KIND_FAILED, 'reason' => $message, 'arguments' => null];
    }

    /**
     * Change the stored problems under the lock.
     *
     * $change is given the current problems and returns the new entries of each group it changes.
     * A continuing problem keeps when it began; a new one starts now, unless its entry says when, and
     * goes to the admin inbox. Failing to record never fails the rebuild: the log still has it.
     *
     * @param callable(array<string,array<int|string,Entry>>):array<string,array<int|string,NewEntry>> $change
     * @return void
     */
    private function change(callable $change): void
    {
        try {
            if (!$this->lockManager->lock(self::LOCK, self::LOCK_TIMEOUT)) {
                $this->logger->warning('MageOS_Seo: rebuild problems not recorded: lock busy.');
                return;
            }
        } catch (\Exception $e) {
            $this->logger->error('MageOS_Seo: could not lock the rebuild problems: ' . $e->getMessage());
            return;
        }

        try {
            $state   = $this->all();
            $now     = (int) $this->dateTime->gmtTimestamp();
            $changed = false;
            $added   = [];

            foreach ($change($state) as $group => $entries) {
                $previous = $state[$group] ?? [];
                $next     = [];
                foreach ($entries as $id => $entry) {
                    $before = $previous[$id] ?? null;
                    if ($before !== null && $before['kind'] === $entry['kind']) {
                        $entry['since'] = $before['since'];
                    } else {
                        $entry['since']     = \array_key_exists('since', $entry) ? $entry['since'] : $now;
                        $added[$group][$id] = $entry;
                    }
                    $next[$id] = $entry;
                }
                if ($next === $previous) {
                    continue;
                }

                $changed = true;
                if ($next === []) {
                    unset($state[$group]);
                } else {
                    $state[$group] = $next;
                }
            }

            if ($changed) {
                $this->flagManager->saveFlag(self::FLAG, $state);
            }
        } catch (\Exception $e) {
            $this->logger->error('MageOS_Seo: could not record the rebuild problems: ' . $e->getMessage());
            return;
        } finally {
            $this->lockManager->unlock(self::LOCK);
        }

        if ($added !== []) {
            $this->notify($added);
        }
    }

    /**
     * Put new problems in the admin inbox, once, worded as the System Messages bar words them.
     *
     * Inbox entries are stored text, so they are in the language this process has loaded: the
     * cron's is the default admin language, the queue consumer loads none and writes English.
     *
     * @param array<string,array<int|string,Entry>> $added
     * @return void
     */
    private function notify(array $added): void
    {
        try {
            $labels = [];
            $lines  = [];
            foreach ($added as $group => $entries) {
                foreach ($entries as $id => $entry) {
                    $subject          = $group === self::GROUP_QUEUE ? (string) $id : (string) $group;
                    $labels[$subject] = $this->formatter->label($subject);
                    $lines[]          = $this->formatter->line((string) $group, (string) $id, $entry, false);
                }
            }

            $this->notifier->addMajor(
                (string) __('Some SEO files are out of date: %1', implode(', ', $labels)),
                implode("\n", $lines),
                'https://github.com/mage-os-lab/module-seo/blob/main/docs/rebuild-problems.md'
            );
        } catch (\Exception $e) {
            $this->logger->error('MageOS_Seo: could not add the rebuild problems to the inbox: ' . $e->getMessage());
        }
    }
}
