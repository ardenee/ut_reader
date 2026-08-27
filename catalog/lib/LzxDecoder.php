<?php
/**
 * Native LZX decompressor used by UE3 package-level compression.
 *
 * UE3's COMPRESS_LZX blocks are regular raw LZX streams. Epic-compatible
 * readers use a 17-bit (128 KiB) window for these package blocks. This
 * implementation is dependency-free PHP and supports regular LZX windows
 * from 15 through 21 bits so the codec can also be regression-tested against
 * other standard LZX streams.
 *
 * Algorithm references:
 * - Microsoft LZX format
 * - libmspack lzxd behaviour (frame alignment / Intel E8 transform)
 * - UEViewer UE3 appDecompressLZX usage (window_bits=17, reset_interval=0)
 */
declare(strict_types=1);

final class CatalogLzxBitReader
{
    private int $pos = 0;
    private int $buffer = 0;
    private int $bitsLeft = 0;
    private readonly int $length;

    public function __construct(private readonly string $data)
    {
        $this->length = strlen($data);
    }

    public function readBits(int $count): int
    {
        if ($count < 0 || $count > 32) {
            throw new RuntimeException('Invalid LZX bit count=' . $count);
        }
        if ($count === 0) {
            return 0;
        }
        $this->ensureBits($count);
        $value = $this->peekBits($count);
        $this->removeBits($count);
        return $value;
    }

    public function ensureBits(int $count): void
    {
        while ($this->bitsLeft < $count) {
            if ($this->pos >= $this->length) {
                throw new RuntimeException(
                    'LZX input overrun while requesting ' . $count
                    . ' bits at byte ' . $this->pos
                );
            }

            $lo = ord($this->data[$this->pos++]);
            $hi = 0;
            if ($this->pos < $this->length) {
                $hi = ord($this->data[$this->pos++]);
            }

            $this->buffer = ($this->buffer << 16) | (($hi << 8) | $lo);
            $this->bitsLeft += 16;
        }
    }

    public function peekBits(int $count): int
    {
        if ($count < 0 || $count > $this->bitsLeft) {
            throw new RuntimeException('Invalid LZX bit peek count=' . $count);
        }
        if ($count === 0) {
            return 0;
        }
        return ($this->buffer >> ($this->bitsLeft - $count)) & ((1 << $count) - 1);
    }

    public function removeBits(int $count): void
    {
        if ($count < 0 || $count > $this->bitsLeft) {
            throw new RuntimeException(
                'LZX attempted to consume ' . $count
                . ' bits with only ' . $this->bitsLeft . ' buffered'
            );
        }

        $this->bitsLeft -= $count;
        if ($this->bitsLeft === 0) {
            $this->buffer = 0;
            return;
        }

        $this->buffer &= (1 << $this->bitsLeft) - 1;
    }

    public function bitsLeft(): int
    {
        return $this->bitsLeft;
    }

    /**
     * LZX raw/uncompressed block alignment can have one 16-bit word prefetched.
     * Return that word to the byte stream before switching to byte-aligned reads.
     */
    public function alignForRawBlock(): void
    {
        $this->ensureBits(16);
        if ($this->bitsLeft > 16) {
            $this->pos = max(0, $this->pos - 2);
        }
        $this->resetBits();
    }

    public function alignFrameWord(): void
    {
        if ($this->bitsLeft > 0) {
            $this->ensureBits(16);
        }
        $remainder = $this->bitsLeft & 15;
        if ($remainder !== 0) {
            $this->removeBits($remainder);
        }
    }

    public function skipRawByte(): void
    {
        if ($this->bitsLeft !== 0) {
            throw new RuntimeException('LZX raw byte skip requested while bit buffer is active');
        }
        if ($this->pos >= $this->length) {
            throw new RuntimeException('LZX input overrun while skipping raw alignment byte');
        }
        $this->pos++;
    }

    public function readRawByte(): int
    {
        if ($this->bitsLeft !== 0) {
            throw new RuntimeException('LZX raw byte read requested while bit buffer is active');
        }
        if ($this->pos >= $this->length) {
            throw new RuntimeException('LZX raw input overrun at byte ' . $this->pos);
        }
        return ord($this->data[$this->pos++]);
    }

    public function readRawLe32(): int
    {
        $b0 = $this->readRawByte();
        $b1 = $this->readRawByte();
        $b2 = $this->readRawByte();
        $b3 = $this->readRawByte();
        return $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);
    }

    public function resetBits(): void
    {
        $this->buffer = 0;
        $this->bitsLeft = 0;
    }
}

