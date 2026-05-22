<?php

const PACKAGE_VERSION_THRESHOLD = 68; // Version threshold for Unreal packages

$file = 'test.utx';    // guid {E484D857-00B7-4107-A58A-36FF29F6A3A5}
//$file = 'oldtest.utx'; // guid {1E90ACA4-ED66-11D1-9727-444553540000}
//$file = 'oldtest2.utx'; // guid {1E90ACA4-ED66-11D1-9727-444553540000}
$fp = fopen($file, 'rb');
echo "<pre>\n";

$sig = readDWORD($fp); // Signature
if ($sig !== 0x9E2A83C1) {
    die('Not an Unreal File.');
} else {
    echo 'Unreal File found. (0x' . StrToUpper(dechex($sig)) . ')' . PHP_EOL;
}
echo "\n";

echo 'Version:          ' . ($version = readWORD($fp)) . PHP_EOL; // File Versions
echo 'License mode:     ' . ($licensemode = readWORD($fp)) . PHP_EOL;
echo 'Package flags:    ' . ($pflags = readDWORD($fp));
echo " - " . GetPackageFlags($pflags) . PHP_EOL;
echo 'Name count:       ' . ($namecount = readDWORD($fp)) . PHP_EOL;
echo 'Name offset:      ' . ($nameoffset = readDWORD($fp)) . PHP_EOL;
echo 'Export count:     ' . ($exportcount = readDWORD($fp)) . PHP_EOL;
echo 'Export offset:    ' . ($exportoffset = readDWORD($fp)) . PHP_EOL;
echo 'Import count:     ' . ($importcount = readDWORD($fp)) . PHP_EOL;
echo 'Import offset:    ' . ($importoffset = readDWORD($fp)) . PHP_EOL;
echo "\n";

if ($version < PACKAGE_VERSION_THRESHOLD) { // old format
    echo 'Heritage count:   ' . ($heritagecount = readDWORD($fp)) . PHP_EOL;
    echo 'Heritage offset:  ' . readDWORD($fp) . PHP_EOL;
    $guid = "";

    for ($i = 0; $i < $heritagecount; $i++) { // always last one
        $guid = readGUID($fp);
    }

    echo 'GUID:             ' . $guid . PHP_EOL; // loop through finding last one!
    echo "\n";
} else { // newer format
    echo 'GUID:             ' . readGUID($fp) . PHP_EOL;
    echo "\n";
    echo '<b>*******Generation count: ' . ($generations = readDWORD($fp))."</b>" . PHP_EOL;

    for ($i = 0; $i < $generations; $i++) {
        echo 'Import offset(Export Count):    ' . readDWORD($fp) . PHP_EOL;
        echo 'Import count (Name Count)  :    ' . readDWORD($fp) . PHP_EOL;
        echo "\n";
    }
}

$names = array();
// Name Table
echo "<b>*******Name Table (" . $namecount . ":" . $nameoffset . ")</b>";
for ($nc = 0; $nc < $namecount; $nc++) {
    $StrText = readNAME($fp);
    echo "\n" . $nc . " Name Text: " . $StrText . " (" . strlen($StrText) . ") - ";
    $flags   = readDWORD($fp);
    $fgs     = GetObjectFlags($flags);
    echo $fgs;
	$names[] = $StrText;
}





//print_r(readExportTable($fp, $exportoffset, $exportcount));

fseek($fp, $exportoffset);

function decformat($foo)
{
	$bar = '00000000' . dechex($foo);

	return "0x".substr($bar, -8);
}


// Export Table
echo "\n\n<b>*******Export Table (" . $exportcount . ":" . $exportoffset . ")</b>\n";
for ($nc = 0; $nc < $exportcount; $nc++) {
    echo 'Class :         ' . ($ClassRef       = readCompactInteger($fp)) ." (".ObjectReferences($ClassRef).")".  PHP_EOL; // INDEX
    echo 'Super :         ' . ($ClassParentRef = readCompactInteger($fp)) ." (".ObjectReferences($ClassParentRef).")".  PHP_EOL;
    echo 'Package :       ' . ($PackageRef     = readDWORD($fp))          ." (".$names[$PackageRef].")". PHP_EOL;
    echo 'Object Name :   ' . ($ObjNameRef     = readCompactInteger($fp)) ." (".$names[$ObjNameRef].")". PHP_EOL;
    echo 'Object Flags :  (' . ($ObjectFlags   = readDWORD($fp))          . ") ";

    $fgs = GetObjectFlags($ObjectFlags);
    echo $fgs. PHP_EOL;;

    echo 'Serial Size :   ' . ($SerialSize   = readCompactInteger($fp)) . PHP_EOL;
    echo 'Serial Offset : ' . ($SerialOffset = readCompactInteger($fp)) . " (". decformat($SerialOffset).")".PHP_EOL . PHP_EOL;
}

