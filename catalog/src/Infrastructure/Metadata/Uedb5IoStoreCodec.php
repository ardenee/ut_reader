<?php
/**
 * Codec boundary for isolated UE5 IoStore staging reads.
 * Missing optional codecs fail closed instead of treating compressed bytes as package data.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use FFI;
use RuntimeException;
use Throwable;

final class Uedb5IoStoreCodec
{
    /** @var array<string,FFI> */
    private static array $oodleLibraries = [];

    public function __construct(
        private readonly ?string $oodleLibraryPath = null
    ) {
    }

    public function decode(string $method, string $compressed, int $uncompressedSize): string
    {
        if ($uncompressedSize < 0) {
            throw new RuntimeException('IoStore uncompressed size cannot be negative.');
        }

        $normalized = strtolower(trim($method));
        if ($normalized === '' || $normalized === 'none') {
            if (strlen($compressed) < $uncompressedSize) {
                throw new RuntimeException('IoStore uncompressed block is shorter than its declared size.');
            }
            return substr($compressed, 0, $uncompressedSize);
        }

        if ($normalized === 'zlib') {
            $decoded = @gzuncompress($compressed);
            if (!is_string($decoded) || strlen($decoded) !== $uncompressedSize) {
                throw new RuntimeException('IoStore zlib block failed to decompress to its declared size.');
            }
            return $decoded;
        }

        if ($normalized === 'gzip') {
            $decoded = @gzdecode($compressed);
            if (!is_string($decoded) || strlen($decoded) !== $uncompressedSize) {
                throw new RuntimeException('IoStore gzip block failed to decompress to its declared size.');
            }
            return $decoded;
        }

        if ($normalized === 'oodle') {
            return $this->decodeOodle($compressed, $uncompressedSize);
        }

        throw new RuntimeException('Unsupported IoStore compression method: ' . $method);
    }

    private function decodeOodle(string $compressed, int $uncompressedSize): string
    {
        $path = trim((string)$this->oodleLibraryPath);
        if ($path === '') {
            $path = trim((string)getenv('UNREALDB_OODLE_LIBRARY'));
        }
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException(
                'IoStore Oodle decoding requires UNREALDB_OODLE_LIBRARY or an explicit Oodle runtime DLL path.'
            );
        }
        if (!class_exists(FFI::class)) {
            throw new RuntimeException('PHP FFI is required for IoStore Oodle decoding.');
        }

        try {
            $ffi = self::$oodleLibraries[$path] ??= FFI::cdef(
                'long long OodleLZ_Decompress('
                . 'const void *compBuf, long long compBufSize, void *rawBuf, long long rawLen, '
                . 'int fuzzSafe, int checkCRC, int verbosity, void *decBufBase, long long decBufSize, '
                . 'void *fpCallback, void *callbackUserData, void *decoderMemory, long long decoderMemorySize, '
                . 'int threadPhase);',
                $path
            );

            $inputLength = strlen($compressed);
            $input = FFI::new('uint8_t[' . max(1, $inputLength) . ']');
            $output = FFI::new('uint8_t[' . max(1, $uncompressedSize) . ']');
            if ($inputLength > 0) {
                FFI::memcpy($input, $compressed, $inputLength);
            }

            $decodedLength = (int)$ffi->OodleLZ_Decompress(
                $input,
                $inputLength,
                $output,
                $uncompressedSize,
                1, // OodleLZ_FuzzSafe_Yes
                0, // OodleLZ_CheckCRC_No
                0, // OodleLZ_Verbosity_None
                null,
                0,
                null,
                null,
                null,
                0,
                3  // OodleLZ_Decode_Unthreaded
            );
            if ($decodedLength !== $uncompressedSize) {
                throw new RuntimeException(
                    'IoStore Oodle block returned ' . $decodedLength
                    . ' bytes; expected ' . $uncompressedSize . '.'
                );
            }
            return FFI::string($output, $uncompressedSize);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException('IoStore Oodle decoder failed: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
