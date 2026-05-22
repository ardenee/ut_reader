<?php
ini_set("max_execution_time", (3600*5)); // 1 houre limit for scaning large folders!!
require_once 'UPackage.php';
$files = array_slice(scandir('M:\Epic\Games\uz'), 2);
/*// uz file format
  // 1) DWORD: Sig
  // 2) FCompactIndex: StrLen Incl 0 char
  // 3) char-array: Orig filename (ends with \0).
  // 4) File data */
	  
if (($key = array_search(".", $files)) !== false) {
    unset($files[$key]);
}
if (($key = array_search("..", $files)) !== false) {
    unset($files[$key]);
}

$filesCount = count($files);
$i          = 0;
$uzext = ".uz";

//echo $filesCount;

foreach($files as $file)
{
	try
	{
		$fileFull = trim("M:\\Epic\\Games\\uz\\".$file);
		$handle = fopen($fileFull, "r");
		$data   = fread($handle, 4); // 52
		$header = unpack("C4UZSignature", $data);
		$n      = 0;

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
		if (hexdec($n) == 1234)
		{
			echo ($i+1)."/$filesCount - Correct Version : $n - ";
			$uzext = ".uz";
		}
		else if (hexdec($n) == 5678)
		{
			echo ($i+1)."/$filesCount - Correct Version UT3: $n - ";
			$uzext = ".uz3";
		}
		else
		{
			echo ($i+1)."$i/$filesCount - Incorrect Version : $n ($file)\n";
			fclose($handle);
			
			$newName = newName2($fileFull.".bad");	

			if($fileFull != $newName)			
				rename($fileFull, $newName);
			
			continue;
		}

		$data   = fread($handle, 1); // 52
		$header = unpack("C1UZFileNameSize", $data);

		echo $header['UZFileNameSize']." "; // last char is terminator 	
		$data   = fread($handle, $header['UZFileNameSize']); // 52
		$header = unpack("C*UZFileName", $data);

		$all_chars    = array_map('chr', $header);
		$OrigFileName = trim(implode("", $all_chars));		
		echo $OrigFileName."\n";
		
		
		//zlib_encode(); // zlib_encode(string $data, int $encoding, int $level = -1): string|false - ZLIB_ENCODING_RAW, ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_GZIP
		//zlib_decode(); // zlib_decode(string $data, int $max_length = 0): string|false
		
		
		
		
		
		
		
		
		
		
		
		
		fclose($handle);	

        $newName = newName2("M:\\Epic\\Games\\uz\\".$OrigFileName.$uzext);	
		
		if($fileFull != $newName)		
			rename($fileFull, $newName);
		//echo $fileFull." - ".$newName."\n";
		
	}
	catch(PDOException $e) {		
		echo "ERROR: ".$e->getMessage()."\n";
		continue;
	}
	
	//if($i>10)
	//		break;
	
	$i++;
}

function isLittleEndian() { return unpack('S',"\x01\x00")[1] === 1; }

function swapEndianness($hex) { return implode('', array_reverse(str_split($hex, 2))); } 

function newName2($fullpath) 
{
  $path = dirname($fullpath);
  //echo "<pre><hr>";
  //echo $fullpath;
  //print_r($path);
  //echo "</pre>";
  
  if (!file_exists($fullpath)) 
	  return $fullpath;
  
  $fnameNoExt = trim(pathinfo($fullpath,PATHINFO_FILENAME));
  $ext        = pathinfo($fullpath, PATHINFO_EXTENSION);

  $b = 1;
  
  while(file_exists("$path\\$fnameNoExt ($b).$ext")) 
	  $b++;
  
  return "$path\\$fnameNoExt ($b).$ext";
}
?>