final class CatalogLzxDecoder
{
    private const NUM_CHARS = 256;
    private const MIN_MATCH = 2;
    private const NUM_PRIMARY_LENGTHS = 7;
    private const NUM_SECONDARY_LENGTHS = 249;

    private const BLOCKTYPE_VERBATIM = 1;
    private const BLOCKTYPE_ALIGNED = 2;
    private const BLOCKTYPE_UNCOMPRESSED = 3;

    private const PRETREE_NUM_ELEMENTS = 20;
    private const ALIGNED_NUM_ELEMENTS = 8;
    private const PRETREE_TABLEBITS = 6;
    private const MAINTREE_TABLEBITS = 12;
    private const LENGTH_TABLEBITS = 12;
    private const ALIGNED_TABLEBITS = 7;
    private const MAX_CODEWORD = 16;
    private const LENTABLE_SAFETY = 64;
    private const FRAME_SIZE = 32768;

    /** @var array<int,int> */
    private const POSITION_SLOTS = [
        15 => 30,
        16 => 32,
        17 => 34,
        18 => 36,
        19 => 38,
        20 => 42,
        21 => 50,
    ];

    private int $windowSize;
    private int $windowMask;
    private int $mainElements;
    private string $window;

    private int $r0 = 1;
    private int $r1 = 1;
    private int $r2 = 1;

    private int $blockType = 0;
    private int $blockLength = 0;
    private int $blockRemaining = 0;

    private bool $headerRead = false;
    private int $intelFileSize = 0;
    private int $intelCurPos = 0;
    private bool $intelStarted = false;
    private int $frame = 0;

    /** @var array<int,int> */
    private array $pretreeLen;
    /** @var array<int,int> */
    private array $maintreeLen;
    /** @var array<int,int> */
    private array $lengthLen;
    /** @var array<int,int> */
    private array $alignedLen;

    /** @var array<int,int> */
    private array $pretreeTable = [];
    /** @var array<int,int> */
    private array $maintreeTable = [];
    /** @var array<int,int> */
    private array $lengthTable = [];
    /** @var array<int,int> */
    private array $alignedTable = [];

    /** @var array<int,int> */
    private array $extraBits = [];
    /** @var array<int,int> */
    private array $positionBase = [];

    public function __construct(private readonly int $windowBits = 17)
    {
        if (!isset(self::POSITION_SLOTS[$windowBits])) {
            throw new InvalidArgumentException(
                'LZX window bits must be between 15 and 21; got ' . $windowBits
            );
        }

        $this->windowSize = 1 << $windowBits;
        $this->windowMask = $this->windowSize - 1;
        $this->mainElements = self::NUM_CHARS + (self::POSITION_SLOTS[$windowBits] << 3);
        $this->window = str_repeat("\xDC", $this->windowSize);

        $this->pretreeLen = array_fill(
            0,
            self::PRETREE_NUM_ELEMENTS + self::LENTABLE_SAFETY,
            0
        );
        $this->maintreeLen = array_fill(
            0,
            $this->mainElements + self::LENTABLE_SAFETY,
            0
        );
        $this->lengthLen = array_fill(
            0,
            self::NUM_SECONDARY_LENGTHS + 1 + self::LENTABLE_SAFETY,
            0
        );
        $this->alignedLen = array_fill(
            0,
            self::ALIGNED_NUM_ELEMENTS + self::LENTABLE_SAFETY,
            0
        );

        $this->buildPositionTables();
    }

    public static function decompress(string $source, int $expectedSize, int $windowBits = 17): string
    {
        if ($expectedSize < 0) {
            throw new InvalidArgumentException('Negative LZX expected output size');
        }
        if ($expectedSize === 0) {
            return '';
        }
        if ($source === '') {
            throw new RuntimeException('Empty LZX compressed block');
        }

        return (new self($windowBits))->decode($source, $expectedSize);
    }

