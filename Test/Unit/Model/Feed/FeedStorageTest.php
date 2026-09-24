<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Feed;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteFactory;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Feed\StorageDirectory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FeedStorageTest extends TestCase
{
    /**
     * @var Filesystem&Stub
     */
    private Filesystem&Stub $filesystem;

    /**
     * @var WriteFactory&Stub
     */
    private WriteFactory&Stub $writeFactory;

    /**
     * @var ReadFactory&Stub
     */
    private ReadFactory&Stub $readFactory;

    /**
     * @var Config&Stub
     */
    private Config&Stub $config;

    protected function setUp(): void
    {
        $this->filesystem   = $this->createStub(Filesystem::class);
        $this->writeFactory = $this->createStub(WriteFactory::class);
        $this->readFactory  = $this->createStub(ReadFactory::class);
        $this->config       = $this->createStub(Config::class);
    }

    public function testWriteReplacesTheFileThroughATemporaryFileInTheSameDirectory(): void
    {
        $this->config->method('getFeedStorageDir')->willReturn('');
        $writeDir = $this->createMock(WriteInterface::class);
        $this->filesystem->method('getDirectoryWrite')
            ->willReturnMap([[DirectoryList::VAR_DIR, DriverPool::FILE, $writeDir]]);

        $temporary = null;
        $writeDir->expects($this->once())->method('openFile')
            ->with(
                $this->callback(static function (string $path) use (&$temporary): bool {
                    $temporary = $path;
                    return (bool) preg_match('#^mageos_seo/store_1/\.[0-9a-f]{12}\.tmp$#', $path);
                }),
                'w'
            )
            ->willReturn($this->fileHandle());
        $writeDir->expects($this->once())->method('renameFile')
            ->with(
                $this->callback(static function (string $path) use (&$temporary): bool {
                    return $path === $temporary;
                }),
                'mageos_seo/store_1/llms.txt'
            );
        $writeDir->expects($this->never())->method('delete');
        $writeDir->method('isExist')->willReturn(true);
        $writeDir->expects($this->never())->method('create');
        $modes = [];
        $writeDir->expects($this->exactly(3))->method('changePermissions')->willReturnCallback(
            static function (string $path, int $mode) use (&$modes): bool {
                $modes[] = [$path, $mode];
                return true;
            }
        );

        $this->storage()->write('llms.txt', 1, 'the-body');

        $this->assertSame(
            [
                ['mageos_seo', 0o750],
                ['mageos_seo/store_1', 0o750],
                ['mageos_seo/store_1/llms.txt', 0o640],
            ],
            $modes
        );
    }

    public function testMissingDirectoriesAreCreatedBeforeWriting(): void
    {
        $this->config->method('getFeedStorageDir')->willReturn('');
        $writeDir = $this->createMock(WriteInterface::class);
        $this->filesystem->method('getDirectoryWrite')->willReturn($writeDir);
        $writeDir->method('isExist')->willReturn(false);

        $calls = [];
        $writeDir->method('create')->willReturnCallback(
            static function (string $path) use (&$calls): bool {
                $calls[] = "create {$path}";
                return true;
            }
        );
        $writeDir->expects($this->once())->method('openFile')->willReturnCallback(
            function () use (&$calls): FileWriteInterface {
                $calls[] = 'open';
                return $this->fileHandle();
            }
        );

        $this->storage()->write('llms.txt', 1, 'the-body');

        $this->assertSame(['create mageos_seo', 'create mageos_seo/store_1', 'open'], $calls);
    }

    public function testPermissionFailuresDoNotFailTheWrite(): void
    {
        $this->config->method('getFeedStorageDir')->willReturn('');
        $writeDir = $this->createMock(WriteInterface::class);
        $this->filesystem->method('getDirectoryWrite')->willReturn($writeDir);
        $writeDir->method('isExist')->willReturn(true);
        $writeDir->method('changePermissions')->willThrowException(new \RuntimeException('chmod denied'));
        $writeDir->method('openFile')->willReturn($this->fileHandle());
        $writeDir->expects($this->once())->method('renameFile');

        $this->storage()->write('llms.txt', 1, 'the-body');
    }

    public function testADirectoryTheInstallationDoesNotPermitIsIgnored(): void
    {
        // The admin field is validated on save, but a configuration row can arrive by other
        // routes — a data patch, a deployment tool, a direct database write — so the value is
        // checked again here. Refusing it falls back to var/mageos_seo rather than failing.
        $this->config->method('getFeedStorageDir')->willReturn('/etc');
        $writeDir = $this->createMock(WriteInterface::class);
        $this->filesystem->method('getDirectoryWrite')
            ->willReturnMap([[DirectoryList::VAR_DIR, DriverPool::FILE, $writeDir]]);
        // The mageos_seo/ prefix is used only for the default location, so its presence below is
        // what shows /etc was never handed to the write factory.
        $writeDir->method('read')->willReturnMap([['mageos_seo/store_1', ['mageos_seo/store_1/llms.txt']]]);
        $writeDir->expects($this->once())->method('delete')->with('mageos_seo/store_1/llms.txt');

        $this->storage(false)->deleteForStore('llms.txt', 1);
    }

    public function testWriteUsesCustomDirectoryWithoutThePrefix(): void
    {
        $this->config->method('getFeedStorageDir')->willReturn('/shared/feeds');
        $writeDir = $this->createMock(WriteInterface::class);
        $this->writeFactory->method('create')
            ->willReturnMap([['/shared/feeds', DriverPool::FILE, null, null, $writeDir]]);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects($this->never())->method('getDirectoryWrite');
        $this->filesystem = $filesystem;

        $writeDir->expects($this->once())->method('openFile')
            ->with($this->stringStartsWith('store_1/.'), 'w')
            ->willReturn($this->fileHandle());
        $writeDir->expects($this->once())->method('renameFile')
            ->with($this->stringStartsWith('store_1/.'), 'store_1/llms.txt');
        // The configured storage root itself is left alone.
        $writeDir->method('isExist')->willReturn(true);
        $modes = [];
        $writeDir->method('changePermissions')->willReturnCallback(
            static function (string $path, int $mode) use (&$modes): bool {
                $modes[] = [$path, $mode];
                return true;
            }
        );

        $this->storage()->write('llms.txt', 1, 'the-body');

        $this->assertSame([['store_1', 0o750], ['store_1/llms.txt', 0o640]], $modes);
    }

    public function testFailedWriteRemovesTheTemporaryFileAndRethrows(): void
    {
        $this->config->method('getFeedStorageDir')->willReturn('');
        $writeDir = $this->createMock(WriteInterface::class);
        $this->filesystem->method('getDirectoryWrite')->willReturn($writeDir);

        $writeDir->method('openFile')->willReturn($this->fileHandle());
        $writeDir->method('renameFile')->willThrowException(new \RuntimeException('rename failed'));
        $writeDir->method('isExist')->willReturn(true);
        $writeDir->expects($this->once())->method('delete')
            ->with($this->stringStartsWith('mageos_seo/store_1/.'));

        $this->expectExceptionMessage('rename failed');
        $this->storage()->write('llms.txt', 1, 'the-body');
    }

    public function testReadReturnsContentWhenFileExists(): void
    {
        $this->config->method('getFeedStorageDir')->willReturn('');
        $readDir = $this->createStub(ReadInterface::class);
        $this->filesystem->method('getDirectoryRead')
            ->willReturnMap([[DirectoryList::VAR_DIR, DriverPool::FILE, $readDir]]);
        $readDir->method('isFile')->willReturnMap([['mageos_seo/store_1/llms.txt', true]]);
        $readDir->method('readFile')->willReturnMap([['mageos_seo/store_1/llms.txt', null, null, 'the-body']]);

        $this->assertSame('the-body', $this->storage()->read('llms.txt', 1));
    }

    public function testReadReturnsNullWhenFileMissing(): void
    {
        $this->config->method('getFeedStorageDir')->willReturn('');
        $readDir = $this->createStub(ReadInterface::class);
        $this->filesystem->method('getDirectoryRead')->willReturn($readDir);
        $readDir->method('isFile')->willReturn(false);

        $this->assertNull($this->storage()->read('llms.txt', 1));
    }

    public function testReadReturnsNullOnFilesystemException(): void
    {
        $this->config->method('getFeedStorageDir')->willReturn('');
        $readDir = $this->createStub(ReadInterface::class);
        $this->filesystem->method('getDirectoryRead')->willReturn($readDir);
        $readDir->method('isFile')->willThrowException(new \RuntimeException('io'));

        $this->assertNull($this->storage()->read('llms.txt', 1));
    }

    public function testDeleteForStoreScopesTheGlobToOneStore(): void
    {
        $this->config->method('getFeedStorageDir')->willReturn('');
        $writeDir = $this->createMock(WriteInterface::class);
        $this->filesystem->method('getDirectoryWrite')->willReturn($writeDir);

        // The store's directory is read and the entries are matched here, so the listing is
        // never one the framework cached earlier in the process.
        $writeDir->method('read')->willReturnMap([[
            'mageos_seo/store_2',
            [
                'mageos_seo/store_2/llms.txt',
                'mageos_seo/store_2/llms-full.txt',
                // Another store's files are in another directory; other feeds do not match.
                'mageos_seo/store_2/llms.jsonl',
            ],
        ]]);
        $deleted = [];
        $writeDir->expects($this->exactly(2))->method('delete')->willReturnCallback(
            static function (string $path) use (&$deleted): bool {
                $deleted[] = $path;
                return true;
            }
        );

        $this->storage()->deleteForStore('llms*.txt', 2);

        $this->assertSame(['mageos_seo/store_2/llms.txt', 'mageos_seo/store_2/llms-full.txt'], $deleted);
    }

    public function testListStoreDirectoriesReturnsTheStoreIdsThatHaveOne(): void
    {
        $this->config->method('getFeedStorageDir')->willReturn('');
        $writeDir = $this->createStub(WriteInterface::class);
        $this->filesystem->method('getDirectoryWrite')->willReturn($writeDir);
        $writeDir->method('read')->willReturnMap([[
            'mageos_seo',
            [
                'mageos_seo/store_1',
                'mageos_seo/store_12',
                // Anything that is not a store directory is ignored.
                'mageos_seo/store_notanumber',
                'mageos_seo/README.md',
            ],
        ]]);

        $this->assertSame([1, 12], $this->storage()->listStoreDirectories());
    }

    public function testListStoreDirectoriesIsEmptyOnFilesystemException(): void
    {
        $this->config->method('getFeedStorageDir')->willReturn('');
        $writeDir = $this->createStub(WriteInterface::class);
        $this->filesystem->method('getDirectoryWrite')->willReturn($writeDir);
        $writeDir->method('read')->willThrowException(new \RuntimeException('io'));

        $this->assertSame([], $this->storage()->listStoreDirectories());
    }

    public function testDeleteStoreDirectoryRemovesOnlyThatStoresDirectory(): void
    {
        $this->config->method('getFeedStorageDir')->willReturn('');
        $writeDir = $this->createMock(WriteInterface::class);
        $this->filesystem->method('getDirectoryWrite')->willReturn($writeDir);
        $writeDir->method('isExist')->willReturnMap([['mageos_seo/store_3', true]]);
        $writeDir->expects($this->once())->method('delete')->with('mageos_seo/store_3');

        $this->storage()->deleteStoreDirectory(3);
    }

    public function testDeleteStoreDirectoryIgnoresAMissingDirectory(): void
    {
        $this->config->method('getFeedStorageDir')->willReturn('');
        $writeDir = $this->createMock(WriteInterface::class);
        $this->filesystem->method('getDirectoryWrite')->willReturn($writeDir);
        $writeDir->method('isExist')->willReturn(false);
        $writeDir->expects($this->never())->method('delete');

        $this->storage()->deleteStoreDirectory(3);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testDeleteStoreDirectorySwallowsFilesystemErrors(): void
    {
        // The store view is already deleted when this runs; a failure here must not surface.
        $this->config->method('getFeedStorageDir')->willReturn('');
        $writeDir = $this->createStub(WriteInterface::class);
        $this->filesystem->method('getDirectoryWrite')->willReturn($writeDir);
        $writeDir->method('isExist')->willThrowException(new \RuntimeException('io'));

        $this->storage()->deleteStoreDirectory(3);
    }

    /**
     * A file handle that accepts whatever is written to it.
     *
     * @return FileWriteInterface
     */
    private function fileHandle(): FileWriteInterface
    {
        return $this->createStub(FileWriteInterface::class);
    }

    /**
     * Build the storage from the current collaborators.
     *
     * The configured directory is permitted unless a test says otherwise; what makes a directory
     * permitted at all is StorageDirectoryTest's subject, not this one's.
     *
     * @param bool $directoryAllowed
     * @return FeedStorage
     */
    private function storage(bool $directoryAllowed = true): FeedStorage
    {
        $storageDirectory = $this->createStub(StorageDirectory::class);
        $storageDirectory->method('isAllowed')->willReturn($directoryAllowed);

        return new FeedStorage(
            $this->filesystem,
            $this->writeFactory,
            $this->readFactory,
            $this->config,
            $storageDirectory,
            $this->createStub(LoggerInterface::class)
        );
    }
}
