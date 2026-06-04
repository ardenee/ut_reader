<?php

/**
 * Provides the means of directly accessing and parsing the content of package
 * files.
 * <p>
 * Manages the buffers and navigation and read operations within package files
 * required for parsing a package's contents.
 */
class PackageReader {

    private const READ_BUFFER = 1024 * 8; // use an 8k read buffer
    private const HEX_ARRAY = "0123456789ABCDEF"; // used for hash encoding

    public    $stats;
    private   $fileChannel;
    private   $buffer;
    private   $channel;
    protected $version      = 0;
    protected $chunks       = null;
    private   $cacheChunks;
    private   $chunkCache;

    /**
     * Creates a new package reader for an Unreal package, represented by the
     * provided {@link FileChannel}.
     *
     * @param fileChannel unreal package file
     * @param cacheChunks if true, decompressed chunks from compressed packages
     *                    will be kept in memory for reuse, rather than
     *                    discarded for potential garbage collection after
     *                    moving to another chunk. this increases memory
     *                    overhead but may improve read performance.
     */

    //default - File only
    public function __construct(string $packageFile) /*throws IOException*/ {
		fopen($packageFile, 'r');
        $this->__construct2(fopen($packageFile, 'r'), false);
    }

   // public function __construct(Path $packageFile, bool $cacheChunks) /*throws IOException*/ {
   //     $this->__construct2(FileChannel::open($packageFile, StandardOpenOption::READ), $cacheChunks);
    //}

   // public function __construct(SeekableByteChannel $fileChannel) {
   //     $this->__construct2($fileChannel, false);
   // }
	
	//main
    public function __construct2($fileChannel, bool $cacheChunks) {
        $this->fileChannel = $fileChannel;
        $this->channel     = $fileChannel;
        $this->cacheChunks = $cacheChunks;		
		$this->buffer = array_fill(0, (1024 * 8), 0);		
        //$this->buffer      = ByteBuffer::allocateDirect(self::READ_BUFFER)->order(ByteOrder::LITTLE_ENDIAN);
        $this->stats       = new ReaderStats();
        $this->chunkCache  = array();
    }




    public function close() /*throws IOException*/ {
        if ($this->channel != null && $this->channel->isOpen()) $this->channel->close();
        $this->fileChannel->close();
    }

    /**
     * Calculate a hash of the file.
     *
     * @param alg hash algorithm, eg. SHA-1 or MD5
     * @return string representation of file hash
     */
    public function hash(string $alg) {
        try {
            $md = MessageDigest::getInstance($alg);

            $this->fileChannel->position(0);
            $this->buffer->clear();
            while ($this->fileChannel->read($this->buffer) > 0) {
                $this->buffer->flip();
                $md->update($this->buffer);
                $this->buffer->clear();
            }

            return $this->bytesToHex($md->digest())->toLowerCase();
        } catch (Exception $e) {
            throw new RuntimeException("Failed to generate hash for package.", $e);
        }
    }

    public function setChunks(CompressedChunk $chunks) {
        $this->chunks = $chunks;
        $this->stats->chunkCount = count($chunks);
    }

    // --- buffer positioning and management

    /**
     * Return the total size of the package file.
     *
     * @return file size
     */
    public function size() {
        try {
            return $this->fileChannel->size();
        } catch (IOException $e) {
            throw new IllegalStateException("Could not determine size of package.");
        }
    }

    /**
     * Get the current read position within the current buffer.
     * <p>
     * Use {@link #currentPosition()} to find the current global
     * read position.
     *
     * @return read position in buffer
     */
    public function position() {
        return $this->buffer->position();
    }

    /**
     * Gets the current global read position, which may be within the current
     * file for uncompressed packages or within compressed package headers, but
     * may be relative to the current chunk for compressed packages.
     *
     * @return read position in package
     */
    public function currentPosition() {
        try {
            if ($this->channel instanceof ChunkChannel) {
                return $this->channel->chunk->uncompressedOffset + ($this->channel->position() - $this->buffer->remaining());
            } else {
                return ($this->channel->position() - $this->buffer->remaining());
            }
        } catch (IOException $e) {
            throw new IllegalStateException("Could not determine current file position");
        }
    }