    private function decode(string $source, int $expectedSize): string
    {
        $bits = new CatalogLzxBitReader($source);
        $output = '';
        $windowPos = 0;
        $framePos = 0;

        if (!$this->headerRead) {
            $intel = $bits->readBits(1);
            if ($intel !== 0) {
                $high = $bits->readBits(16);
                $low = $bits->readBits(16);
                $this->intelFileSize = ($high << 16) | $low;
            }
            $this->headerRead = true;
            $this->intelStarted = false;
        }

        while (strlen($output) < $expectedSize) {
            $remainingOutput = $expectedSize - strlen($output);
            $frameSize = min(self::FRAME_SIZE, $remainingOutput);
            $bytesTodo = ($framePos + $frameSize) - $windowPos;
            if ($bytesTodo < 0) {
                throw new RuntimeException('LZX decoder advanced beyond the current output frame');
            }

            while ($bytesTodo > 0) {
                if ($this->blockRemaining === 0) {
                    $this->readBlockHeader($bits);
                }

                $thisRun = min($this->blockRemaining, $bytesTodo);
                $bytesTodo -= $thisRun;
                $this->blockRemaining -= $thisRun;

                if ($thisRun <= 0) {
                    throw new RuntimeException('Invalid LZX block run length');
                }

                if ($this->blockType === self::BLOCKTYPE_UNCOMPRESSED) {
                    for ($i = 0; $i < $thisRun; $i++) {
                        $this->window[$windowPos & $this->windowMask] = chr($bits->readRawByte());
                        $windowPos++;
                    }
                    continue;
                }

                while ($thisRun > 0) {
                    $mainElement = $this->readHuffman(
                        $this->maintreeTable,
                        $this->maintreeLen,
                        $this->mainElements,
                        self::MAINTREE_TABLEBITS,
                        $bits
                    );

                    if ($mainElement < self::NUM_CHARS) {
                        $this->window[$windowPos & $this->windowMask] = chr($mainElement);
                        $windowPos++;
                        $thisRun--;
                        continue;
                    }

                    $mainElement -= self::NUM_CHARS;
                    $matchLength = $mainElement & self::NUM_PRIMARY_LENGTHS;
                    if ($matchLength === self::NUM_PRIMARY_LENGTHS) {
                        $matchLength += $this->readHuffman(
                            $this->lengthTable,
                            $this->lengthLen,
                            self::NUM_SECONDARY_LENGTHS + 1,
                            self::LENGTH_TABLEBITS,
                            $bits
                        );
                    }
                    $matchLength += self::MIN_MATCH;

                    $slot = $mainElement >> 3;
                    $matchOffset = $this->matchOffset($slot, $bits);
                    if ($matchOffset <= 0 || $matchOffset > $this->windowSize) {
                        throw new RuntimeException(
                            'Invalid LZX match offset=' . $matchOffset
                            . ' window=' . $this->windowSize
                        );
                    }

                    $thisRun -= $matchLength;
                    $sourcePos = ($windowPos - $matchOffset) & $this->windowMask;
                    for ($i = 0; $i < $matchLength; $i++) {
                        $byte = $this->window[$sourcePos];
                        $this->window[$windowPos & $this->windowMask] = $byte;
                        $sourcePos = ($sourcePos + 1) & $this->windowMask;
                        $windowPos++;
                    }
                }

                if ($thisRun < 0) {
                    $overrun = -$thisRun;
                    if ($overrun > $this->blockRemaining) {
                        throw new RuntimeException(
                            'LZX match overruns block by ' . $overrun
                            . ' with only ' . $this->blockRemaining . ' bytes remaining'
                        );
                    }
                    $this->blockRemaining -= $overrun;
                }
            }

            if (($windowPos - $framePos) !== $frameSize) {
                throw new RuntimeException(
                    'LZX frame output mismatch expected=' . $frameSize
                    . ' got=' . ($windowPos - $framePos)
                );
            }

            $bits->alignFrameWord();
            $frameBytes = $this->windowSlice($framePos, $frameSize);
            if ($this->intelStarted
                && $this->intelFileSize !== 0
                && $this->frame <= 32768
                && $frameSize > 10) {
                $frameBytes = $this->applyIntelE8($frameBytes, $frameSize);
            } elseif ($this->intelFileSize !== 0) {
                $this->intelCurPos += $frameSize;
            }

            $output .= $frameBytes;
            $framePos += $frameSize;
            $this->frame++;
        }

        if (strlen($output) !== $expectedSize) {
            throw new RuntimeException(
                'LZX output size mismatch expected=' . $expectedSize
                . ' got=' . strlen($output)
            );
        }

        return $output;
    }

