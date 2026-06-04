<?php
$filename = "oldtest.utx";
$handle   = fopen($filename, "r");
echo "<pre>\n";

$data = fread($handle, 52) or die ("Could not read data from file $filename"); // 52
$header = unpack("C4Unreal Header/vVersion/vLicense Mode/v2Package Flags/VNumber Of Names/VName Directory Offset/VNumber Of Files/VFile Directory Offset/VNumber Of Types/VType Directory Offset/C16GUID Hash", $data);
print_r($header);
echo "<HR>\n";

	if(isLittleEndian()!=1) // rearange to get correct hash
	{ // order of file
	//“0x9E2A83C1 - unreal file
	echo "0x".strtoupper(str_pad(dechex($header['Unreal Header1']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['Unreal Header2']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['Unreal Header3']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['Unreal Header4']),  2, "0", STR_PAD_LEFT));
	echo "\n";
	echo strtoupper(str_pad(dechex($header['GUID Hash1']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash2']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash3']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash4']),  2, "0", STR_PAD_LEFT)."-".
					str_pad(dechex($header['GUID Hash5']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash6']),  2, "0", STR_PAD_LEFT)."-".
					str_pad(dechex($header['GUID Hash7']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash8']),  2, "0", STR_PAD_LEFT)."-".
					str_pad(dechex($header['GUID Hash9']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash10']), 2, "0", STR_PAD_LEFT)."-".
					str_pad(dechex($header['GUID Hash11']), 2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash12']), 2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash13']), 2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash14']), 2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash15']), 2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash16']), 2, "0", STR_PAD_LEFT))."\n";	
	}
	else
	{ // rearange
	echo "0x".strtoupper(str_pad(dechex($header['Unreal Header4']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['Unreal Header3']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['Unreal Header2']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['Unreal Header1']),  2, "0", STR_PAD_LEFT));
	echo "\n";
	echo strtoupper(str_pad(dechex($header['GUID Hash4']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash3']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash2']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash1']),  2, "0", STR_PAD_LEFT)."-".
					str_pad(dechex($header['GUID Hash6']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash5']),  2, "0", STR_PAD_LEFT)."-".
					str_pad(dechex($header['GUID Hash8']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash7']),  2, "0", STR_PAD_LEFT)."-".
					str_pad(dechex($header['GUID Hash9']),  2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash10']), 2, "0", STR_PAD_LEFT)."-".
					str_pad(dechex($header['GUID Hash11']), 2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash12']), 2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash13']), 2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash14']), 2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash15']), 2, "0", STR_PAD_LEFT).str_pad(dechex($header['GUID Hash16']), 2, "0", STR_PAD_LEFT))."\n";	
	}

	echo "<HR>\n";
	
	
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
	
	// old guid {1E90ACA4-ED66-11D1-444553540000}

echo "</pre>\n";
fclose($handle);

function isLittleEndian() { return unpack('S',"\x01\x00")[1] === 1; }

function swapEndianness($hex) { return implode('', array_reverse(str_split($hex, 2))); }
?>