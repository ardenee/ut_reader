<?php
declare(strict_types=1);

/**
 * Unreal II .con music assets are RIFF containers (normally form DMCN), not
 * Unreal package files. They therefore do not begin with 0x9E2A83C1 and do not
 * contain UE Names/Imports/Exports tables.
 */

function riff_con_read_u32le(string $bytes, int $offset): ?int
{
    if ($offset < 0 || $offset + 4 > strlen($bytes)) {
        return null;
    }
    return (int)unpack('V', substr($bytes, $offset, 4))[1];
}

function riff_con_ascii_fourcc(string $value): string
{
    return preg_replace('/[^\x20-\x7E]/', '.', $value) ?? '';
}

function riff_con_trim_text(string $value): string
{
    $value = rtrim($value, "\0 \t\r\n");
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', ' ', $value) ?? '';
    return trim($value);
}

function riff_con_guid_from_bytes(string $bytes): string
{
    if (strlen($bytes) < 16) {
        return '';
    }
    $parts = unpack('Vfirst/vsecond/vthird/C8tail', substr($bytes, 0, 16));
    if (!is_array($parts)) {
        return '';
    }
    $tail = '';
    for ($index = 1; $index <= 8; $index++) {
        $tail .= sprintf('%02X', (int)($parts['tail' . $index] ?? 0));
    }
    return sprintf('%08X-%04X-%04X-%s-%s', (int)$parts['first'], (int)$parts['second'], (int)$parts['third'], substr($tail, 0, 4), substr($tail, 4));
}

/**
 * @return array{kind:string,form_type:string,container_guid:string,title:string,chunk_count:int,chunks:list<string>,notes:list<string>}|null
 */
function riff_con_parse(string $path, string $originalName = ''): ?array
{
    $extension = strtolower(pathinfo($originalName !== '' ? $originalName : $path, PATHINFO_EXTENSION));
    if ($extension !== 'con' || !is_file($path)) {
        return null;
    }

    $header = @file_get_contents($path, false, null, 0, 12);
    if (!is_string($header) || strlen($header) < 12 || substr($header, 0, 4) !== 'RIFF') {
        return null;
    }

    $declaredSize = riff_con_read_u32le($header, 4);
    $formType = substr($header, 8, 4);
    $fileSize = (int)(filesize($path) ?: 0);
    $limit = min($fileSize, max(12, (int)($declaredSize ?? 0) + 8), 16 * 1024 * 1024);
    $bytes = @file_get_contents($path, false, null, 0, $limit);
    if (!is_string($bytes) || strlen($bytes) < 12) {
        return [
            'kind' => 'RIFF CON asset',
            'form_type' => riff_con_ascii_fourcc($formType),
            'container_guid' => '',
            'title' => '',
            'chunk_count' => 0,
            'chunks' => [],
            'notes' => ['RIFF header is present, but the container could not be read.'],
        ];
    }

    $chunks = [];
    $guid = '';
    $title = '';
    $offset = 12;
    $count = 0;
    $maxChunks = 2048;
    while ($offset + 8 <= strlen($bytes) && $count < $maxChunks) {
        $chunkId = substr($bytes, $offset, 4);
        $chunkSize = riff_con_read_u32le($bytes, $offset + 4);
        if ($chunkSize === null) {
            break;
        }
        $dataStart = $offset + 8;
        $dataEnd = $dataStart + $chunkSize;
        if ($dataEnd > strlen($bytes)) {
            $chunks[] = riff_con_ascii_fourcc($chunkId) . ' (truncated)';
            break;
        }

        $id = riff_con_ascii_fourcc($chunkId);
        if (count($chunks) < 32) {
            $chunks[] = $id;
        }
        if (strtolower($chunkId) === 'guid') {
            $guid = riff_con_guid_from_bytes(substr($bytes, $dataStart, min(16, $chunkSize)));
        }
        if (in_array($chunkId, ['UNAM', 'INAM'], true)) {
            $candidate = riff_con_trim_text(substr($bytes, $dataStart, min($chunkSize, 4096)));
            if ($candidate !== '') {
                $title = $candidate;
            }
        }

        /* LIST payload starts with its list type followed by nested chunks. */
        if ($chunkId === 'LIST' && $chunkSize >= 4 && substr($bytes, $dataStart, 4) === 'UNFO') {
            $nestedOffset = $dataStart + 4;
            while ($nestedOffset + 8 <= $dataEnd) {
                $nestedId = substr($bytes, $nestedOffset, 4);
                $nestedSize = riff_con_read_u32le($bytes, $nestedOffset + 4);
                if ($nestedSize === null || $nestedOffset + 8 + $nestedSize > $dataEnd) {
                    break;
                }
                if (count($chunks) < 32) {
                    $chunks[] = 'UNFO/' . riff_con_ascii_fourcc($nestedId);
                }
                if (in_array($nestedId, ['UNAM', 'INAM'], true)) {
                    $candidate = riff_con_trim_text(substr($bytes, $nestedOffset + 8, min($nestedSize, 4096)));
                    if ($candidate !== '') {
                        $title = $candidate;
                    }
                }
                $nestedOffset += 8 + $nestedSize + ($nestedSize % 2);
            }
        }

        $count++;
        $offset = $dataEnd + ($chunkSize % 2);
    }

    $notes = ['RIFF form=' . riff_con_ascii_fourcc($formType) . '; no UE package Names/Imports/Exports tables.'];
    if ($declaredSize !== null && $declaredSize + 8 > $fileSize) {
        $notes[] = 'RIFF declared size exceeds the physical file size; container may be truncated.';
    }

    return [
        'kind' => 'Unreal II RIFF CON music asset',
        'form_type' => riff_con_ascii_fourcc($formType),
        'container_guid' => $guid,
        'title' => $title,
        'chunk_count' => $count,
        'chunks' => array_values(array_unique($chunks)),
        'notes' => $notes,
    ];
}