    private function readBlockHeader(CatalogLzxBitReader $bits): void
    {
        if ($this->blockType === self::BLOCKTYPE_UNCOMPRESSED) {
            if (($this->blockLength & 1) !== 0) {
                $bits->skipRawByte();
            }
            $bits->resetBits();
        }

        $this->blockType = $bits->readBits(3);
        $highLength = $bits->readBits(16);
        $lowLength = $bits->readBits(8);
        $this->blockLength = ($highLength << 8) | $lowLength;
        $this->blockRemaining = $this->blockLength;

        if ($this->blockLength <= 0) {
            throw new RuntimeException('Invalid zero-length LZX block');
        }

        if ($this->blockType === self::BLOCKTYPE_ALIGNED) {
            for ($i = 0; $i < self::ALIGNED_NUM_ELEMENTS; $i++) {
                $this->alignedLen[$i] = $bits->readBits(3);
            }
            $this->alignedTable = $this->makeDecodeTable(
                self::ALIGNED_NUM_ELEMENTS,
                self::ALIGNED_TABLEBITS,
                $this->alignedLen
            );
        }

        if ($this->blockType === self::BLOCKTYPE_VERBATIM
            || $this->blockType === self::BLOCKTYPE_ALIGNED) {
            $this->readLengths($this->maintreeLen, 0, self::NUM_CHARS, $bits);
            $this->readLengths(
                $this->maintreeLen,
                self::NUM_CHARS,
                $this->mainElements,
                $bits
            );
            $this->maintreeTable = $this->makeDecodeTable(
                $this->mainElements,
                self::MAINTREE_TABLEBITS,
                $this->maintreeLen
            );
            if (($this->maintreeLen[0xE8] ?? 0) !== 0) {
                $this->intelStarted = true;
            }

            $this->readLengths(
                $this->lengthLen,
                0,
                self::NUM_SECONDARY_LENGTHS,
                $bits
            );
            $this->lengthTable = $this->makeDecodeTable(
                self::NUM_SECONDARY_LENGTHS + 1,
                self::LENGTH_TABLEBITS,
                $this->lengthLen
            );
            return;
        }

        if ($this->blockType === self::BLOCKTYPE_UNCOMPRESSED) {
            $this->intelStarted = true;
            $bits->alignForRawBlock();
            $this->r0 = $bits->readRawLe32();
            $this->r1 = $bits->readRawLe32();
            $this->r2 = $bits->readRawLe32();
            return;
        }

        throw new RuntimeException('Invalid LZX block type=' . $this->blockType);
    }

    /**
     * @param array<int,int> $lengths
     */
    private function readLengths(
        array &$lengths,
        int $first,
        int $last,
        CatalogLzxBitReader $bits
    ): void {
        for ($i = 0; $i < self::PRETREE_NUM_ELEMENTS; $i++) {
            $this->pretreeLen[$i] = $bits->readBits(4);
        }
        $this->pretreeTable = $this->makeDecodeTable(
            self::PRETREE_NUM_ELEMENTS,
            self::PRETREE_TABLEBITS,
            $this->pretreeLen
        );

        $x = $first;
        while ($x < $last) {
            $z = $this->readHuffman(
                $this->pretreeTable,
                $this->pretreeLen,
                self::PRETREE_NUM_ELEMENTS,
                self::PRETREE_TABLEBITS,
                $bits
            );

            if ($z === 17) {
                $run = $bits->readBits(4) + 4;
                $this->fillLengths($lengths, $x, $last, $run, 0);
                $x += $run;
                continue;
            }

            if ($z === 18) {
                $run = $bits->readBits(5) + 20;
                $this->fillLengths($lengths, $x, $last, $run, 0);
                $x += $run;
                continue;
            }

            if ($z === 19) {
                $run = $bits->readBits(1) + 4;
                $delta = $this->readHuffman(
                    $this->pretreeTable,
                    $this->pretreeLen,
                    self::PRETREE_NUM_ELEMENTS,
                    self::PRETREE_TABLEBITS,
                    $bits
                );
                $value = (($lengths[$x] ?? 0) + 17 - $delta) % 17;
                $this->fillLengths($lengths, $x, $last, $run, $value);
                $x += $run;
                continue;
            }

            $lengths[$x] = (($lengths[$x] ?? 0) + 17 - $z) % 17;
            $x++;
        }
    }