//------------------------------------------------------------------------------------------------------

echo "\n\n<b>*******Import Table (" . $importcount . ":" . $importoffset . ")</b>\n";

fseek($fp, $importoffset);

for ($nc = 0; $nc < $importcount; $nc++) 
{
	$ci = readIndex($fp);
	echo "Class Package: ".$ci ." (".$names[$ci].")".  PHP_EOL;

	$ci = readIndex($fp);
	echo "Class Name   : ".$ci ." (".$names[$ci].")".  PHP_EOL;



    $flags = readDWORD($fp);
    $fgs   = GetObjectFlags($flags);
    //echo $fgs;

	//$ci = readDWORD($fp);
	echo "Package      : ".$fgs . PHP_EOL;

	$ci = readIndex($fp);
	echo "Object Name  : ".$ci." (".$names[$ci].")". PHP_EOL. PHP_EOL;
}

function ObjectReferences($obj)
{
	if($obj==0)
		return "NULL";
	
	if($obj<0)
		return "Import table in the position (–index-1)";
	
	if($obj>0)
		return "Export table in the position (index-1)";
}


function readIndex_1($file) {
    $output = 0;
    $signed = false;
    $shift  = 0; // This will track how many bits to shift for each byte

    // Read bytes until we reach the last one
    while (true) {
        $x = ord(fread($file, 1)); // Read the next byte

        // If this is the first byte, determine the sign and most significant 6 bits
        if ($shift === 0) {
            if ($x & 0x80) { // Check if the sign bit is set
                $signed = true;
            }

            // Add the least significant 6 bits of this byte to the output
            $output |= ($x & 0x3F);
            $shift += 6; // We've added 6 bits so far

            // Check if more bytes follow (bit 6)
            if (($x & 0x40) === 0) {
                break; // No more bytes follow
            }
        } else {
            // For subsequent bytes, process the 7 bits of data (bit 7 = continuation flag)
            $output |= ($x & 0x7F) << $shift;
            $shift += 7; // We've added 7 more bits

            // If the most significant bit of this byte is not set, we've reached the last byte
            if (($x & 0x80) === 0) {
                break;
            }
        }
    }

    // If the sign bit was set, negate the number
    if ($signed) {
        $output = -$output;
    }

    return $output;
}



// Import Table Processing with Error Handling
echo "\n\n<b>*******Import Table (" . $importcount . ":" . $importoffset . ")</b>\n";
for ($nc = 0; $nc < $importcount; $nc++) {
    $seekPosition = $importoffset + ($nc * 16);
    fseek($fp, $seekPosition);

    echo "Processing Import Table Entry at Position: $seekPosition\n";

    // Read Class Index
    $ClassIndex = readDWORD($fp);
    echo 'Class Index : ' . $ClassIndex . PHP_EOL;

    // Read Class Name Index
    $ClassNameIndex = readDWORD($fp);
    echo 'Class Name Index : ' . $ClassNameIndex . PHP_EOL;

    // Read Package Index
    $PackageIndex = readDWORD($fp);
    echo 'Package Index : ' . $PackageIndex . PHP_EOL;

    // Special case handling for invalid Package Index (0xFFFFFFEC)
    if ($PackageIndex == 0xFFFFFFEC) {
        $PackageName = "Invalid Export Table Index";
        echo "Error: Invalid Package Index (0xFFFFFFEC) detected at entry $nc\n";
    } elseif ($PackageIndex < 0) {
        // Negative index indicates a reference to the Import Table
        $importIndex = -$PackageIndex - 1;
        if ($importIndex >= 0 && $importIndex < $importcount) {
            $PackageName = "Import Table Entry " . $importIndex;
        } else {
            $PackageName = "Invalid Import Table Index";
        }
    } elseif ($PackageIndex > 0) {
        // Positive index refers to the Export Table
        $exportIndex = $PackageIndex - 1;
        if ($exportIndex >= 0 && $exportIndex < $exportcount) {
            $PackageName = "Export Table Entry " . $exportIndex;
        } else {
            $PackageName = "Invalid Export Table Index";
        }
    } else {
        $PackageName = "Invalid Package Index";
    }

    echo 'Package Name : ' . $PackageName . PHP_EOL;

    // Read Object Name Index
    $ObjectNameIndex = readDWORD($fp);
    echo 'Object Name Index : ' . $ObjectNameIndex . PHP_EOL;

    // Resolve Object Name
    $ObjectName = getNameFromTable($ObjectNameIndex);
    echo 'Object Name : ' . $ObjectName . PHP_EOL;
    
    // Check if offset exceeds file size (prevent overflows)
    if (ftell($fp) >= filesize($filename)) {
        echo "Warning: File pointer has exceeded file size at entry $nc. Stopping processing.\n";
        break;  // Stop processing if the pointer exceeds the file size
    }

    echo PHP_EOL;
}

