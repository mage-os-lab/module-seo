<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Rebuild;

use Magento\Framework\Phrase;

/**
 * What a rebuild reports about the documents it writes, for the admin's System Message and the
 * notifications inbox.
 *
 * A rebuild is a bracket: rebuilding() opens it, rebuilt() closes it with the failures, and what was
 * written with something missing is reported with degraded() in between. The outermost bracket's
 * result replaces what was recorded for the group before, so a clean rebuild clears it.
 *
 * @api
 */
interface ProblemLogInterface
{
    /**
     * The ID that stands for the whole group rather than one store view or file.
     */
    public const ALL = 'all';

    /**
     * A rebuild of the group starts in this process.
     *
     * @param string $group
     * @return void
     */
    public function rebuilding(string $group): void;

    /**
     * The file for an ID of a group being rebuilt was written, but with something missing.
     *
     * @param string $group
     * @param int|string $id A store view or file ID, or self::ALL
     * @param Phrase $reason What is missing, and why, as a sentence
     * @return void
     */
    public function degraded(string $group, int|string $id, Phrase $reason): void;

    /**
     * Something degrades whichever groups are being rebuilt now as a whole, such as their storage.
     *
     * @param Phrase $reason
     * @return void
     */
    public function degradedWhileRebuilding(Phrase $reason): void;

    /**
     * A rebuild of the group finished; for the outermost bracket, its result replaces the group's.
     *
     * @param string $group
     * @param array<int|string,string> $failures Error message per store view or file ID that failed
     * @return void
     */
    public function rebuilt(string $group, array $failures): void;
}
