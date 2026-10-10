<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Migration;

use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsPageConfigRepository;
use MageOS\Seo\Model\PageCache\Purger;
use MageOS\Seo\Model\Product\OverrideRepository;

/**
 * Carries MageOS_MetaRobotsTag's per-entity flags into this module's robots settings.
 *
 * That module stores flags; this module stores a whole directive. Each flagged entity gets the
 * directive its flags gave it on each store view it is on (MetaRobotsTagDirectives):
 * - **One global row** when every one of those store views was served the same directive.
 * - **Otherwise, a row per store view** that had a flag. A store view without one keeps following
 *   its store setting, as it did.
 *
 * Only entities with at least one flag are touched: a flagless entity was never overridden, and
 * writing a directive for it would turn "follow the store setting" into a fixed value.
 *
 * **A value already set in this module is never replaced** (issue #26): the store view's own row, or
 * the global row the store view inherits. Each one kept is reported with both directives. A
 * category's ancestors do not count: MageOS_MetaRobotsTag's flags were never inherited, so an
 * inherited value here is not one the flag competed with.
 *
 * Safe to run when MageOS_MetaRobotsTag was never installed: its attributes and columns are not
 * there, and there is nothing to do. Safe to run again: what it wrote is no longer empty.
 */
class MetaRobotsTagImporter implements LegacyImporterInterface
{
    /**
     * Entities whose robots are read per query.
     */
    private const CHUNK = 500;

