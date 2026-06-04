<?php
ini_set("max_execution_time", (3600*5)); // 5 hour limit for scaning large folders!!
$files = array_slice(scandir('M:\Epic\Games\uz32'), 2);

if (($key = array_search(".", $files)) !== false) {
    unset($files[$key]);
}
if (($key = array_search("..", $files)) !== false) {
    unset($files[$key]);
}

$filesCount = count($files);
$i          = 0;


foreach($files as $file)
{
	$FileFullName = trim("M:\\Epic\\Games\\uz32\\".$file);
	$FileInfo     = pathinfo($FileFullName);
	$FileSize     = filesize($FileFullName);

	$handle       = fopen($FileFullName, "r");
	$SigNum 	  = GetSig($handle);
	//$SigNum       = array_shift(unpack("L", pack("CCCC", $sig[0], $sig[1], $sig[2], $sig[3]))); // 5678
	
	if($SigNum == 1234)	
	{ // 0x000004D2 (decimal: 1234) uz file
/*
Field	Type	Length	Value range
UZ File Tag	int (little-endian)	4 Bytes (DWORD)	0x000004D2 (decimal: 1234)
File Name String Size	int (little-endian)	4 Bytes (FCompactIndex)	0 - 231-1
File Name String	char-array	(needs clarification)
Compressed Data	bytes	(needs clarification)	
*/

		$data   = fread($handle, 1); 
		$header = unpack("C1UZFileNameSize", $data);

		//echo $header['UZFileNameSize']." "; // last char is terminator 	
		$data   = fread($handle, $header['UZFileNameSize']); 
		$header = unpack("C*UZFileName", $data);

		$all_chars    = array_map('chr', $header);
		$OrigFileName = trim(implode("", $all_chars));		
		//echo $OrigFileName."\n";		
		
		$data = fread($handle, filesize($FileFullName)-(5+$header['UZFileNameSize']));	// remove what we have already read, 8			
		file_put_contents($OrigFileName, zlib_decode($data));
	}
	else if ($SigNum == 5678) 
	{ // 0x0000162E (decimal: 5678) uz3 file or uz file with wrong sig, check for file name?
/*
Field	Type	Length	Value range
UZ3 File Tag	int (little-endian)	4 Bytes	0x0000162E (decimal: 5678)
Uncompressed file size	int (little-endian)	4 Bytes	0 - 231-1
Compressed data	bytes	0 - 231-1 Bytes	
*/
		$data   = fread($handle, 4); // 52
		$header = unpack("VUncompressedFileSize", $data);
			
		echo $header['UncompressedFileSize']."<BR>\n";
		$data = fread($handle, filesize($fileFull)-8);				
		file_put_contents("ttest.ut3", zlib_decode($data));
			
		if($header['UncompressedFileSize']==filesize("ttest.ut3"))
			echo "Filesize Matches\n";
	}
	else{ // uz2 file - no sig/filename, just chunks of data to verify
	/*
	Field	Type	Length	Value range
	Compressed chunk size	int	4 Bytes	0-33096
	Uncompressed chunk size	int	4 Bytes	0-32768
	Compressed data	bytes	0-33096 Bytes	
	*/
	$block = $SigNum;
	fopen($H, "w");
	
	do while(!feof($handle))
	{
		fseek($fp, 0);// reset for loop
		
		$data   = fread($handle, 4); 
		$header = unpack("VCompressedBlockSize", $data);
		
		$data   = fread($handle, 4); 
		$header = unpack("VUncompressedBlockSize", $data);
		
		$data   = fread($handle, $header['VCompressedBlockSize']);
		$rawdata = zlib_decode($data);
		
		if(sizeof($rawdata) == $header['VUncompressedBlockSize'])
			echo "File Sie Mataches block";
		
		fwrite(H, $rawdata);
	
	}
	
	fclose($fp);
	fclose($H);
}

//-------------------------------------------------------------------------------------------
function GetSig($H)
{
	$sig = array();
	
	try
	{
		$data = fread($H, 4);
		$sig  = unpack("C*UZSig", $data);// C4
	}
	catch(PDOException $e) {		
		echo "ERROR: GetSig() ".$e->getMessage()."\n";
	}
	
	return array_shift(unpack("L", pack("CCCC", $sig[0], $sig[1], $sig[2], $sig[3]))); // returns decmil value, not hex
}
//-------------------------------------------------------------------------------------------
function GetNewName($fullpath) 
{
  $path = dirname($fullpath);
  
  if (!file_exists($fullpath)) 
	  return $fullpath;
  
  $fnameNoExt = trim(pathinfo($fullpath,PATHINFO_FILENAME));
  $ext        = pathinfo($fullpath, PATHINFO_EXTENSION);

  $b = 1;
  
  while(file_exists("$path\\$fnameNoExt ($b).$ext")) 
	  $b++;
  
  return "$path\\$fnameNoExt ($b).$ext";
}
//-------------------------------------------------------------------------------------------

?>