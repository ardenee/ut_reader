<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$root = rtrim((string) getenv('UNREALDB_EPIC_SOURCE_ROOT'), '/\\');
$asyncCpp = $root . '/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$asyncHdr = $root . '/Engine/Source/Runtime/CoreUObject/Public/Serialization/AsyncLoading2.h';
$cityCpp = $root . '/Engine/Source/Runtime/Core/Private/Hash/CityHash.cpp';

$files = [
    $asyncCpp => '43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9',
    $asyncHdr => '088f00a8c970458e4bb717490ac4d50361d635ddf8cb0b7b1a5c93ff4d9feef2',
    $cityCpp => '035965ffa9875cc2ad93694a44a45cd8bdc042101e4b84d355b22d089f070d62',
];

$loaded = [];
foreach ($files as $path => $expectedHash) {
    $data = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($data) || hash('sha256', $data) !== $expectedHash) {
        fwrite(STDERR, "Source mismatch: {$path}\n");
        exit(2);
    }
    $loaded[$path] = str_replace("\r\n", "\n", $data);
}

$extractFunction = static function (string $source, string $signature): string {
    $start = strpos($source, $signature);
    if ($start === false) {
        throw new RuntimeException("Signature not found: {$signature}");
    }
    $brace = strpos($source, '{', $start);
    if ($brace === false) {
        throw new RuntimeException("Opening brace not found: {$signature}");
    }
    $depth = 0;
    $length = strlen($source);
    for ($i = $brace; $i < $length; ++$i) {
        if ($source[$i] === '{') {
            ++$depth;
        } elseif ($source[$i] === '}') {
            --$depth;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException("Closing brace not found: {$signature}");
};

try {
    $cellBody = $extractFunction(
        $loaded[$asyncCpp],
        'void AddScriptCellPackage(Verse::VPackage* Package)'
    );
    $hashBody = $extractFunction(
        $loaded[$asyncCpp],
        'uint64 FPackageObjectIndex::GenerateImportHashFromVersePath(FUtf8StringView VersePath)'
    );
    $fromVerseBody = $extractFunction(
        $loaded[$asyncHdr],
        'inline static FPackageObjectIndex FromVersePath(FUtf8StringView VersePath)'
    );
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

$citySource = $loaded[$cityCpp];
$citySource = str_replace(
    '#include "Hash/CityHash.h"',
    '// Harness supplies declarations copied from the pinned CityHash.h contract.',
    $citySource
);
$citySource = str_replace(
    '#include "HAL/UnrealMemory.h"',
    '// Harness supplies FPlatformMemory::Memcpy only.',
    $citySource
);

$cpp = <<<'CPP'
#include <cassert>
#include <cstdint>
#include <cstring>
#include <iomanip>
#include <iostream>
#include <map>
#include <string>
#include <utility>
#include <vector>

using int32 = int32_t;
using uint8 = uint8_t;
using uint32 = uint32_t;
using uint64 = uint64_t;

struct Uint128_64
{
    inline Uint128_64(uint64 InLo, uint64 InHi) : lo(InLo), hi(InHi) {}
    uint64 lo;
    uint64 hi;
};

inline uint64 CityHash128to64(const Uint128_64& x)
{
    const uint64 kMul = 0x9ddfea08eb382d69ULL;
    uint64 a = (x.lo ^ x.hi) * kMul;
    a ^= (a >> 47);
    uint64 b = (x.hi ^ a) * kMul;
    b ^= (b >> 47);
    b *= kMul;
    return b;
}

uint64 CityHash64(const char* buf, uint32 len);
uint64 CityHash64WithSeed(const char* buf, uint32 len, uint64 seed);
uint64 CityHash64WithSeeds(const char* buf, uint32 len, uint64 seed0, uint64 seed1);
uint32 CityHash32(const char* buf, uint32 len);

struct FPlatformMemory
{
    static void Memcpy(void* destination, const void* source, size_t size)
    {
        std::memcpy(destination, source, size);
    }
};

__EPIC_CITYHASH_SOURCE__

struct FUtf8StringView
{
    std::string Text;

    FUtf8StringView() = default;
    FUtf8StringView(const char* value) : Text(value) {}
    explicit FUtf8StringView(std::string value) : Text(std::move(value)) {}

    const char* GetData() const { return Text.data(); }
    uint32 Len() const { return static_cast<uint32>(Text.size()); }
};

class FPackageObjectIndex
{
    static constexpr uint64 IndexBits = 62ull;
    static constexpr uint64 IndexMask = (1ull << IndexBits) - 1ull;
    static constexpr uint64 TypeShift = IndexBits;
    static constexpr uint64 Invalid = ~0ull;

    uint64 TypeAndId = Invalid;

    enum EType
    {
        Export,
        ScriptImport,
        PackageImport,
        Null,
        TypeCount = Null,
    };

    inline explicit FPackageObjectIndex(EType InType, uint64 InId)
        : TypeAndId((uint64(InType) << TypeShift) | InId)
    {
    }

    static uint64 GenerateImportHashFromVersePath(FUtf8StringView VersePath);

public:
__EPIC_FROM_VERSE_PATH__

    uint64 GetRaw() const { return TypeAndId; }
    uint64 GetId() const { return TypeAndId & IndexMask; }
    uint64 GetType() const { return TypeAndId >> TypeShift; }

    bool operator<(const FPackageObjectIndex& rhs) const { return TypeAndId < rhs.TypeAndId; }
    bool operator==(const FPackageObjectIndex& rhs) const { return TypeAndId == rhs.TypeAndId; }
};

__EPIC_HASH_FUNCTION__

#define LLM_SCOPE_BYTAG(x) ((void)0)

namespace Verse
{
    struct VCell
    {
        int Value;
    };

    struct VUniqueString
    {
        std::string Text;
        FUtf8StringView AsStringView() const { return FUtf8StringView(Text); }
    };

    struct VValue
    {
        VCell* Ptr;
        VCell* ExtractCell() const { return Ptr; }
    };

    struct VPackage
    {
        std::vector<std::pair<VUniqueString, VValue>> Definitions;

        int32 NumDefinitions() const { return static_cast<int32>(Definitions.size()); }
        VUniqueString GetDefinitionName(int32 index) const { return Definitions.at(index).first; }
        VValue GetDefinition(int32 index) const { return Definitions.at(index).second; }
    };
}

struct FCellMap
{
    std::map<FPackageObjectIndex, Verse::VCell*> Values;

    void Add(FPackageObjectIndex index, Verse::VCell* cell)
    {
        Values[index] = cell;
    }
};

struct Store
{
    FCellMap ScriptCells;
__EPIC_CELL_FUNCTION__
};

int main()
{
    Store store;
    Verse::VCell a{11}, b{22}, c{33};

    Verse::VPackage empty;
    store.AddScriptCellPackage(&empty);
    assert(store.ScriptCells.Values.empty());

    const FUtf8StringView pathA("/Verse.org/Verse/a");
    const FUtf8StringView pathB("/Verse.org/Verse/b");
    const FPackageObjectIndex keyA = FPackageObjectIndex::FromVersePath(pathA);
    const FPackageObjectIndex keyB = FPackageObjectIndex::FromVersePath(pathB);

    assert(keyA.GetType() == 1);
    assert(keyB.GetType() == 1);
    assert(keyA.GetId() == 0x3aa68938e584f3bdULL);
    assert(keyA.GetId() == (CityHash64(pathA.GetData(), pathA.Len()) & ~(3ull << 62ull)));
    assert(keyB.GetId() == (CityHash64(pathB.GetData(), pathB.Len()) & ~(3ull << 62ull)));
    assert(!(keyA == keyB));

    Verse::VPackage first;
    first.Definitions.push_back({{"/Verse.org/Verse/a"}, {&a}});
    first.Definitions.push_back({{"/Verse.org/Verse/b"}, {&b}});
    store.AddScriptCellPackage(&first);

    assert(store.ScriptCells.Values.size() == 2);
    assert(store.ScriptCells.Values.at(keyA) == &a);
    assert(store.ScriptCells.Values.at(keyB) == &b);

    Verse::VPackage replacement;
    replacement.Definitions.push_back({{"/Verse.org/Verse/a"}, {&c}});
    store.AddScriptCellPackage(&replacement);

    assert(store.ScriptCells.Values.size() == 2);
    assert(store.ScriptCells.Values.at(keyA) == &c);

    std::cout
        << "EPIC_B015_SCRIPT_CELLS_SOURCE_BODY"
        << "|empty=1"
        << "|two_distinct=1"
        << "|duplicate_overwrite=1"
        << "|actual_cityhash=1"
        << "|actual_from_verse_path=1"
        << "|hash_a=0x" << std::hex << keyA.GetId()
        << "|count=" << std::dec << store.ScriptCells.Values.size()
        << "\n";
}
CPP;

$cpp = str_replace(
    ['__EPIC_CITYHASH_SOURCE__', '__EPIC_FROM_VERSE_PATH__', '__EPIC_HASH_FUNCTION__', '__EPIC_CELL_FUNCTION__'],
    [$citySource, $fromVerseBody, $hashBody, $cellBody],
    $cpp
);

$tmp = sys_get_temp_dir() . '/uedb-b015-cell-native-' . getmypid();
@mkdir($tmp);
$main = $tmp . '/main.cpp';
$exe = $tmp . '/run.exe';
$obj = $tmp . '/main.obj';
$cmd = $tmp . '/compile.cmd';
file_put_contents($main, $cpp);

$vcvars = 'C:/Program Files/Microsoft Visual Studio/18/Community/VC/Auxiliary/Build/vcvars64.bat';
if (!is_file($vcvars)) {
    fwrite(STDERR, "MSVC vcvars64.bat not found\n");
    exit(2);
}

$batch = '@echo off' . "\r\n"
    . 'call "' . $vcvars . '" >nul' . "\r\n"
    . 'cl.exe /nologo /EHsc /std:c++20 "' . $main . '" /Fo:"' . $obj . '" /Fe:"' . $exe . '"' . "\r\n";
file_put_contents($cmd, $batch);

exec('cmd.exe /d /c ' . escapeshellarg($cmd) . ' 2>&1', $compilerOutput, $compileExit);
if ($compileExit !== 0) {
    echo json_encode([
        'ok' => false,
        'compile_exit' => $compileExit,
        'compiler_output' => $compilerOutput,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(1);
}

exec(escapeshellarg($exe) . ' 2>&1', $runOutput, $runExit);
$marker = 'EPIC_B015_SCRIPT_CELLS_SOURCE_BODY|empty=1|two_distinct=1|duplicate_overwrite=1|actual_cityhash=1|actual_from_verse_path=1|hash_a=0x3aa68938e584f3bd|count=2';
$ok = $runExit === 0 && str_contains(implode("\n", $runOutput), $marker);

echo json_encode([
    'ok' => $ok,
    'operation' => 'UE5-583-B015-1',
    'disposition' => 'partial',
    'source_identical_epic_function_executed' => true,
    'source_identical_epic_verse_hash_executed' => true,
    'source_identical_epic_cityhash_compiled' => true,
    'real_verse_vm_executed' => false,
    'vni_codegen_provenance_verified' => false,
    'native_result' => $runOutput,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

@unlink($cmd);
@unlink($exe);
@unlink($obj);
@unlink($main);
@rmdir($tmp);

exit($ok ? 0 : 1);