    /**
     * @param array<int,int> $lengths
     */
    private function fillLengths(
        array &$lengths,
        int $start,
        int $last,
        int $run,
        int $value
    ): void {
        if ($run < 0 || $start + $run > $last) {
            throw new RuntimeException(
                'LZX code-length run exceeds table bounds start=' . $start
                . ' run=' . $run . ' last=' . $last
            );
        }
        for ($i = 0; $i < $run; $i++) {
            $lengths[$start + $i] = $value;
        }
    }

    /**
     * @param array<int,int> $lengths
     * @return array<int,int>
     */
    private function makeDecodeTable(int $symbolCount, int $tableBits, array $lengths): array
    {
        $tableSize = (1 << $tableBits) + ($symbolCount << 1) + 64;
        $table = array_fill(0, $tableSize, 0);

        $pos = 0;
        $tableMask = 1 << $tableBits;
        $bitMask = $tableMask >> 1;
        $nextSymbol = $bitMask;
        $bitNumber = 1;

        while ($bitNumber <= $tableBits) {
            for ($symbol = 0; $symbol < $symbolCount; $symbol++) {
                if (($lengths[$symbol] ?? 0) !== $bitNumber) {
                    continue;
                }

                $leaf = $pos;
                $pos += $bitMask;
                if ($pos > $tableMask) {
                    throw new RuntimeException('Invalid LZX Huffman table: direct table overrun');
                }

                for ($fill = 0; $fill < $bitMask; $fill++) {
                    $table[$leaf + $fill] = $symbol;
                }
            }
            $bitMask >>= 1;
            $bitNumber++;
        }

        if ($pos === $tableMask) {
            return $table;
        }

        for ($symbol = $pos; $symbol < $tableMask; $symbol++) {
            $table[$symbol] = 0;
        }

        $pos <<= 16;
        $tableMask <<= 16;
        $bitMask = 1 << 15;

        while ($bitNumber <= self::MAX_CODEWORD) {
            for ($symbol = 0; $symbol < $symbolCount; $symbol++) {
                if (($lengths[$symbol] ?? 0) !== $bitNumber) {
                    continue;
                }

                $leaf = $pos >> 16;
                for ($fill = 0; $fill < ($bitNumber - $tableBits); $fill++) {
                    if (($table[$leaf] ?? 0) === 0) {
                        $left = $nextSymbol << 1;
                        $right = $left + 1;
                        if ($right >= count($table)) {
                            throw new RuntimeException('Invalid LZX Huffman table: tree overflow');
                        }
                        $table[$left] = 0;
                        $table[$right] = 0;
                        $table[$leaf] = $nextSymbol++;
                    }

                    $leaf = ($table[$leaf] ?? 0) << 1;
                    if ((($pos >> (15 - $fill)) & 1) !== 0) {
                        $leaf++;
                    }
                    if ($leaf >= count($table)) {
                        throw new RuntimeException('Invalid LZX Huffman table: leaf overflow');
                    }
                }

                $table[$leaf] = $symbol;
                $pos += $bitMask;
                if ($pos > $tableMask) {
                    throw new RuntimeException('Invalid LZX Huffman table: long table overrun');
                }
            }

            $bitMask >>= 1;
            $bitNumber++;
        }

        if ($pos === $tableMask) {
            return $table;
        }

        for ($symbol = 0; $symbol < $symbolCount; $symbol++) {
            if (($lengths[$symbol] ?? 0) !== 0) {
                throw new RuntimeException('Invalid or incomplete LZX Huffman table');
            }
        }

        return $table;
    }

    /**
     * @param array<int,int> $table
     * @param array<int,int> $lengths
     */
    private function readHuffman(
        array $table,
        array $lengths,
        int $symbolCount,
        int $tableBits,
        CatalogLzxBitReader $bits
    ): int {
        $bits->ensureBits(self::MAX_CODEWORD);
        $peek = $bits->peekBits(self::MAX_CODEWORD);
        $index = $table[$peek >> (self::MAX_CODEWORD - $tableBits)] ?? -1;

        if ($index < 0) {
            throw new RuntimeException('Invalid LZX Huffman lookup');
        }

        if ($index >= $symbolCount) {
            $mask = 1 << (self::MAX_CODEWORD - $tableBits - 1);
            do {
                if ($mask === 0) {
                    throw new RuntimeException('Invalid LZX Huffman tree traversal');
                }
                $index = ($index << 1) | (($peek & $mask) !== 0 ? 1 : 0);
                $mask >>= 1;
                $index = $table[$index] ?? -1;
                if ($index < 0) {
                    throw new RuntimeException('Invalid LZX Huffman tree node');
                }
            } while ($index >= $symbolCount);
        }

        $length = $lengths[$index] ?? 0;
        if ($length <= 0 || $length > self::MAX_CODEWORD) {
            throw new RuntimeException(
                'Invalid LZX Huffman code length=' . $length . ' symbol=' . $index
            );
        }
        $bits->removeBits($length);
        return $index;
    }