function INDEX(&$data, $offset = 0, &$l = 5) {
    // Get index size (optimized)
    $l = 5;
    $output = ord($data[$offset]);
    if (($output & 0x40) == 0) {
        $l = 1;
    } else {
        for ($i = 1; $i < 4; $i++) {
            if ((ord($data[$offset + $i]) & 0x80) == 0) {
                $l = $i + 1;
                break;
            }
        }
    }

    // Calculate index (optimized)
    $signed = (($output & 0x80) == 0x80);
    $output = ($output & 0x3F);
    for ($i = 1; $i < min($l, 4); $i++) {
        $output |= (ord($data[$offset + $i]) & 0x7F) << (6 + (($i - 1) * 7));
    }
    if ($l == 5) {
        $output |= ((ord($data[$offset + 4]) & 0x1F) << 27);
    }
    return ($signed ? -1 * $output : $output);
}

function DWORD(&$data, $offset=0){
		return ((ord($data[$offset+3])<<24) + (ord($data[$offset+2])<<16) + (ord($data[$offset+1])<<8) + ord($data[$offset]));
}

function readExportTable($file, $exportTableOffset, $exportCount) {
    // Seek to the offset where the Export Table starts (this is dependent on the file format)
    fseek($file, $exportTableOffset);
	
	//$loadedPackage = ($file);

    $tOffset         = $exportTableOffset;//getHeaderValue('export_table');
    $exportSize      = $exportCount; // getExportCount();
	
	
	
    $exportData      = fread($file, $exportSize * 33); //substr($loadedPackage, $tOffset, $exportSize * 33);
    $exportDataTable = [];
    $lastMainIndex   = $l = 0;

    for ($i = 0; $i < $exportSize; $i++) {
        $subExportData = substr($exportData, 0, 33);

        $exportDataTable[$i]['ClassIndex']   = INDEX($subExportData, $lastMainIndex, $l);
        $lastMainIndex += $l;
        $exportDataTable[$i]['SuperIndex']   = INDEX($subExportData, $lastMainIndex, $l);
        $lastMainIndex += $l;
        $exportDataTable[$i]['PackageIndex'] = DWORD($subExportData, $lastMainIndex);
        $lastMainIndex += 4;
        $exportDataTable[$i]['ObjNameIndex'] = INDEX($subExportData, $lastMainIndex, $l);
        $lastMainIndex += $l;
        $exportDataTable[$i]['ObjFlags']     = DWORD($subExportData, $lastMainIndex);
        $lastMainIndex += 4;
        $exportDataTable[$i]['SerialSize']   = INDEX($subExportData, $lastMainIndex, $l);
        $lastMainIndex += $l;

        if ($exportDataTable[$i]['SerialSize'] > 0) {
            $exportDataTable[$i]['SerialOffset'] = INDEX($subExportData, $lastMainIndex, $l);
            $lastMainIndex += $l;
        }

        $exportData = substr($exportData, $lastMainIndex);
        $lastMainIndex = 0;
    }

    $exportTable = $exportDataTable;
    unset($exportData, $subExportData, $exportDataTable);
    return $exportTable;
}