    /**
     * Move to a position in the file, clear the buffer, and read from there.
     *
     * @param pos position in file
     */
    public function moveTo1(int $pos) {
        $this->moveTo2($pos, false);
    }

    private function moveTo2(int $pos, bool $nonChunked) {
        $this->moveTo3($pos, $nonChunked, false);
    }

    private function moveTo3(int $pos, bool $nonChunked, bool $keepChannel) {
        if ($this->channel != $this->fileChannel && $nonChunked) $this->channel = $this->fileChannel;

        $movePos = new AtomicLong($pos);

        // maybe we want to be inside a chunk actually
        if (!$keepChannel && !$nonChunked && $this->chunks != null) {
            $chunk = Arrays::stream($this->chunks)
                ->filter(function ($c) use ($pos) {
                    return $pos >= $c->uncompressedOffset && $pos < $c->uncompressedOffset + $c->uncompressedSize;
                })
                ->findFirst();

            if ($chunk.isPresent()) {
                $compressedChunk = $chunk.get();
                // we're already in the chunk, no need to re-read it
                if (!($this->channel instanceof ChunkChannel) || $this->channel->chunk != $compressedChunk) {
                    $this->channel = $this->loadChunk2($compressedChunk, $this->cacheChunks);
                }

                $movePos->set($pos - $compressedChunk->uncompressedOffset);
            }
        }

        try {
            $this->channel->position($movePos->get());

            $this->buffer->clear();
            $this->channel->read($this->buffer);
            $this->buffer->flip();
        } catch (IOException $e) {
            throw new IllegalStateException("Could not move to position " . $pos . " within package file", $e);
        } finally {
            $this->stats->moveToCount++;
        }
    }

    /**
     * Move to a position in the file, relative to the current position,
     * and fill the buffer with data from that point on.
     *
     * @param amount amount to move forward by
     */
    public function moveRelative(int $amount) {
        try {
            // note: subtract remaining because the current position within the channel will align with the end of the last buffer fill
            $this->moveTo3($this->channel->position() - $this->buffer->remaining() + $amount, false, true);
        } catch (IOException $e) {
            throw new IllegalStateException("Could not move by " . $amount . " bytes within channel", $e);
        } finally {
            $this->stats->moveRelativeCount++;
        }
    }

    /**
     * Ensure at least the specified number of bytes are available for
     * subsequent read operations.
     *
     * @param minRemaining bytes
     */
    public function ensureRemaining(int $minRemaining) {
        try {
            if ($this->buffer->capacity() < $minRemaining) {
                throw new IllegalArgumentException("Impossible to fill buffer with " . $minRemaining . " bytes");
            }

            if ($this->buffer->remaining() < $minRemaining) $this->fillBuffer();
        } finally {
            $this->stats->ensureRemainingCount++;
        }
    }

    /**
     * Fill the read buffer with more data from the current position, retaining
     * currently unread bytes in the buffer.
     */
    public function fillBuffer() {
        try {
            $this->buffer->compact();
            $this->channel->read($this->buffer);
            $this->buffer->flip();
        } catch (IOException $e) {
            throw new IllegalStateException("Could not read from package file", $e);
        } finally {
            $this->stats->fillBufferCount++;
        }
    }

    // --- read operations

    /**
     * Reads a single byte at the current reader position and advances the
     * position by one byte.
     *
     * @return a byte
     */
    public function readByte() {
        return $this->buffer->get();
    }

    /**
     * Reads a 2-byte signed short value from the current reader position and
     * advances the position by two bytes.
     *
     * @return a signed short
     */
    public function readShort() {
        return $this->buffer->getShort();
    }

