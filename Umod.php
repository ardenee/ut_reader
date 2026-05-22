<?php

namespace net\shrimpworks\unreal\packages;

use Exception;
use RuntimeException;
use SplFileObject;
use SeekableIterator;

/**
 * An Unreal modification package.
 * <p>
 * UMod files are used to bundle third party Unreal Engine 1 and 2 content,
 * normally for more complex modifications with many files, rather than
 * individual pieces of content like maps. The Unreal game in question will
 * unpack these packages into their installation directories.
 * <p>
 * They may hold any content, not just Unreal {@link Package}s.
 * <p>
 * This implementation supports reading the file list, and then reading the
 * individual files as {@link UmodFile}s, which may be either saved to disk
 * or used in conjunction with the {@link Package} class to inspect and
 * extract package contents without first unpacking the Umod.
 */
class Umod implements \Closeable {

    private const UMOD_SIGNATURE = 0x9FE3C5A3;
    private const SHA1 = 'sha1';

    private $reader;

    public $version;
    public $size;
    public $files;

    public $manifestIni;
    public $manifestInt;

    public function __construct($umodFile) {
        if (is_string($umodFile)) {
            $this->reader = new PackageReader($umodFile);
        } else {
            $this->reader = $umodFile;
        }

        $this->reader->moveTo($this->reader->size() - 20);

        if ($this->reader->readInt() != self::UMOD_SIGNATURE) {
            throw new \InvalidArgumentException("Package does not seem to be a UMOD package");
        }

        $filesOffset = $this->reader->readInt();

        $this->size = $this->reader->readInt(); // this is actually just the filesize; perhaps useful for validation
        $this->version = $this->reader->readInt();
        $this->reader->version = $this->version; // not strictly accurate, since this version is "1", but we only need it from `readIndex`

        $checksum = $this->reader->readInt(); // cool story bro

        // read the files directory/table
        $this->reader->moveTo($filesOffset);

        // read number of entries within the file
        $fileCount = $this->reader->readIndex();
        $this->files = [];

        // keep reading until we get back to the header
        for ($i = 0; $i < $fileCount; $i++) {
            $this->reader->ensureRemaining(270); // enough to read a full file path and the other bytes
            $file = $this->readFile();
            $this->files[] = $file;
        }

        $this->manifestIni = array_filter($this->files, function($f) {
            return strtolower(substr($f->name, -12)) === 'manifest.ini';
        });
        $this->manifestIni = reset($this->manifestIni) ?: null;

        $this->manifestInt = array_filter($this->files, function($f) {
            return strtolower(substr($f->name, -12)) === 'manifest.int';
        });
        $this->manifestInt = reset($this->manifestInt) ?: null;
    }

    public function close() {
        $this->reader->close();
    }

    private function readFile() {
        $nameSize = $this->reader->readIndex();
        $val = $this->reader->readBytes($nameSize);
        $name = trim($val);
        $offset = $this->reader->readInt();
        $size = $this->reader->readInt();
        $flags = $this->reader->readInt();

        return new UmodFile($name, $size, $offset, $flags);
    }

    /**
     * Represents a single file entry in a Umod package.
     */
    public class UmodFile {

        public $name;
        public $size;

        private $offset;
        private $flags;

        public function __construct($name, $size, $offset, $flags) {
            $this->name = $name;
            $this->size = $size;
            $this->offset = $offset;
            $this->flags = $flags;
        }

        /**
         * Get a byte channel exposing the contents of this file.
         * <p>
         * This channel may be used in conjunction with a {@link PackageReader}
         * and {@link Package} to inspect the contents of Unreal packages
         * without the need to extract them first, or may simply be written to
         * a file on disk.
         *
         * @return SeekableByteChannel
         */
        public function read() {
            // provide a channel which presents the contents of this file as a standalone-seeming channel
            return new UmodFileChannel($this->reader, $this->offset, $this->size);
        }

        /**
         * Utility to get the SHA-1 hash for this file.
         *
         * @return string sha1 hash string
         * @throws IOException read failure
         */
        public function sha1() {
            $reader = new PackageReader($this->read());
            return $reader->hash(self::SHA1);
        }

        public function __toString() {
            return sprintf("UmodFile [name=%s]", $this->name);
        }
    }

    /**
     * A simple channel implementation which remains within the bounds of a
     * file within a Umod archive.
     */
    private static class UmodFileChannel implements SeekableIterator {

        private $reader;

        private $offset;
        private $size;

        public function __construct($reader, $offset, $size) {
            $this->reader = $reader;
            $this->offset = $offset;
            $this->size = $size;

            $this->reader->moveTo($offset);
        }

        public function read($dst) {
            if ($this->position() == $this->size) return -1;

            $cnt = $dst->position();
            $remain = $this->size - $this->position();
            $buff = min($dst->remaining(), $remain); // possibly expensive if using a large buffer

            $this->reader->ensureRemaining($buff);
            $read = $this->reader->readBytes($buff);
            $dst->put($read);

            return $dst->position() - $cnt;
        }

        public function position() {
            return $this->reader->currentPosition() - $this->offset;
        }

        public function position($newPosition) {
            if ($newPosition > $this->size) throw new \InvalidArgumentException("Cannot seek beyond size " . $this->size);
            $this->reader->moveTo($this->offset + $newPosition);
            return $this;
        }

        public function size() {
            return $this->size;
        }

        public function truncate($size) {
            throw new \RuntimeException("Truncate not supported");
        }

        public function write($src) {
            throw new \RuntimeException("Write not supported");
        }

        public function isOpen() {
            return true;
        }

        public function close() {
            // no-op
        }
    }
}
?>