function readCompactInteger($fp)
{
		$l = 5;
		$output = ord(fread($fp, 1));
		
		if (($output & 0x40) == 0) // 6th bit - 01000000 (64)
		{
			$l = 1;
		} else {
			for ($i = 1; $i < 4; $i++)
			{
				if ((ord(fread($fp, 1)) & 0x80) == 0) // most significant bit (MSB) - 10000000 (128)
				{
					$l = $i + 1;
					break;
				}
			}
		}
			
		//Calculate index (optimized)
		$signed = (($output & 0x80) == 0x80); // most significant bit (MSB) - 10000000 (128)
		$output = ($output & 0x3F); // lower 6 bits set to 1, and the upper 2 bits set to 0 - 00111111 (63)
		
		for ($i = 1; $i < min($l, 4); $i++)
			$output |= (ord(fread($fp, 1+$i)) & 0x7F) << (6 + (($i - 1) * 7)); // lower 7 bits - 01111111 (127)
		if ($l == 5)
			$output |= ((ord(fread($fp, 5)) & 0x1F) << 27); // lower 5 bits - 00011111 (31)
		
		return ($signed ? -1*$output : $output);	
	
	/*
	$output = 0;
    $signed = false;

    for ($i = 0; $i < 5; $i++) {
        // Read a byte from the file
        $x = ord(fread($fp, 1));  // fread returns a string, ord gets the byte value
		
		echo $x;

        // First byte
        if ($i == 0) {
            // Bit: X0000000
            if (($x & 0x80) > 0) { // most significant bit (MSB)
                $signed = true;
            }
            // Bits: 00XXXXXX
            $output |= ($x & 0x3F);
            // Bit: 0X000000
            if (($x & 0x40) == 0) {
                break;
            }
        }
        // Last byte
        else if ($i == 4) {
            // Bits: 000XXXXX -- the 0 bits are ignored
            // (hits the 32-bit boundary)
            $output |= ($x & 0x1F) << (6 + (3 * 7));
        }
        // Middle bytes
        else {
            // Bits: 0XXXXXXX
            $output |= ($x & 0x7F) << (6 + (($i - 1) * 7));
            // Bit: X0000000
            if (($x & 0x80) == 0) {
                break;
            }
        }
		
    }*/
	
	echo "<b>$output</b>";

    // Multiply by negative one here, since the first 6+ bits could be 0
    if ($signed) {
        $output *= -1;
    }

    return $output;
}


/*
	function INDEX(&$data, $offset=0, &$l=5){
		//Get index size (optimized)
		$l = 5;
		$output = ord($data[$offset]);
		if (($output & 0x40) == 0){
			$l = 1;
		} else {
			for ($i = 1; $i < 4; $i++){
				if ((ord($data[$offset+$i]) & 0x80) == 0){
					$l = $i + 1;
					break;
				}
			}
		}
			
		//Calculate index (optimized)
		$signed = (($output & 0x80) == 0x80);
		$output = ($output & 0x3F);
		for ($i = 1; $i < min($l, 4); $i++)
			$output |= (ord($data[$offset+$i]) & 0x7F) << (6 + (($i - 1) * 7));
		if ($l == 5)
			$output |= ((ord($data[$offset+4]) & 0x1F) << 27);
		return ($signed ? -1*$output : $output);
	}

*/










/**
 * Helper function to read a DWORD (4 bytes) from the file.
 */
function readDWORD($fp) {
    $bytes = fread($fp, 4);
    //echo "\nAttempting to read 4 bytes at position: " . ftell($fp) . "\n";  // Debug current file pointer position
    if (strlen($bytes) < 4) {
        echo "Error: Failed to read 4 bytes (expected DWORD)\n";  // Debugging read failure
        return 0xFFFFFFFF; // Return error value if reading fails
    }
    //echo "Read DWORD: " . bin2hex($bytes) . "\n";  // Output the raw bytes being read
    return unpack('V', $bytes)[1];
}

/**
 * Helper function to get the Name from the Name Table using an index.
 * 
 * @param int $index The index to look up in the Name Table.
 * @return string The corresponding name from the Name Table.
 */
