<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Sitemap\Model\Sitemap;

/**
 * Every name a sitemap's files can have, so the Writer and the clean-up agree on them.
 *
 * - `{name}.xml` — the index, the one name search engines are given. It never changes.
 * - `{name}-{store}-{type}-a-{n}.xml` and `{name}-{store}-{type}-b-{n}.xml` — the two sets of
 *   each type. A rebuild writes the set the index does not list, then switches the index to it,
 *   so the index always lists a set that is complete.
 * - `{name}-{store}-{type}-{n}.xml` — the names this generator used before the sets, and
 *   `{name}-{store}-{n}.xml` — core's own. Only recognised, so they can be removed.
 * - `{file}.tmp` — a file being written, renamed over its name once complete.
 *
 * `{name}` is the Site Map entry's file name without `.xml`, as core names its own files from it.
 * Names are matched against the registered types, never a pattern any type would fit: a type can
 * contain a hyphen, so a looser pattern could take another sitemap's files for this one's.
 */
class FileNames
{
    /**
     * The two sets of a type, the first one first.
     */
    public const SETS = ['a', 'b'];

    private const TEMPORARY_SUFFIX = '.tmp';

    /**
     * The sitemap's directory, as core builds its paths: the entry's path without its trailing slash.
     *
     * @param Sitemap $sitemap
     * @return string
     */
    public function directory(Sitemap $sitemap): string
    {
        return rtrim((string) $sitemap->getSitemapPath(), '/');
    }

    /**
     * The path of a file in the sitemap's directory.
     *
     * @param Sitemap $sitemap
     * @param string $fileName
     * @return string
     */
    public function path(Sitemap $sitemap, string $fileName): string
    {
        return $this->directory($sitemap) . '/' . $fileName;
    }

    /**
     * The file name at the end of a path, as a directory listing gives it.
     *
     * @param string $path
     * @return string
     */
    public function nameOf(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? $path : substr($path, $slash + 1);
    }

    /**
     * The index's file name.
     *
     * @param Sitemap $sitemap
     * @return string
     */
    public function index(Sitemap $sitemap): string
    {
        return (string) $sitemap->getSitemapFilename();
    }

    /**
     * The name of a type's file in one of its sets.
     *
     * @param Sitemap $sitemap
     * @param string $type
     * @param string $set One of SETS
     * @param int $number From 1
     * @return string
     */
    public function child(Sitemap $sitemap, string $type, string $set, int $number): string
    {
        return $this->prefix($sitemap) . $type . '-' . $set . '-' . $number . '.xml';
    }

    /**
     * The name a file is written under until it is complete.
     *
     * @param string $fileName
     * @return string
     */
    public function temporary(string $fileName): string
    {
        return $fileName . self::TEMPORARY_SUFFIX;
    }

    /**
     * The set a file of the type belongs to, or null when the name is not one of the type's sets.
     *
     * @param Sitemap $sitemap
     * @param string $type
     * @param string $fileName
     * @return string|null
     */
    public function setOf(Sitemap $sitemap, string $type, string $fileName): ?string
    {
        $pattern = '/^' . preg_quote($this->prefix($sitemap) . $type, '/')
            . '-(' . implode('|', self::SETS) . ')-\d+\.xml$/';

        return preg_match($pattern, $fileName, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Whether a file in the sitemap's directory is one of this sitemap's, other than its index.
     *
     * A file of either set of a registered type, one of this generator's names from before the
     * sets, one of core's, or one of these — or the index — being written.
     *
     * @param Sitemap $sitemap
     * @param string[] $types The registered types
     * @param string $fileName
     * @return bool
     */
    public function isOwn(Sitemap $sitemap, array $types, string $fileName): bool
    {
        if ($fileName === $this->temporary($this->index($sitemap))) {
            return true;
        }

        $typed = $types === []
            ? ''
            : '(?:(?:' . implode('|', array_map(static fn (string $type) => preg_quote($type, '/'), $types))
                . ')-(?:(?:' . implode('|', self::SETS) . ')-)?)?';

        return preg_match(
            '/^' . preg_quote($this->prefix($sitemap), '/') . $typed . '\d+\.xml'
            . '(?:' . preg_quote(self::TEMPORARY_SUFFIX, '/') . ')?$/',
            $fileName
        ) === 1;
    }

    /**
     * What every name of the sitemap's other files starts with: `{name}-{store}-`.
     *
     * @param Sitemap $sitemap
     * @return string
     */
    private function prefix(Sitemap $sitemap): string
    {
        return str_replace('.xml', '', $this->index($sitemap)) . '-' . (int) $sitemap->getStoreId() . '-';
    }
}