    /**
     * @param MetaRobotsTagDirectives $directives
     * @param OverrideRepository $productOverrideRepository
     * @param CategoryConfigRepository $categoryConfigRepository
     * @param CmsPageConfigRepository $cmsPageConfigRepository
     * @param Purger $purger
     */
    public function __construct(
        private readonly MetaRobotsTagDirectives  $directives,
        private readonly OverrideRepository       $productOverrideRepository,
        private readonly CategoryConfigRepository $categoryConfigRepository,
        private readonly CmsPageConfigRepository  $cmsPageConfigRepository,
        private readonly Purger                   $purger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function hasLegacyData(): bool
    {
        return $this->directives->exist();
    }

    /**
     * Import every flagged product, category and CMS page.
     *
     * One page cache purge for the whole import, not one per row written: a catalogue can carry
     * thousands of flags. Reports the entities given at least one row, by type, and the values kept.
     *
     * @param bool $dryRun
     * @return array{written: array{product: int, category: int, cms_page: int}, notes: string[]}
     */
    public function import(bool $dryRun = false): array
    {
        $notes   = [];
        $written = $this->purger->batch(function () use ($dryRun, &$notes): array {
            return [
                'product'  => $this->importEntities(
                    'Product',
                    $this->directives->products(),
                    fn (array $ids, int $storeId): array => $this->robots(
                        $this->productOverrideRepository->getForProducts($ids, $storeId)
                    ),
                    fn (int $id, int $storeId, string $directive) => $this->productOverrideRepository
                        ->save($id, $storeId, ['robots_meta' => $directive]),
                    $dryRun,
                    $notes
                ),
                'category' => $this->importEntities(
                    'Category',
                    $this->directives->categories(),
                    fn (array $ids, int $storeId): array => $this->categoryRobots($ids, $storeId),
                    fn (int $id, int $storeId, string $directive) => $this->categoryConfigRepository
                        ->save($id, ['robots_meta' => $directive], $storeId),
                    $dryRun,
                    $notes
                ),
                'cms_page' => $this->importEntities(
                    'CMS page',
                    $this->directives->cmsPages(),
                    fn (array $ids, int $storeId): array => $this->robots(
                        $this->cmsPageConfigRepository->getForPages($ids, $storeId)
                    ),
                    fn (int $id, int $storeId, string $directive) => $this->cmsPageConfigRepository
                        ->save($id, ['robots_meta' => $directive], $storeId),
                    $dryRun,
                    $notes,
                    false
                ),
            ];
        });

        return ['written' => $written, 'notes' => $notes];
    }

    /**
     * @inheritdoc
     */
    public function describe(array $report, bool $dryRun): array
    {
        $written = $report['written'];
        if (array_sum($written) === 0) {
            return [];
        }

        return [\sprintf(
            '%s robots settings for %d product(s), %d categor%s and %d CMS page(s).',
            $dryRun ? 'Would import' : 'Imported',
            $written['product'],
            $written['category'],
            $written['category'] === 1 ? 'y' : 'ies',
            $written['cms_page']
        )];
    }

    /**
     * Write each entity's directives where this module has none, and note each value kept.
     *
     * - `$served`: by entity ID, then store view ID, the directive MageOS_MetaRobotsTag served there,
     *   or null for none.
     * - `$robots`: this module's robots for the entities, as a store view inherits them (0: the
     *   global row), '' for none.
     * - `$formShowsStoreViews`: whether the admin form shows a store view's row. The CMS page form
     *   shows only the global row, so a store view row written for a page is reported.
     *
     * @param string $label The entity type, as a note names it
     * @param array<int,array<int,string|null>> $served
     * @param callable(int[],int):array<int,string> $robots
     * @param callable(int,int,string):void $save Writes an entity's robots for a store view
     * @param bool $dryRun
     * @param string[] $notes
     * @param bool $formShowsStoreViews
     * @return int Entities given at least one row
     */
    private function importEntities(
        string $label,
        array $served,
        callable $robots,
        callable $save,
        bool $dryRun,
        array &$notes,
        bool $formShowsStoreViews = true
    ): int {
        $written = 0;
        foreach (array_chunk($served, self::CHUNK, true) as $chunk) {
            $entityIds = array_keys($chunk);
            $ours      = [0 => $robots($entityIds, 0)];
            foreach ($chunk as $directives) {
                foreach (array_keys($directives) as $storeId) {
                    $ours[$storeId] ??= $robots($entityIds, $storeId);
                }
            }

            foreach ($chunk as $entityId => $directives) {
                $global = $ours[0][$entityId] ?? '';
                $rows   = $this->rowsFor($directives);
                $wrote  = [];

                foreach ($rows as $storeId => $directive) {
                    $current = $ours[$storeId][$entityId] ?? '';
                    if ($current === '') {
                        if (!$dryRun) {
                            $save($entityId, $storeId, $directive);
                        }
                        $wrote[$storeId] = $directive;
                    } elseif ($current !== $directive) {
                        $notes[] = $this->kept($label, $entityId, $storeId, $current, $directive, $global);
                    }
                }

                if (!$formShowsStoreViews && !isset($rows[0]) && $wrote !== []) {
                    $notes[] = \sprintf(
                        '%s %d: the directives differ by store view, so they are set per store view, which'
                        . ' its admin form does not show: %s.',
                        $label,
                        $entityId,
                        implode(', ', array_map(
                            static fn (int $storeId, string $directive): string
                                => \sprintf('store view %d "%s"', $storeId, $directive),
                            array_keys($wrote),
                            $wrote
                        ))
                    );
                }

                // A global row does not reach a store view that has a row of its own.
                if (isset($rows[0])) {
                    foreach (array_keys($directives) as $storeId) {
                        $current = $ours[$storeId][$entityId] ?? '';
                        if ($current !== '' && $current !== $global && $current !== $rows[0]) {
                            $notes[] = $this->kept($label, $entityId, $storeId, $current, $rows[0], $global);
                        }
                    }
                }

                if ($wrote !== []) {
                    $written++;
                }
            }
        }

        return $written;
    }

    /**
     * The rows that reproduce an entity's directives.
     *
     * One global row when every store view the entity is on was served the same directive;
     * otherwise a row for each store view that was served one.
     *
     * @param array<int,string|null> $directives Store view ID => directive, null for none
     * @return array<int, string> Store view ID (0: global) => directive
     */
    private function rowsFor(array $directives): array
    {
        // array_unique() compares as strings, so null is one value among the directives.
        $first = reset($directives);
        if (\is_string($first) && \count(array_unique($directives)) === 1) {
            return [0 => $first];
        }

        return array_filter($directives, static fn (?string $directive): bool => $directive !== null);
    }

    /**
     * A note that this module's value was kept.
     *
     * @param string $label
     * @param int $entityId
     * @param int $storeId 0 for the global row
     * @param string $kept
     * @param string $theirs
     * @param string $global This module's global value, '' for none
     * @return string
     */
    private function kept(
        string $label,
        int $entityId,
        int $storeId,
        string $kept,
        string $theirs,
        string $global
    ): string {
        return \sprintf(
            '%s %d, %s: kept "%s"%s; MageOS_MetaRobotsTag\'s flags give "%s".',
            $label,
            $entityId,
            $storeId === 0 ? 'all store views' : 'store view ' . $storeId,
            $kept,
            $storeId > 0 && $kept === $global ? ' (set for all store views)' : '',
            $theirs
        );
    }

    /**
     * The robots of the rows a repository read, by entity ID.
     *
     * @param array<int,mixed[]> $rows
     * @return array<int, string>
     */
    private function robots(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) ($row['robots_meta'] ?? ''), $rows);
    }

    /**
     * Categories' own robots as a store view inherits them, without their ancestors'.
     *
     * @param int[] $categoryIds
     * @param int $storeId
     * @return array<int, string>
     */
    private function categoryRobots(array $categoryIds, int $storeId): array
    {
        $robots = [];
        foreach ($categoryIds as $categoryId) {
            $robots[$categoryId] = (string) (
                $this->categoryConfigRepository->getForCategory($categoryId, [], $storeId)['robots_meta'] ?? ''
            );
        }

        return $robots;
    }
}
