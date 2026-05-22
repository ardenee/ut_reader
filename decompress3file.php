<?php
ini_set("max_execution_time", (3600*5)); // 5 hour limit for scaning large folders!!
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

foreach($files as $file)
{
	try
	{
		$fileFull = trim("M:\\Epic\\Games\\uz32\\".$file);
		$handle   = fopen($fileFull, "r");
		$data     = fread($handle, 4); // 52
		$header   = unpack("C4UZSignature", $data);
		$n        = 0;

		if(isLittleEndian()!=1) // rearange to get correct hash
		{ // order of file		
			$n = "0x".strtoupper(str_pad(dechex($header['UZSignature1']),  2, "0", STR_PAD_LEFT).
								 str_pad(dechex($header['UZSignature2']),  2, "0", STR_PAD_LEFT).
								 str_pad(dechex($header['UZSignature3']),  2, "0", STR_PAD_LEFT).
								 str_pad(dechex($header['UZSignature4']),  2, "0", STR_PAD_LEFT));							 	
		}
		else
		{ // rearange	
			$n = "0x".strtoupper(str_pad(dechex($header['UZSignature4']),  2, "0", STR_PAD_LEFT).
								 str_pad(dechex($header['UZSignature3']),  2, "0", STR_PAD_LEFT).
								 str_pad(dechex($header['UZSignature2']),  2, "0", STR_PAD_LEFT).
								 str_pad(dechex($header['UZSignature1']),  2, "0", STR_PAD_LEFT));	
		}
			
		// 0x000004D2 / 0x0000162E unreal uz file (1234 / 5678)
		if (hexdec($n) == 5678) // UT3 tag 0x0000162E / 5678
		{
			echo ($i+1)."/$filesCount - Correct Version UT3: $n - ";
			$uzext = ".uz3";
			
			$data   = fread($handle, 4); // read file size
		    $header = unpack("VUncompressedFileSize", $data);
			
			echo $header['UncompressedFileSize']."<BR>\n";
			$data = fread($handle, filesize($fileFull)-8);	// remove what we have already read, 8			
			file_put_contents("ttest.ut3", zlib_decode($data));
			
			if($header['UncompressedFileSize']==filesize("ttest.ut3")) // we have the correct amount of data, so write it
				echo "Filesize Matches\n";
				
			fclose($handle);
		}
		else
		{
			echo ($i+1)."$i/$filesCount - Incorrect Version : $n ($file)\n";
			fclose($handle);
			
			$newName = newName2($fileFull.".bad");	

			//if($fileFull != $newName)			
				//rename($fileFull, $newName);
			
			continue;
		}
	}
	catch(PDOException $e) {		
		echo "ERROR: ".$e->getMessage()."\n";
		continue;
	}
	
	$i++;
}
//-----------------------------------------------------------------------------------------------------
function isLittleEndian() { return unpack('S',"\x01\x00")[1] === 1; }
//-----------------------------------------------------------------------------------------------------
function swapEndianness($hex) { return implode('', array_reverse(str_split($hex, 2))); } 
//-----------------------------------------------------------------------------------------------------
function newName2($fullpath) 
{
  $path = dirname($fullpath);
  
  if (!file_exists($fullpath)) 
	  return $fullpath;
  
  $fnameNoExt = trim(pathinfo($fullpath,PATHINFO_FILENAME));
  $ext        = pathinfo($fullpath, PATHINFO_EXTENSION);
  $b          = 1;
  
  while(file_exists("$path\\$fnameNoExt ($b).$ext")) 
	  $b++;
  
  return "$path\\$fnameNoExt ($b).$ext";
}
//-----------------------------------------------------------------------------------------------------
?>