    private function matchOffset(int $slot, CatalogLzxBitReader $bits): int
    {
        if ($slot < 0 || $slot >= count($this->extraBits)) {
            throw new RuntimeException('Invalid LZX match slot=' . $slot);
        }

        if ($slot === 0) {
            return $this->r0;
        }
        if ($slot === 1) {
            $offset = $this->r1;
            $this->r1 = $this->r0;
            $this->r0 = $offset;
            return $offset;
        }
        if ($slot === 2) {
            $offset = $this->r2;
            $this->r2 = $this->r0;
            $this->r0 = $offset;
            return $offset;
        }

        if ($slot === 3) {
            $offset = 1;
        } else {
            $extra = $this->extraBits[$slot];
            $offset = $this->positionBase[$slot] - 2;

            if ($this->blockType === self::BLOCKTYPE_ALIGNED) {
                if ($extra > 3) {
                    $offset += $bits->readBits($extra - 3) << 3;
                    $offset += $this->readHuffman(
                        $this->alignedTable,
                        $this->alignedLen,
                        self::ALIGNED_NUM_ELEMENTS,
                        self::ALIGNED_TABLEBITS,
                        $bits
                    );
                } elseif ($extra === 3) {
                    $offset += $this->readHuffman(
                        $this->alignedTable,
                        $this->alignedLen,
                        self::ALIGNED_NUM_ELEMENTS,
                        self::ALIGNED_TABLEBITS,
                        $bits
                    );
                } elseif ($extra > 0) {
                    $offset += $bits->readBits($extra);
                } else {
                    $offset = 1;
                }
            } else {
                $offset += $bits->readBits($extra);
            }
        }

        $this->r2 = $this->r1;
        $this->r1 = $this->r0;
        $this->r0 = $offset;
        return $offset;
    }

    private function windowSlice(int $start, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $start &= $this->windowMask;
        if ($start + $length <= $this->windowSize) {
            return substr($this->window, $start, $length);
        }

        $first = $this->windowSize - $start;
        return substr($this->window, $start, $first)
            . substr($this->window, 0, $length - $first);
    }

    private function applyIntelE8(string $frame, int $frameSize): string
    {
        $limit = $frameSize - 10;
        $index = 0;
        $curPos = $this->intelCurPos;

        while ($index < $limit) {
            if (ord($frame[$index]) !== 0xE8) {
                $index++;
                $curPos++;
                continue;
            }

            $value = unpack('Voffset', substr($frame, $index + 1, 4));
            $absolute = is_array($value) ? (int)($value['offset'] ?? 0) : 0;
            if ($absolute >= 0x80000000) {
                $absolute -= 0x100000000;
            }

            if ($absolute >= -$curPos && $absolute < $this->intelFileSize) {
                $relative = $absolute >= 0
                    ? $absolute - $curPos
                    : $absolute + $this->intelFileSize;
                if ($relative < 0) {
                    $relative += 0x100000000;
                }
                $relative &= 0xFFFFFFFF;
                $frame[$index + 1] = chr($relative & 0xFF);
                $frame[$index + 2] = chr(($relative >> 8) & 0xFF);
                $frame[$index + 3] = chr(($relative >> 16) & 0xFF);
                $frame[$index + 4] = chr(($relative >> 24) & 0xFF);
            }

            $index += 5;
            $curPos += 5;
        }

        $this->intelCurPos += $frameSize;
        return $frame;
    }

    private function buildPositionTables(): void
    {
        $maxSlots = max(self::POSITION_SLOTS);
        $this->extraBits = array_fill(0, $maxSlots, 0);
        $this->positionBase = array_fill(0, $maxSlots, 0);

        for ($slot = 4; $slot < $maxSlots; $slot++) {
            $this->extraBits[$slot] = min(17, intdiv($slot, 2) - 1);
        }

        $this->positionBase[0] = 0;
        for ($slot = 1; $slot < $maxSlots; $slot++) {
            $this->positionBase[$slot] = $this->positionBase[$slot - 1]
                + (1 << $this->extraBits[$slot - 1]);
        }
    }
}