    /**
     * Reads a 4-byte signed integer value from the current reader position and
     * advances the reader position by 4 bytes.
     *
     * @return a singed integer
     */
    public function readInt() {
        return $this->buffer->getInt();
    }

    /**
     * Reads an 8-byte signed long value from the current reader position and
     * advances the reader position by 8 bytes.
     *
     * @return a singed long
     */
    public function readLong() {
        return $this->buffer->getLong();
    }

    /**
     * Reads a 4-byte signed float value from the current reader position and
     * advances the reader position by 4 bytes.
     *
     * @return a signed float
     */
    public function readFloat() {
        return $this->buffer->getFloat();
    }
	
    public function GetVersion() {
        return $this->version;
    }

    /**
     * Read <code>length</code> bytes from the package, placing them into the
     * destination byte array specified, at the <code>offset</code> within the
     * destination array.
     *
     * @param dest   destination array
     * @param offset position within destination to place read bytes
     * @param length number of bytes to read
     * @return number of bytes read
     */
    public function readBytes(byte $dest, int $offset, int $length) {
        $start = $this->currentPosition(); //buffer.remaining();

        $read = 0;
        while ($read < $length) {
            if ($this->buffer->remaining() < $length) $this->fillBuffer();
            $i = $this->currentPosition();
            $this->buffer->get($dest, $offset + $read, min($this->buffer->remaining(), $length - $read));
            $read += $this->currentPosition() - $i;
        }

        return $this->currentPosition() - $start;
    }

    /**
     * Reads a "Compact Index" integer value.
     * <p>
     * Refer to package documentation and reference for description of the
     * format.
     * <p>
     * For Unreal Engine 3 packages, compact indexes are no longer used,
     * rather we use plain 32-bit/4-byte integers.
     *
     * @return an index value
     */
    public function readIndex() {
        if ($this->version == 0) throw new IllegalStateException("Version is not set");

        if ($this->version > 178) return $this->readInt();

        $negative = false;
        $num = 0;
        $len = 6;
        for ($i = 0; $i < 5; $i++) {
            $more;

            $one = $this->buffer->get();

            if ($i == 0) {
                $negative = ($one & 0x80) > 0;
                $more = ($one & 0x40) > 0;
                $num = $one & 0x3F;
            } else if ($i == 4) {
                $num |= ($one & 0x80) << $len;
                $more = false;
            } else {
                $more = ($one & 0x80) > 0;
                $num |= ($one & 0x7F) << $len;
                $len += 7;
            }

            if (!$more) break;
        }

        return $negative ? $num * -1 : $num;
    }

    /**
     * Reads a name index from the current buffer position.
     * <p>
     * For Unreal Engines 1 and 2, this is the same as {@link #readIndex()},
     * but Unreal Engine 3 also contains an additional integer string number.
     *
     * @return index of name in names table
     */
    public function readNameIndex() {
        if ($this->version == 0) throw new IllegalStateException("Version is not set");

        if ($this->version < 343) return new NameNumber($this->readIndex());
        else {
            $index = $this->readIndex();
            $number = $this->readInt(); // for UE3, not used
            return new NameNumber($index, $number);
        }
    }

    public function readString() {
        return $this->readString1(-1);
    }

    /**
     * Read a string from the current buffer position.
     * <p>
     * String read operations differ between package versions, so {@link #version} should
     * be set prior to string read operations.
     *
     * @param length length of the string to read, or -1 to read it automatically
     * @return a string
     */
    public function readString1(int $length) {
        if ($this->version == 0) throw new IllegalStateException("Version is not set");

        $string = "";

        if ($this->version < 64) {
            // read to NUL/0x00
            /*$val = new byte[255];*/
			$val = array_fill(0, 255, 0);
            $len = 0;
			$v   = 0;
            while (($v = $this->readByte()) != 0x00) {
                $val[$len] = $v;
                $len++;
            }
            if ($len > 0) $string = new String(Arrays::copyOfRange($val, 0, $len), StandardCharsets::ISO_8859_1);
        } else {
            // Note: Oddity in some properties, where length byte reports longer than the property length
            $len = $this->version > 117 ? $this->readIndex() : ($length > -1 ? min($length, $this->readByte() & 0xFF) : $this->readByte() & 0xFF);

            // UE3 uses negative lengths to indicate unicode strings
            $charset = $len < 0 ? StandardCharsets::UTF_16LE : StandardCharsets::ISO_8859_1;
            $readLen = $len < 0 ? -(len * 2) : $len;

            if ($readLen != 0) {
                /*$val = new byte[$readLen];*/				
				$val = array_fill(0, $readLen, 0);
                $this->ensureRemaining($readLen);
                $this->buffer->get($val);
                $string = new String($val, $charset);
            }
        }

        return $string.trim();
    }

