<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Phrase;

/**
 * The local file driver, made to fail at a chosen operation.
 *
 * Counts every operation that changes a file — open, write, close, rename, delete — from the
 * moment failAt() is called, and throws in place of the one with the given number, as a full disk
 * or a lost mount would. The operations before it run for real.
 */
class FailingFileDriver extends File
{
    /**
     * The number of the operation to fail; null for none.
     *
     * @var int|null
     */
    private ?int $failAt = null;

    /**
     * Operations counted since failAt() was called.
     *
     * @var int
     */
    private int $operations = 0;

    /**
     * A pattern an operation — "delete /path/…" and the like — fails on; null for none.
     *
     * @var string|null
     */
    private ?string $failMatching = null;

    /**
     * Files opened and not yet closed.
     *
     * @var int
     */
    private int $openFiles = 0;

    /**
     * How many files are open: remote storage writes a file only when it is closed.
     *
     * @return int
     */
    public function openFiles(): int
    {
        return $this->openFiles;
    }

    /**
     * Fail every operation whose description matches the pattern, such as '#^delete .*-9\.xml$#'.
     *
     * @param string|null $pattern null to fail none
     * @return void
     */
    public function failMatching(?string $pattern): void
    {
        $this->failMatching = $pattern;
    }

    /**
     * Fail the operation with the given number, counting from 1 from now on.
     *
     * @param int|null $operation null to fail none
     * @return void
     */
    public function failAt(?int $operation): void
    {
        $this->failAt     = $operation;
        $this->operations = 0;
    }

    /**
     * @inheritDoc
     */
    public function fileOpen($path, $mode)
    {
        $this->count('open ' . $path);
        $resource = parent::fileOpen($path, $mode);
        $this->openFiles++;

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function fileWrite($resource, $data)
    {
        $this->count('write');

        return parent::fileWrite($resource, $data);
    }

    /**
     * @inheritDoc
     */
    public function fileClose($resource)
    {
        $this->count('close');
        $closed = parent::fileClose($resource);
        $this->openFiles--;

        return $closed;
    }

    /**
     * @inheritDoc
     */
    public function rename($oldPath, $newPath, ?DriverInterface $targetDriver = null)
    {
        $this->count('rename ' . $oldPath);

        return parent::rename($oldPath, $newPath, $targetDriver);
    }

    /**
     * @inheritDoc
     */
    public function deleteFile($path)
    {
        $this->count('delete ' . $path);

        return parent::deleteFile($path);
    }

    /**
     * Count an operation, and fail it if it is the chosen one.
     *
     * @param string $operation
     * @throws FileSystemException
     * @return void
     */
    private function count(string $operation): void
    {
        $this->operations++;
        if ($this->failAt === $this->operations
            || ($this->failMatching !== null && preg_match($this->failMatching, $operation) === 1)
        ) {
            throw new FileSystemException(new Phrase('Injected failure at operation %1: %2', [
                $this->operations,
                $operation,
            ]));
        }
    }
}
