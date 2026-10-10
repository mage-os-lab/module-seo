<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Migration;

/**
 * Carries a retired module's settings into this module.
 *
 * An import never replaces a value already set in this module. It fills only what is empty, and
 * reports each value it kept, so running it again is safe and finds nothing new to write. Whether
 * it has finished is recorded by LegacyImport, not by the importer.
 */
interface LegacyImporterInterface
{
    /**
     * Whether the retired module left anything in the database this importer reads.
     *
     * @return bool
     */
    public function hasLegacyData(): bool;

    /**
     * Import what the retired module left.
     *
     * @param bool $dryRun Work out and report what would be written, and write nothing
     * @return array<string, mixed> The report, with `notes`: the values kept and the input dropped
     */
    public function import(bool $dryRun = false): array;

    /**
     * What a report says was written, or on a dry run would be, a line per kind of change.
     *
     * @param array<string,mixed> $report As import() returned it
     * @param bool $dryRun
     * @return string[] Empty when nothing was or would be written
     */
    public function describe(array $report, bool $dryRun): array;
}