function getNameFromTable($index) {
    global $fp, $nameoffset;

    // Seek to the start of the Name Table
    fseek($fp, $nameoffset);

    // Read the Name Table entry at the given index
    fseek($fp, $nameoffset + $index * 8);  // Assuming each Name Table entry is 8 bytes (4 for the name offset and 4 for flags)

    // Read the Name entry (index offset and flags)
    $nameOffset = readDWORD($fp);  // Offset in the file
    $nameFlags = readDWORD($fp);   // Flags (unused for now)

    // Seek to the name location
    fseek($fp, $nameOffset);
    return readNAME($fp);  // Read the name string using your existing readNAME function
}











//------------------------------------------------------------------------------------------------------
echo "</pre>\n";

function readNAME($fp): string
{
    global $version;
    if ($version >= PACKAGE_VERSION_THRESHOLD) {
        return readNAME_new($fp);
    } else {
        return readNAME_old($fp);
    }
}

function readNAME_old($fp): string
{
    return readNullSTRING($fp); // for older versions, it’s a null-terminated string
}

function readNAME_new($fp): string
{
    $strSize = readBYTE($fp);             // get size of string
    return readSTRING($fp, $strSize); // get string
}

function IndexSrializer($Ar, $I): int
{
    $Original = $I;
    $V = abs($I);
    $B0 = (($I >= 0) ? 0 : 0x80) + (($V < 0x40) ? $V : (($V & 0x3f) + 0x40));
    $I = 0;
    $Ar << $B0;

    if ($B0 & 0x40) {
        $V >>= 6;
        $B1 = ($V < 0x80) ? $V : (($V & 0x7f) + 0x80);
        $Ar << $B1;

        if ($B1 & 0x80) {
            $V >>= 7;
            $B2 = ($V < 0x80) ? $V : (($V & 0x7f) + 0x80);
            $Ar << $B2;

            if ($B2 & 0x80) {
                $V >>= 7;
                $B3 = ($V < 0x80) ? $V : (($V & 0x7f) + 0x80);
                $Ar << $B3;

                if ($B3 & 0x80) {
                    $V >>= 7;
                    $B4 = $V;
                    $Ar << $B4;
                    $I = $B4;
                }

                $I = ($I << 7) + ($B3 & 0x7f);
            }

            $I = ($I << 7) + ($B2 & 0x7f);
        }

        $I = ($I << 7) + ($B1 & 0x7f);
    }

    $I = ($I << 6) + ($B0 & 0x3f);

    if ($B0 & 0x80)
        $I = -$I;

    if ($I != $Original)
        echo "Mismatch: I:" . dechex($I) . " Original:" . dechex($Original);

    return $Ar;
}

//function readINDEX($fp): int
//{
//    return read($fp, 4, 'V');
//}

function readSTRING($fp, $size): string
{
    return readStr($fp, $size, 'C' . $size);
}

function readNullSTRING($fp): string
{
    return readNulStr($fp, 'C');
}

//function readDWORD($fp): int
//{
//    return read($fp, 4, 'V');
//}

function readWORD($fp)
{
    $bytes = fread($fp, 2);
    $parsed = unpack('v', $bytes);

    return $parsed[1];
}

function readBYTE($fp): int
{
    return read($fp, 1, 'C');
}

function readGUID($fp): string
{
    $time_low = readDWORD($fp);
    $time_mid = readWORD($fp);
    $time_high_and_version = readWORD($fp);
    $clk_seq_hi_res = read($fp, 1, 'C');
    $clk_seq_low = read($fp, 1, 'C');
    $node = fread($fp, 6);

    return strtoupper(sprintf('%s-%s-%s-%s%s-%s'
        , bin2hex(pack('N', $time_low))
        , bin2hex(pack('n', $time_mid))
        , bin2hex(pack('n', $time_high_and_version))
        , bin2hex(pack('C', $clk_seq_hi_res))
        , bin2hex(pack('C', $clk_seq_low))
        , bin2hex($node)
    ));
}

function read($fp, int $length, string $code)
{
    $bytes = fread($fp, $length);
    if (strlen($bytes) !== $length) {
        die("Error: Failed to read the expected number of bytes.");
    }
    $parsed = unpack($code . 'parsed', $bytes);

    return $parsed['parsed'];
}

function readStr($fp, int $length, string $code)
{
    $bytes = fread($fp, $length);
    if (strlen($bytes) !== $length) {
        die("Error: Failed to read the expected number of bytes.");
    }
    $parsed = unpack($code . 'parsed', $bytes);

    return implode(array_map("chr", $parsed));
}

