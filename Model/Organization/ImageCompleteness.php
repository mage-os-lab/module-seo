<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Organization;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

/**
 * Whether an image file reaches the end its format defines (issue #20).
 *
 * Decoders are lenient on purpose: a JPEG cut short decodes under GD (`gd.jpeg_ignore_warning` is
 * on by default) and under ImageMagick, and a GIF cut short decodes under GD without a warning.
 * Browsers draw what is there. So a file cut short is found by walking its structure, the same
 * whichever image adapter is configured:
 * - **JPEG:** its segments, through each scan's data, to the end-of-image marker;
 * - **PNG:** its chunks, to `IEND`;
 * - **GIF:** its blocks and their sub-blocks, to the trailer;
 * - **WebP:** the length its RIFF header declares.
 *
 * The walk skips each segment by its declared length, so the end marker of a thumbnail embedded
 * in a JPEG's EXIF data does not count.
 */
class ImageCompleteness
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * @param FileDriver $fileDriver
     */
    public function __construct(
        private readonly FileDriver $fileDriver
    ) {
    }

    /**
     * Whether the file, of the given type, is complete.
     *
     * @param string $filePath
     * @param int $type An IMAGETYPE_* constant: JPEG, PNG, GIF or WEBP
     * @throws FileSystemException When the file cannot be read
     * @return bool
     */
    public function isComplete(string $filePath, int $type): bool
    {
        $bytes = (string) $this->fileDriver->fileGetContents($filePath);

        return match ($type) {
            IMAGETYPE_JPEG => $this->jpegIsComplete($bytes),
            IMAGETYPE_PNG  => $this->pngIsComplete($bytes),
            IMAGETYPE_GIF  => $this->gifIsComplete($bytes),
            IMAGETYPE_WEBP => $this->webpIsComplete($bytes),
            default        => false,
        };
    }

    /**
     * Walk a JPEG's segments to its end-of-image marker.
     *
     * @param string $bytes
     * @return bool
     */
    private function jpegIsComplete(string $bytes): bool
    {
        $length = \strlen($bytes);
        if (substr($bytes, 0, 2) !== "\xFF\xD8") {
            return false;
        }

        $offset = 2;
        while ($offset + 2 <= $length) {
            if ($bytes[$offset] !== "\xFF") {
                return false;
            }
            $marker = \ord($bytes[$offset + 1]);
            if ($marker === 0xD9) {
                return true;
            }
            if ($marker === 0xFF) {
                // A fill byte before a marker.
                $offset++;
                continue;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                // Markers without a length.
                $offset += 2;
                continue;
            }
            if ($offset + 4 > $length) {
                return false;
            }

            $offset += 2 + $this->number($bytes, $offset + 2, 2, false);
            if ($marker === 0xDA) {
                // A scan: its entropy-coded data runs to the next marker.
                $next = $this->nextJpegMarker($bytes, $offset);
                if ($next === null) {
                    return false;
                }
                $offset = $next;
            }
        }

        return false;
    }

    /**
     * The offset of the next marker after a scan's entropy-coded data, or null for none.
     *
     * Inside the data, 0xFF is followed by 0x00 (a stuffed byte), a restart marker or another 0xFF.
     *
     * @param string $bytes
     * @param int $offset
     * @return int|null
     */
    private function nextJpegMarker(string $bytes, int $offset): ?int
    {
        $length = \strlen($bytes);
        while ($offset < $length && ($offset = strpos($bytes, "\xFF", $offset)) !== false) {
            if ($offset + 1 >= $length) {
                return null;
            }
            $next = \ord($bytes[$offset + 1]);
            if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                $offset += 2;
                continue;
            }
            if ($next === 0xFF) {
                $offset++;
                continue;
            }

            return $offset;
        }

        return null;
    }

    /**
     * Walk a PNG's chunks to IEND.
     *
     * @param string $bytes
     * @return bool
     */
    private function pngIsComplete(string $bytes): bool
    {
        if (!str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return false;
        }

        $length = \strlen($bytes);
        $offset = \strlen(self::PNG_SIGNATURE);
        // Each chunk: its data length (4 bytes), type (4), data and CRC (4).
        while ($offset + 12 <= $length) {
            $type    = substr($bytes, $offset + 4, 4);
            $offset += 12 + $this->number($bytes, $offset, 4, false);
            if ($type === 'IEND') {
                return $offset <= $length;
            }
        }

        return false;
    }

    /**
     * Walk a GIF's blocks to its trailer.
     *
     * @param string $bytes
     * @return bool
     */
    private function gifIsComplete(string $bytes): bool
    {
        $length = \strlen($bytes);
        if ($length < 13 || !\in_array(substr($bytes, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            return false;
        }

        // The header (6 bytes) and the logical screen descriptor (7), then its colour table.
        $offset = 13 + $this->gifColourTableSize(\ord($bytes[10]));
        while ($offset < $length) {
            $block = $bytes[$offset];
            if ($block === "\x3B") {
                return true;
            }

            if ($block === "\x21") {
                // An extension: introducer, label, then sub-blocks.
                $next = $this->afterGifSubBlocks($bytes, $offset + 2);
            } elseif ($block === "\x2C" && $offset + 10 <= $length) {
                // An image: its descriptor (10 bytes), colour table, LZW code size (1), sub-blocks.
                $next = $this->afterGifSubBlocks(
                    $bytes,
                    $offset + 10 + $this->gifColourTableSize(\ord($bytes[$offset + 9])) + 1
                );
            } else {
                return false;
            }

            if ($next === null) {
                return false;
            }
            $offset = $next;
        }

        return false;
    }

    /**
     * The size of the colour table a GIF descriptor's packed field announces.
     *
     * @param int $packed
     * @return int
     */
    private function gifColourTableSize(int $packed): int
    {
        return ($packed & 0x80) === 0 ? 0 : 3 * (2 ** (($packed & 0x07) + 1));
    }

    /**
     * The offset after a run of GIF sub-blocks and its terminator, or null where it is cut short.
     *
     * @param string $bytes
     * @param int $offset
     * @return int|null
     */
    private function afterGifSubBlocks(string $bytes, int $offset): ?int
    {
        $length = \strlen($bytes);
        while ($offset < $length) {
            $size    = \ord($bytes[$offset]);
            $offset += 1 + $size;
            if ($size === 0) {
                return $offset;
            }
        }

        return null;
    }

    /**
     * Whether a WebP is as long as its RIFF header declares.
     *
     * @param string $bytes
     * @return bool
     */
    private function webpIsComplete(string $bytes): bool
    {
        $length = \strlen($bytes);
        if ($length < 12 || !str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            return false;
        }

        // The size counts what follows its own 8 bytes.
        return $length >= 8 + $this->number($bytes, 4, 4, true);
    }

    /**
     * An unsigned number stored in the given bytes.
     *
     * @param string $bytes
     * @param int $offset
     * @param int $size Bytes: 2 or 4
     * @param bool $littleEndian WebP's RIFF sizes are; JPEG's and PNG's lengths are big-endian
     * @return int
     */
    private function number(string $bytes, int $offset, int $size, bool $littleEndian): int
    {
        $number = 0;
        for ($index = 0; $index < $size; $index++) {
            $byte    = \ord($bytes[$offset + ($littleEndian ? $size - 1 - $index : $index)]);
            $number  = ($number << 8) | $byte;
        }

        return $number;
    }
}
