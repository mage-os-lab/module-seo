<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Filesystem\Directory\WriteInterface;

/**
 * The names of the files in a sitemap's directory, listed through the directory's driver.
 *
 * Not `Directory\Read::read()`: it passes every entry through Magento's deny list, which refuses any
 * `.htaccess`, so listing `pub/`, where a sitemap goes by default, threw (issue #43). The directory
 * path itself is still validated (`getAbsolutePath()`), and the driver lists local disk and remote
 * storage alike. Which names are a sitemap's own is FileNames::isOwn()'s to say.
 */
class DirectoryNames
{
    /**
     * @param FileNames $fileNames
     */
    public function __construct(
        private readonly FileNames $fileNames
    ) {
    }

    /**
     * The names of the entries in the directory.
     *
     * @param WriteInterface $directory
     * @param string $path The directory, relative to `$directory`
     * @throws FileSystemException
     * @throws ValidatorException The directory path itself is refused.
     * @return string[]
     */
    public function names(WriteInterface $directory, string $path): array
    {
        return array_map(
            fn (string $entry): string => $this->fileNames->nameOf($entry),
            $directory->getDriver()->readDirectory($directory->getAbsolutePath($path))
        );
    }
}