    // -- private helpers

    private static function bytesToHex(byte $bytes) {
        /*$hexChars = new char[count($bytes) * 2];*/
		/*
		$hexChars = array_fill(0, (count($bytes)*2), 0);		
		
        for ($i = 0; $i < count($bytes); $i++) {
            $v = $bytes[$i] & 0xFF;
            $hexChars[$i * 2]     = self::HEX_ARRAY[$v >>> 4];
            $hexChars[$i * 2 + 1] = self::HEX_ARRAY[$v & 0x0F];
        }
		*/
		
		
		$chars    = array_map("chr", $bytes); //Then make it a string:
		$bin      = join($chars); // And finally convert that to a hex string:
		$hexChars = bin2hex($bin);
		
        return new String($hexChars);
    }

    /**
     * For Unreal Engine 3 packages, loads data from a compressed chunk, and
     * returns a readable channel, within which normal package read
     * operations may be invoked.
     *
     * @param chunk chunk to load
     * @return a decompressed chunk
     */
    public function loadChunk1(CompressedChunk $chunk) {
        return $this->loadChunk2($chunk, false);
    }

    private function loadChunk2(CompressedChunk $chunk, bool $cache) {
        try {
            $chunkLoader = function () {
                try {
                    $this->moveTo2($chunk->compressedOffset, true);
                    if ($this->readInt() != Package::PKG_SIGNATURE)
                        throw new IllegalStateException("Chunk does not seem to be Unreal package data");
                    $blockSize = $this->readInt();
                    $compressedSize = $this->readInt();
                    $uncompressedSize = $this->readInt();
                    $numBlocks = ($uncompressedSize + $blockSize - 1) / $blockSize;
                    //$blockSizes = new int[$numBlocks * 2];					
					$blockSizes = array_fill(0, ($numBlocks * 2), 0);
					
                    for ($i = 0; $i < count($blockSizes); $i += 2) {
                        $blockSizes[$i] = $this->readInt(); // compressed size
                        $blockSizes[$i + 1] = $this->readInt(); // uncompressed size
                    }

                    return new ChunkChannel($this, $chunk, $uncompressedSize, $blockSizes);
                } finally {
                    $this->stats->chunkLoadCount++;
                }
            };

            return !$cache ? $chunkLoader() : $this->chunkCache->computeIfAbsent($chunk, $chunkLoader);
        } finally {
            $this->stats->chunkFetchCount++;
        }
    }
	
}

    class ReaderStats {

        public $moveToCount;
        public $moveRelativeCount;
        public $ensureRemainingCount;
        public $fillBufferCount;
        public $chunkCount;
        public $chunkLoadCount;
        public $chunkFetchCount;

        public function __toString() {
            return sprintf(
                "ReaderStats [moveToCount=%s, moveRelativeCount=%s, ensureRemainingCount=%s, fillBufferCount=%s, chunkCount=%s, chunkLoadCount=%s, chunkFetchCount=%s]",
                $this->moveToCount, $this->moveRelativeCount, $this->ensureRemainingCount, $this->fillBufferCount, $this->chunkCount, $this->chunkLoadCount, $this->chunkFetchCount);
        }
    }



$g = new PackageReader("test.utx");

echo $g->GetVersion();

?>