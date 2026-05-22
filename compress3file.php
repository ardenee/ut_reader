<?php
ini_set("max_execution_time", (3600*5)); // 1 houre limit for scaning large folders!!
$files = array_slice(scandir('M:\Epic\Games\uz32'), 2);
/*
UZ3 File Tag	        int (little-endian)	4 Bytes	0x0000162E (decimal: 5678)
Uncompressed file size	int (little-endian)	4 Bytes	0 - 231-1
Compressed data	bytes	0 - 231-1 Bytes	
*/
	  
if (($key = array_search(".", $files)) !== false) {
    unset($files[$key]);
}
if (($key = array_search("..", $files)) !== false) {
    unset($files[$key]);
}

$filesCount = count($files);
$i          = 0;
$uzext      = ".uz";

//echo $filesCount;
/*
Array
(
    [UZSignature1] => 46
    [UZSignature2] => 22
    [UZSignature3] => 0
    [UZSignature4] => 0
)
Array
(
    [UncompressedFileSize] => 40485841
)
*/

foreach($files as $file)
{
	try
	{
		$fileFull = trim("M:\\Epic\\Games\\uz32\\".$file);		
		$rawdata  = file_get_contents($fileFull);		
		$fp       = fopen($fileFull.".uz3", 'w');	
		$dword    = pack("CCCC", 46, 22, 0, 0); //0x0000162E (decimal: 5678)
		fwrite($fp, $dword);
		$dword    = pack("V", filesize($fileFull));
		fwrite($fp, $dword); 

		//$dword = array_shift(unpack("L", pack("CCCC", $arr[0], $arr[1], $arr[2], $arr[3]))); - 5678			
		//fwrite($fp, pack("V", $fSize));
		$cdata    = zlib_encode($rawdata, ZLIB_ENCODING_DEFLATE, 9);//ZLIB_ENCODING_RAW, ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_GZIP
		
		fwrite($fp, $cdata);
		fclose($fp);	
	}
	catch(PDOException $e) {		
		echo "ERROR: ".$e->getMessage()."\n";
		continue;
	}

	$i++;
}

?>