function readNulStr($fp, string $code)
{
    $output = "";

    while (bin2hex(($b = fread($fp, 1))) != 0x00) // null terminated
    {
        $output .= $b;
    }

    return $output;
}

/*
function GetObjectFlags($val): string
{
    $Str = "";
    if ($val & 0x00000001) $Str = $Str . "RF_Transactional,";
    if ($val & 0x00000002) $Str = $Str . "RF_Unreachable,";
    if ($val & 0x00000004) $Str = $Str . "RF_Public,";
    if ($val & 0x00000008) $Str = $Str . "RF_TagImp,";
    if ($val & 0x00000010) $Str = $Str . "RF_TagExp,";
    if ($val & 0x00000020) $Str = $Str . "RF_SourceModified,";
    if ($val & 0x00000040) $Str = $Str . "RF_TagGarbage,";
    if ($val & 0x00000200) $Str = $Str . "RF_NeedLoad,";
    if ($val & 0x00000400) $Str = $Str . "RF_NeedSave,";
    return $Str;
}
*/

function GetObjectFlags($val) : string
{
	$Str = "";
	if ( $val & 0x00000001 ) $Str = $Str."RF_Transactional,";
	if ( $val & 0x00000002 ) $Str = $Str."RF_Unreachable,";	
	if ( $val & 0x00000004 ) $Str = $Str."RF_Public,";
	if ( $val & 0x00000008 ) $Str = $Str."RF_TagImp,";	
	if ( $val & 0x00000010 ) $Str = $Str."RF_TagExp,";
	if ( $val & 0x00000020 ) $Str = $Str."RF_SourceModified,";	
	if ( $val & 0x00000040 ) $Str = $Str."RF_TagGarbage,";
	if ( $val & 0x00000200 ) $Str = $Str."RF_NeedLoad,";
	if ( $val & 0x00000400 ) $Str = $Str."RF_HighlightedName,"; // RF_EliminateObject
	if ( $val & 0x00000800 ) $Str = $Str."RF_InSingularFunc,";  // RF_RemappedName
	if ( $val & 0x00001000 ) $Str = $Str."RF_Suppress,";	    // Or RF_StateChanged
	if ( $val & 0x00002000 ) $Str = $Str."RF_InEndState,";
	if ( $val & 0x00004000 ) $Str = $Str."RF_Transient,";
	if ( $val & 0x00008000 ) $Str = $Str."RF_PreLoading,";
	if ( $val & 0x00010000 ) $Str = $Str."RF_LoadForClient,";	
	if ( $val & 0x00020000 ) $Str = $Str."RF_LoadForServer,";
	if ( $val & 0x00040000 ) $Str = $Str."RF_LoadForEdit,";	
	if ( $val & 0x00080000 ) $Str = $Str."RF_Standalone,";
	if ( $val & 0x00100000 ) $Str = $Str."RF_NotForClient,";	
	if ( $val & 0x00200000 ) $Str = $Str."RF_NotForServer,";
	if ( $val & 0x00400000 ) $Str = $Str."RF_NotForEdit,";	
	if ( $val & 0x00800000 ) $Str = $Str."RF_Destroyed,";
	if ( $val & 0x01000000 ) $Str = $Str."RF_NeedPostLoad,";	
	if ( $val & 0x02000000 ) $Str = $Str."RF_HasStack,";
	if ( $val & 0x04000000 ) $Str = $Str."RF_Native,";	
	if ( $val & 0x08000000 ) $Str = $Str."RF_Marked,";
	if ( $val & 0x10000000 ) $Str = $Str."RF_ErrorShutdown,";	
	if ( $val & 0x20000000 ) $Str = $Str."RF_DebugPostLoad,";
	if ( $val & 0x40000000 ) $Str = $Str."RF_DebugSerialize,";	
	if ( $val & 0x80000000 ) $Str = $Str."RF_DebugDestroy,";
	
	return rtrim($Str, ',');
}


function GetPackageFlags($val): string
{
    $Str = "";
    if ($val & 0x00000001) $Str = $Str . "PKG_Standalone,";
    if ($val & 0x00000002) $Str = $Str . "PKG_Loaded,";
    if ($val & 0x00000004) $Str = $Str . "PKG_Deferred,";
    return $Str;
}

?>
