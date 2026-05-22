<?php
ini_set("max_execution_time", (3600*5)); // 1 houre limit for scaning large folders!!
require_once 'UPackage.php';
$files = array_slice(scandir('O:\un-uz2'), 2);

if (($key = array_search(".", $files)) !== false) {
    unset($files[$key]);
}
if (($key = array_search("..", $files)) !== false) {
    unset($files[$key]);
}

$filesCount = count($files);
$dbpass     = "MyPASSWORD";
$dbname     = "unreal_files";
$dbhost     = "localhost";
$dbuser     = "root";
$i          = 0;

try {
	$dblink = new PDO("mysql:host=".$dbhost.";charset=utf8", $dbuser, $dbpass); # MySQL Database
		
	if(!$dblink) {
		echo "No DB<BR>\n";
		Exit;
	}
		
	$dblink->query("CREATE DATABASE IF NOT EXISTS $dbname;");
	$dblink->query("USE $dbname;");
	$dblink->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$dblink->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
} 
catch(PDOException $ex) {
	echo "ERROR: ".$e->getMessage()."\n";
}
//echo "<pre>\n";
//print_r($files);
//echo "</pre>\n";


foreach($files as $file)
{
	$fileFull = "O:\\un-uz2\\".$file;
	/*
	$package  = $fileFull;
	
	try {		
		//-- Load package and get GUID
		echo "Loading package $package<BR>\n";
		UPackage::loadPackage($package);
		echo "GUID: ".UPackage::getGUID(true)."\n";		
		var_dump(UPackage::getSignature(true));
		var_dump(UPackage::getVersion());
		var_dump(UPackage::getNameTable());
		var_dump(UPackage::getImportTable());
		var_dump(UPackage::getExportTable());
		var_dump(UPackage::getDependencies());
		var_dump(UPackage::getOuterClasses());
		var_dump(UPackage::getInnerClasses());
		var_dump(UPackage::getClassTree());
		var_dump(UPackage::getObjects());
		
	} catch (Exception $e) {
		echo "ERROR: ".$e->getMessage()."\n";
	}
	*/	
	
	$fileFull   = "O:\\un-uz2\\".$file;	
	$file_parts = pathinfo($fileFull);
	//echo $file_parts['dirname'], "\n";
	//echo $file_parts['basename'], "\n";
	//echo $file_parts['extension'], "\n";
	//echo $file_parts['filename'], "\n";	
	$fileSize   = filesize($fileFull);	
	$fileHash   = strtoupper(sha1_file($fileFull));
	$FileHeader = GetFileHeaderData($fileFull);	
	$FileHeader['FileName'] = $file_parts['basename'];
	$FileHeader['FilePath'] = $file_parts['dirname'];
	$FileHeader['FileSize'] = $fileSize;
	$FileHeader['FileHash'] = $fileHash;
	
	if(strlen(@$file_parts['extension'])==0)
		$FileHeader['FileType'] = "unknown";
	else if (strlen(@$file_parts['extension'])>7)
		continue;
	else
		$FileHeader['FileType'] = $file_parts['extension'];	
	//echo "<pre>\n<br>";
	//echo ($i+1)."/".$filesCount." - ".$fileFull." - ".$fileSize." - ".$fileHash."\n<BR>";
	//print_r($FileHeader);
	//echo "</pre>\n<br>";
	
	try	{		
			$query = "INSERT INTO files(FileName, FilePath, FileSize, FileHash, FileType, FileVersion, FileGUID) VALUES(:FileName, :FilePath, :FileSize, :FileHash, :FileType, :FileVersion, :FileGUID);";		
			$sdt   = $dblink->prepare($query);			
			$sdt->bindParam(':FileName',    $FileHeader['FileName']);
			$sdt->bindParam(':FilePath',    $FileHeader['FilePath']);			
			$sdt->bindParam(':FileSize',    $FileHeader['FileSize']);
			$sdt->bindParam(':FileHash',    $FileHeader['FileHash']);
			$sdt->bindParam(':FileType',    $FileHeader['FileType']);
			$sdt->bindParam(':FileVersion', $FileHeader['Version']);	
			$sdt->bindParam(':FileGUID',    $FileHeader['GUID']);		
			$sdt->execute();
			$sdt->closeCursor();
		}
		catch(PDOException $e) {		
			echo "ERROR: ".$e->getMessage()."\n";
			continue;
		}
		
	echo ($i+1)."/$filesCount - Added: $fileFull - $fileHash<BR>\n";
	//.getHeaderValue($FileHeader, false)."<BR>\n";
	$i++;
	
	//if($i>10)
	//	break;
}
//---------------------------------------------------------------------------------------------------------------------------
function GetFileHeaderData($FILENAME)
{
	$fileHeader = array();
	$data = "";
	
	try
	{
		$handle           = fopen($FILENAME, "r");
		$data             = fread($handle, 52);// or die ("Could not read data from file $filename"); // 52
		$header           = unpack("C4Unreal Header/vVersion/vLicense Mode/v2Package Flags/VNumber Of Names/VName Directory Offset/VNumber Of Files/VFile Directory Offset/VNumber Of Types/VType Directory Offset/C16GUID Hash", $data);
		$header['Header'] = GetFileHeader($header);
		$header['GUID']   = GetGUID($header);	
	}
	catch (Exception $e)
	{
		echo "ERROR 2: ".$e->getMessage()."\n";
	}
	/*
	if($header['Version']<68)
	{
		echo "Old Format\n";
		$data   = fread($handle, 4);
		$header = unpack("vHeritage Count/vHeritage Offset", $data);	
		print_r($header);
		
		echo "<HR>\n";	
	}
	else
	{
		$data   = fread($handle, 2);
		$header = unpack("vGeneration Count", $data);
		echo "Generations to Process\n";
		print_r($header);
		echo "<HR>\n";
		$loop = $header['Generation Count'];

		for($i=0;$i<$loop;$i++)
		{
			echo "Generation ".($i+1)."\n";
			$data   = fread($handle, 4);
			$header = unpack("vExport Count/vName Count", $data);	
			print_r($header);
		}

		echo "<HR>\n";	
	}
	*/
	
	// old guid {1E90ACA4-ED66-11D1-444553540000}
	//echo "</pre>\n";
	fclose($handle);
	return $header;
}
//---------------------------------------------------------------------------------------------------------------------------
function GetFileHeader($HEADER)
{
	if(isLittleEndian()!=1) // rearange to get correct hash
	{ //0x9E2A83C1 - unreal file
		return "0x".strtoupper(str_pad(dechex($HEADER['Unreal Header1']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['Unreal Header2']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['Unreal Header3']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['Unreal Header4']),  2, "0", STR_PAD_LEFT));
	}
	else
	{ // rearange
		return "0x".strtoupper(str_pad(dechex($HEADER['Unreal Header4']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['Unreal Header3']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['Unreal Header2']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['Unreal Header1']),  2, "0", STR_PAD_LEFT));
	}	
}
//---------------------------------------------------------------------------------------------------------------------------
function GetGUID($HEADER)
{
	if(isLittleEndian()!=1) // rearange to get correct hash
	{ 
		return strtoupper(str_pad(dechex($HEADER['GUID Hash1']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash2']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash3']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash4']),  2, "0", STR_PAD_LEFT)."-".
						  str_pad(dechex($HEADER['GUID Hash5']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash6']),  2, "0", STR_PAD_LEFT)."-".
						  str_pad(dechex($HEADER['GUID Hash7']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash8']),  2, "0", STR_PAD_LEFT)."-".
						  str_pad(dechex($HEADER['GUID Hash9']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash10']), 2, "0", STR_PAD_LEFT)."-".
						  str_pad(dechex($HEADER['GUID Hash11']), 2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash12']), 2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash13']), 2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash14']), 2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash15']), 2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash16']), 2, "0", STR_PAD_LEFT));	
	}
	else
	{ // rearange
		return strtoupper(str_pad(dechex($HEADER['GUID Hash4']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash3']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash2']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash1']),  2, "0", STR_PAD_LEFT)."-".
						  str_pad(dechex($HEADER['GUID Hash6']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash5']),  2, "0", STR_PAD_LEFT)."-".
						  str_pad(dechex($HEADER['GUID Hash8']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash7']),  2, "0", STR_PAD_LEFT)."-".
						  str_pad(dechex($HEADER['GUID Hash9']),  2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash10']), 2, "0", STR_PAD_LEFT)."-".
						  str_pad(dechex($HEADER['GUID Hash11']), 2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash12']), 2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash13']), 2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash14']), 2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash15']), 2, "0", STR_PAD_LEFT).str_pad(dechex($HEADER['GUID Hash16']), 2, "0", STR_PAD_LEFT));	
	}
}
//---------------------------------------------------------------------------------------------------------------------------
function isLittleEndian() { return unpack('S',"\x01\x00")[1] === 1; }
//---------------------------------------------------------------------------------------------------------------------------
function swapEndianness($hex) { return implode('', array_reverse(str_split($hex, 2))); }
//---------------------------------------------------------------------------------------------------------------------------
function dexHexZero($n){
	$n = dechex($n);
		return ((strlen($n)%2) == 1 ? "0$n" : $n);	
}
//---------------------------------------------------------------------------------------------------------------------------	
function getHeaderValue($HEADER, $returnHex=false)
{		
	$ref = $HEADER['GUID Hash1'].$HEADER['GUID Hash2'].$HEADER['GUID Hash3'].$HEADER['GUID Hash4'].$HEADER['GUID Hash5'].$HEADER['GUID Hash6'].$HEADER['GUID Hash7'].$HEADER['GUID Hash8'].
	       $HEADER['GUID Hash9'].$HEADER['GUID Hash10'].$HEADER['GUID Hash11'].$HEADER['GUID Hash12'].$HEADER['GUID Hash13'].$HEADER['GUID Hash14'].$HEADER['GUID Hash15'].$HEADER['GUID Hash16'];
	
	//substr($data, $offset, $dl);	
echo $ref."<HR>\n";	
	
	$hexArray = array();
	for ($i = 0; $i<strlen($ref); $i++)
		$hexArray[] = dexHexZero(ord($ref[$i]));
	$value = implode('', array_reverse($hexArray));
	if (!$returnHex)
		return hexdec($value);
	return $value;
}
?>