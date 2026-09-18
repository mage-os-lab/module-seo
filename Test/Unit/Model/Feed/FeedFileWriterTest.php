<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Feed;

use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;
use MageOS\Seo\Model\Feed\FeedFileWriter;
use PHPUnit\Framework\TestCase;

class FeedFileWriterTest extends TestCase
{
    private const TEMPORARY = 'mageos_seo/store_1/.abc123.tmp';

    public function testContentGoesToTheTemporaryFileAndIsNamedOnCommit(): void
    {
        $written = [];
        $file    = $this->createStub(FileWriteInterface::class);
        $file->method('write')->willReturnCallback(
            static function (string $content) use (&$written): int {
                $written[] = $content;
                return \strlen($content);
            }
        );

        $directory = $this->createMock(WriteInterface::class);
        $directory->expects($this->once())->method('openFile')->with(self::TEMPORARY, 'w')->willReturn($file);
        $directory->expects($this->once())->method('renameFile')
            ->with(self::TEMPORARY, 'mageos_seo/store_1/llms.jsonl');
        $directory->expects($this->once())->method('changePermissions')
            ->with('mageos_seo/store_1/llms.jsonl', 0o640);

        $writer = $this->writer($directory);
        $writer->write("first\n");
        $writer->write("second\n");
        $writer->commit('llms.jsonl');

        $this->assertSame(["first\n", "second\n", ''], $written);
    }

    public function testAFileWithNoContentIsStillCreated(): void
    {
        // An empty catalogue must still replace the served file, not leave the previous one.
        $directory = $this->createMock(WriteInterface::class);
        $directory->expects($this->once())->method('openFile')->willReturn(
            $this->createStub(FileWriteInterface::class)
        );
        $directory->expects($this->once())->method('renameFile')
            ->with(self::TEMPORARY, 'mageos_seo/store_1/llms.jsonl');

        $this->writer($directory)->commit('llms.jsonl');
    }

    public function testDiscardRemovesTheTemporaryFileAndLeavesTheServedFile(): void
    {
        $directory = $this->createMock(WriteInterface::class);
        $directory->method('openFile')->willReturn($this->createStub(FileWriteInterface::class));
        $directory->method('isExist')->with(self::TEMPORARY)->willReturn(true);
        $directory->expects($this->once())->method('delete')->with(self::TEMPORARY);
        $directory->expects($this->never())->method('renameFile');

        $writer = $this->writer($directory);
        $writer->write('half a document');
        $writer->discard();
    }

    public function testDiscardWithoutAnyContentDoesNotFail(): void
    {
        $directory = $this->createMock(WriteInterface::class);
        $directory->method('isExist')->willReturn(false);
        $directory->expects($this->never())->method('delete');

        $this->writer($directory)->discard();
    }

    public function testAPermissionFailureAfterTheRenameIsIgnored(): void
    {
        $directory = $this->createMock(WriteInterface::class);
        $directory->method('openFile')->willReturn($this->createStub(FileWriteInterface::class));
        $directory->expects($this->once())->method('renameFile');
        $directory->method('changePermissions')->willThrowException(new \RuntimeException('chmod denied'));

        $this->writer($directory)->commit('llms.jsonl');
    }

    /**
     * @param WriteInterface $directory
     * @return FeedFileWriter
     */
    private function writer(WriteInterface $directory): FeedFileWriter
    {
        return new FeedFileWriter($directory, self::TEMPORARY, 'mageos_seo/store_1', 0o640);
    }
}
