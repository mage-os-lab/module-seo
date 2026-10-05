<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Rebuild;

use Magento\Framework\Phrase;

/**
 * How the admin is told about a handler's groups when their files are out of date.
 *
 * Optional: a GroupHandlerInterface that also implements this is shown by its label, with the time
 * its cron job next retries the group. One that does not is shown by its group name, retried when
 * its content next changes. See Model\System\Message\RebuildProblems.
 *
 * @api
 */
interface GroupDescriptionInterface
{
    /**
     * What the group's files are called, for an admin: `llms.jsonl`, say. Not the group name.
     *
     * @param string $group One of the handler's groups
     * @return Phrase
     */
    public function getLabel(string $group): Phrase;

    /**
     * The cron job (its name in crontab.xml) that rebuilds the group on a schedule, or null.
     *
     * Its next run is shown as when a failed rebuild is retried, so the job must rebuild the group
     * through the handler, recording the result as Model\Rebuild\ProblemLog describes.
     *
     * @param string $group One of the handler's groups
     * @return string|null
     */
    public function getScheduledJob(string $group): ?string;
}
