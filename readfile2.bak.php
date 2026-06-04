<?php

$file = 'test.utx';    // guid {E484D857-00B7-4107-A58A-36FF29F6A3A5}
//$file = 'oldtest.utx'; // guid {1E90ACA4-ED66-11D1-9727-444553540000}
//$file = 'oldtest2.utx'; // guid {1E90ACA4-ED66-11D1-9727-444553540000}
$fp = fopen($file, 'rb');
echo "<pre>\n";

$sig = readDWORD($fp); // Signature
if ($sig !== 0x9E2A83C1){
    die('Not an Unreal File.');
} else {
    echo 'Unreal File found. (0x' . StrToUpper(dechex($sig)) . ')' . PHP_EOL;
}
echo "\n";

echo 'Version:          ' . ($version      = readWORD($fp))  . PHP_EOL; // File Versions



echo 'License mode:     ' . ($licensemode  = readWORD($fp))  . PHP_EOL; 
echo 'Package flags:    ' . ($pflags       = readDWORD($fp));
echo " - ".GetPackageFlags($pflags).PHP_EOL;	
echo 'Name count:       ' . ($namecount    = readDWORD($fp)) . PHP_EOL;
echo 'Name offset:      ' . ($nameoffset   = readDWORD($fp)) . PHP_EOL;
echo 'Export count:     ' . ($exportcount  = readDWORD($fp)) . PHP_EOL;
echo 'Export offset:    ' . ($exportoffset = readDWORD($fp)) . PHP_EOL;
echo 'Import count:     ' . ($importcount  = readDWORD($fp)) . PHP_EOL;
echo 'Import offset:    ' . ($importoffset = readDWORD($fp)) . PHP_EOL;
echo "\n";

if ($version < 68) // old format
{
	echo 'Heritage count:   ' . ($heritagecount = readDWORD($fp)) . PHP_EOL;
	echo 'Heritage offset:  ' . readDWORD($fp) . PHP_EOL;
	$guid = "";
	
	for($i=0;$i<$heritagecount;$i++) // always last one
		$guid = readGUID($fp);
	
	echo 'GUID:             '.$guid.PHP_EOL; // loop through finding last one!
	echo "\n";
}
else { // newer format
    echo 'GUID:             ' . readGUID($fp) . PHP_EOL;
	echo "\n";
	echo 'Generation count: ' . ($generations = readDWORD($fp)) . PHP_EOL;
	echo "\n";
	
	for($i=0;$i<$generations;$i++)
	{
		echo 'Import offset:    ' . readDWORD($fp) . PHP_EOL;
		echo 'Import count:     ' . readDWORD($fp) . PHP_EOL;
		echo "\n";
	}
}



//'''''''''Name Table
/*
NAME Object Name
DWORD Object Flags See "Object Flags"
*/
echo "<b>*******Name Table (".$namecount.":".$nameoffset.")</b>";
for($nc=0;$nc<$namecount;$nc++)
{
	$StrText = readNAME($fp);
	echo "\n".($nc+1)." Name Text: ".$StrText." (".strlen($StrText).") - ";
	$flags   = readDWORD($fp);
	$fgs     = GetObjectFlags($flags);
	echo $fgs;
}

/*
Object References
Some indices do not refer to a name in the Name Table, but to other objects in the
Export or Import tables. They work in this way:
If the index is zero the object referenced is null.
If the index<0 the object is in the Import table in the position (–index-1).
If the index>0 the object is in the Export table in the position (index-1).
*/

//'''''''''Export Table
echo "\n\n<b>*******Export Table (".$exportcount.":".$exportoffset.")</b>\n";
/*
INDEX Class Class of the Object. See "object references".
INDEX Super Parent of the Object (from which it inherits). See "objectreferences".
DWORD Package Package this Object resides in. Could be an internal package (agroup). See "object references".
INDEX Object Name The Object name. It’s an index into the Name Table.
DWORD Object Flags See "Object Flags"
INDEX Serial Size Size of the object inside the file.
INDEX Serial Offset Offset of the object inside the file. This field only exists if SerialSize>0
*/
for($nc=0;$nc<$exportcount;$nc++)
{
	echo 'Class :         '.($ClassRef       = readDWORD($fp)).PHP_EOL; //INDEX
	
	echo $ClassRef.PHP_EOL;
	echo dechex($ClassRef).PHP_EOL;
	echo bin2hex($ClassRef).PHP_EOL.PHP_EOL;
	
	echo 'Super :         '.($ClassParentRef = readDWORD($fp)).PHP_EOL;
	echo 'Package :       '.($PackageRef     = readDWORD($fp)).PHP_EOL;
	echo 'Object Name :   '.($ObjNameRef     = readDWORD($fp)).PHP_EOL;	
	echo 'Object Flags :  '.($ObjectFlags    = readDWORD($fp))." ";
	
	$fgs     = GetObjectFlags($ObjectFlags);
	echo $fgs;
	
	echo 'Serial Size :   '.($SerialSize     = readDWORD($fp)).PHP_EOL;
	echo 'Serial Offset : '.($SerialOffset   = readDWORD($fp)).PHP_EOL.PHP_EOL;
	

	//32 37 38 35 32 38
	
	//echo 'Import offset:    ' . readDWORD($fp) . PHP_EOL;
	
	//echo "\nName Text: ".$StrText." (".strlen($StrText).") - ";
	//$flags   = readDWORD($fp);
	//$fgs     = GetObjectFlags($flags);
	//echo $fgs;
}

//'''''''''Import Table
echo "\n\n<b>*******Import Table (".$importcount.":".$importoffset.")</b>\n";
for($nc=0;$nc<$importcount;$nc++)
{	
	//echo 'Import offset:    ' . readDWORD($fp) . PHP_EOL;
	
	//echo "\nName Text: ".$StrText." (".strlen($StrText).") - ";
	//$flags   = readDWORD($fp);
	//$fgs     = GetObjectFlags($flags);
	//echo $fgs;
}

echo "</pre>\n";


function readNAME($fp) : string {
/*
The NAME type changed between versions of the package format.
If PackageVersion<64 then the type is like an ASCIIZ string.
But if PackageVersion>=64 then the type also saves first the length of the string plus one for the zero byte. 
So for example, the name “Unreal” would be saved: 0x07 “U” “n” “r” “e” “a” “l” 0x00	
*/
	global $version;
	$strText = "";
	
	if($version>=68) // new version
	{
		$strSize = readBYTE($fp);             // get size of string
		$strText = readSTRING($fp, $strSize); // get string
	}
	else { // old version	
		$strText = readNullSTRING($fp); // get null terminated string
	}
	
    return $strText;
}

function IndexSrializer($Ar, $I) : int
{
	/*INT*/ $Original = $I;
	/*DWORD*/ $V      = abs($I);
	/*BYTE*/ $B0      = (($I>=0) ? 0 : 0x80) + (($V < 0x40) ? $V : (($V & 0x3f)+0x40));
	$I      = 0;
	$Ar << $B0;
	
	if($B0 & 0x40) 
	{
		$V >>= 6;
		/*BYTE*/ $B1 = ($V < 0x80) ? $V : (($V & 0x7f)+0x80);
		$Ar << $B1;
		
		if($B1 & 0x80) 
		{
			$V >>= 7;
			/*BYTE*/ $B2 = ($V < 0x80) ? $V : (($V & 0x7f)+0x80);
			$Ar << $B2;
			
			if($B2 & 0x80) 
			{
				$V >>= 7;
				/*BYTE*/ $B3 = ($V < 0x80) ? $V : (($V & 0x7f)+0x80);
				$Ar << $B3;
				
				if($B3 & 0x80) 
				{
					$V >>= 7;
					/*BYTE*/ $B4 = $V;
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
	
	if($B0 & 0x80) 
		$I = -$I;
	
	if($I!=$Original)
		echo "Mismatch: I:".dechex($I)." Original:".dechex($Original);
	
	return $Ar;
}


function readINDEX($fp) : int {
/*
The INDEX type is used as a way of reducing file size. It is a DWORD saved with as less bytes as possible. 
The first byte tells if the number is positive or negative (bit 7, B && 0x80; 1 means negative). 
Any byte has a bit that means that there is another byte following, in the first byte this is bit 6 (B && 0x40) and in the following is bit 7 (B && 0x80). 

For example for number –12345:
0x00003039 (in positive, the sign is flagged at the end of the conversion)
00000000 00000000 00110000 00111001 (in binary)
0000001 1000000 111001 (grouping, 6 bits for the most significant byte, 7 bits for others)
0x01 0x40 0x39 (in hex)
0x01 0xC0 0xF9 (added the bits for the sign and for the continuation flags)
Saved as 0xF9 0xC0 0x01, three bytes instead of four.
*/
    return read($fp, 4, 'V');
}

function readSTRING($fp, $size) : String {
    return readStr($fp, $size, 'C'.$size);
}

function readNullSTRING($fp) : String {
    return readNulStr($fp, 'C');
}

function readDWORD($fp) : int {
    return read($fp, 4, 'V');
}

#function readWORD($fp) : int {
#    return read($fp, 2, 'v');
#}

function readWORD($fp) {
    $bytes  = fread($fp, 2);
    $parsed = unpack('v', $bytes);

    return $parsed[1];
}


function readBYTE($fp) : int {
    return read($fp, 1, 'C');
}

function readGUID($fp) : string{
    $time_low              = readDWORD($fp);
    $time_mid              = readWORD($fp);
    $time_high_and_version = readWORD($fp);
    $clk_seq_hi_res        = read($fp, 1, 'C');
    $clk_seq_low           = read($fp, 1, 'C');
    $node                  = fread($fp, 6);

    return strtoupper(sprintf('%s-%s-%s-%s%s-%s'
        , bin2hex(pack('N', $time_low))
        , bin2hex(pack('n', $time_mid))
        , bin2hex(pack('n', $time_high_and_version))
        , bin2hex(pack('C', $clk_seq_hi_res))
        , bin2hex(pack('C', $clk_seq_low))
        , bin2hex($node)
    ));
}

function read($fp, int $length, string $code) {
    $bytes  = fread($fp, $length);
    $parsed = unpack($code . 'parsed', $bytes);

    return $parsed['parsed'];
}

function readStr($fp, int $length, string $code) {
    $bytes  = fread($fp, $length);
    $parsed = unpack($code . 'parsed', $bytes);

    return implode(array_map("chr", $parsed));
}

function readNulStr($fp, string $code) {
    $output = "";
	
	while (bin2hex(($b = fread($fp, 1))) != 0x00) //null terminated
	{
       // $bytes[] = $b;
		$output .= $b;
		//echo bin2hex($b);
	}
	
	//print_r($bytes); 
	//$output = "";
	
	//for ($i = 0; $i < count($bytes); $i++) {
    //    $output .= $bytes[$i];
	//}

	//print_r($output);
	return $output;
	
    //$parsed = unpack($code . 'parsed', $output);
	
	//$strings = array_map("chr", $bytes);
	//$string  = implode(" ", $strings);
	
	//return $string;
	

    //return implode(array_map("chr", $parsed));
}


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

function GetPackageFlags($val) : string
{
	$Str = "";
	if ( $val & 0x0001 ) $Str = $Str."PKG_AllowDownload,";  // Allow downloading package
	if ( $val & 0x0002 ) $Str = $Str."PKG_ClientOptional,";	// Purely optional for clients
	if ( $val & 0x0004 ) $Str = $Str."PKG_ServerSideOnly,"; // Only needed on the server side
	if ( $val & 0x0008 ) $Str = $Str."PKG_BrokenLinks,";	// Loaded from linker with broken import links
	if ( $val & 0x0010 ) $Str = $Str."PKG_Unsecure,";       // Not trusted
	if ( $val & 0x6000 ) $Str = $Str."PKG_Encrypted,";      // Encrypted
	if ( $val & 0x8000 ) $Str = $Str."PKG_Need,";	        // Client needs to download this package
	
	return rtrim($Str, ',');
}

function NameUnpack($mask, $data, &$pos) 
{
    try {
        $result = array();
        $pos    = 0;
		
        foreach($mask as $field) 
		{
            $subject = substr($data, $pos);
            $type    = $field[0];
            $name    = $field[1];
			
            switch($type) {
                case 'N':
                case 'n':
                case 'C':
                case 'c':
                    $temp          = unpack("{$type}temp", $subject);
                    $result[$name] = $temp['temp'];
                    if($type=='N') {
                        $result[$name] = (int)$result[$name];
                    }

                    $pos += ($type=='N' ? 4 : ($type=='n' ? 2 : 1));
                    break;
                case 'a':
                    $nullPos       = strpos($subject, "\0") + 1;
                    $temp          = unpack("a{$nullPos}temp", $subject);
                    $result[$name] = $temp['temp'];
                    $pos += $nullPos;
                    break;
            }
        }
        return $result;
		
    } catch(Exception $e) {
        $message = $e->getMessage();
        throw new Exception("unpack failed with error '{$message}'");
    }
}

/*

switch ($val)
{
	case 0x00000001 : $flag = "RF_Transactional";   break; // Object is transactional
	case 0x00000002 : $flag = "RF_Unreachable";     break; // Object is not reachable on the object graph
	case 0x00000004 : $flag = "RF_Public";          break; // Object is visible outside its package
	case 0x00000008 : $flag = "RF_TagImp";          break; // Temporary import tag in load/save
	case 0x00000010 : $flag = "RF_TagExp";          break; // Temporary export tag in load/save
	case 0x00000020 : $flag = "RF_SourceModified";  break; // Modified relative to source files.
	case 0x00000040 : $flag = "RF_TagGarbage";      break; // Check during garbage collection
	case 0x00000200 : $flag = "RF_NeedLoad";        break; // During load, indicates object needs loading
	case 0x00000400 : $flag = "RF_HighlightedName"; break; /*RF_EliminateObject(?)*/ // A hardcoded name which should be syntaxhighlighted
/*	case 0x00000800 : $flag = "RF_InSingularFunc";  break; /*RF_RemappedName(?)*/    // In a singular function.
/*	case 0x00001000 : $flag = "RF_Suppress";        break; /*RF_StateChanged(?)*/    // Suppressed log name
/*	case 0x00002000 : $flag = "RF_InEndState";      break; // Within an EndState call
	case 0x00004000 : $flag = "RF_Transient";       break; // Don't save object
	case 0x00008000 : $flag = "RF_PreLoading";      break; // Data is being preloaded from file.
	case 0x00010000 : $flag = "RF_LoadForClient";   break; // In-file load for client.
	case 0x00020000 : $flag = "RF_LoadForServer";   break; // In-file load for client
	case 0x00040000 : $flag = "RF_LoadForEdit";     break; // In-file load for client
	case 0x00080000 : $flag = "RF_Standalone";      break; // Keep object around for editing even if unreferenced
	case 0x00100000 : $flag = "RF_NotForClient";    break; // Don't load this object for the game client
	case 0x00200000 : $flag = "RF_NotForServer";    break; // Don't load this object for the game server
	case 0x00400000 : $flag = "RF_NotForEdit";      break; // Don't load this object for the editor
	case 0x00800000 : $flag = "RF_Destroyed";       break; // Destroy has already been called.
	case 0x01000000 : $flag = "RF_NeedPostLoad";    break; // needs to be postloaded
	case 0x02000000 : $flag = "RF_HasStack";        break; // Has execution stack
	case 0x04000000 : $flag = "RF_Native";          break; // Native (UClass only)
	case 0x08000000 : $flag = "RF_Marked";          break; // Marked (for debugging)
	case 0x10000000 : $flag = "RF_ErrorShutdown";   break; // ShutdownAfterError called
	case 0x20000000 : $flag = "RF_DebugPostLoad";   break; // For debugging Serialize calls
	case 0x40000000 : $flag = "RF_DebugSerialize";  break; // For debugging Serialize calls
	case 0x80000000 : $flag = "RF_DebugDestroy";    break; // For debugging Destroy calls
	default : $flag = "";
}
*/

/*
// Function to get package flags
function GetPackageFlags($val) {
    $flags = '';
    if ($val & 0x0001) $flags .= "PKG_AllowDownload, ";
    if ($val & 0x0002) $flags .= "PKG_ClientOptional, ";
    if ($val & 0x0004) $flags .= "PKG_ServerSideOnly, ";
    if ($val & 0x0008) $flags .= "PKG_BrokenLinks, ";
    if ($val & 0x0010) $flags .= "PKG_Unsecure, ";
    if ($val & 0x8000) $flags .= "PKG_Need, ";

    return rtrim($flags, ', ');
}
*/
/*
// The property type value means:
1 = ByteProperty
2 = IntegerProperty
3 = BooleanProperty
4 = FloatProperty
5 = ObjectProperty
6 = NameProperty
7 = StringProperty
8 = ClassProperty
9 = ArrayProperty
10 = StructProperty
11 = VectorProperty
12 = RotatorProperty
13 = StrProperty
14 = MapProperty
15 = FixedArrayProperty

If the type is an struct then the struct name follows.
The size value is interpreted in the following way:
0 = 1 byte
1 = 2 bytes
2 = 4 bytes
3 = 12 bytes
4 = 16 bytes
5 = a byte follows with real size
6 = a word follows with real size
7 = an integer follows with real size
*/

/*
switch ($val) // Property Flags
{
	case 0x00000001 : $flag = "CPF_Edit";           break; // Property is user-settable in the editor
	case 0x00000002 : $flag = "CPF_Const";          break; // Actor's property always matches class's defaultactor property
	case 0x00000004 : $flag = "CPF_Input";          break; // Variable is writable by the input system
	case 0x00000008 : $flag = "CPF_ExportObject";   break; // Object can be exported with actor
	case 0x00000010 : $flag = "CPF_OptionalParm";   break; // Optional parameter (if CPF_Param is set)
	case 0x00000020 : $flag = "CPF_Net";            break; // Property is relevant to network replication (notspecified in source code)
	case 0x00000040 : $flag = "CPF_ConstRef";       break; // Reference to a constant object
	case 0x00000080 : $flag = "CPF_Parm";           break; // Function/When call parameter
	case 0x00000100 : $flag = "CPF_OutParm";        break; // Value is copied out after function call
	case 0x00000200 : $flag = "CPF_SkipParm";       break; // In a singular function.
	case 0x00000400 : $flag = "CPF_ReturnParm";     break; // Return value.
	case 0x00000800 : $flag = "CPF_CoerceParm";     break; // Coerce args into this function parameter
	case 0x00001000 : $flag = "CPF_Native";         break; // Property is native: C++ code is responsible for serializing it.
	case 0x00002000 : $flag = "CPF_Transient";      break; // Property is transient: shouldn't be saved, zerofilled at load time.
	case 0x00004000 : $flag = "CPF_Config";         break; // Property should be loaded/saved as permanent profile.
	case 0x00008000 : $flag = "CPF_Localized";      break; // Property should be loaded as localizable text
	case 0x00010000 : $flag = "CPF_Travel";         break; // Property travels across levels/servers
	case 0x00020000 : $flag = "CPF_EditConst";      break; // Property is uneditable in the editor
	case 0x00040000 : $flag = "CPF_GlobalConfig";   break; // Load config from base class, not subclass
	case 0x00100000 : $flag = "CPF_OnDemand";       break; // Object or dynamic array loaded on demand only
	case 0x00200000 : $flag = "CPF_New";            break; // Automatically create inner object
	case 0x00400000 : $flag = "CPF_NeedCtorLink";   break; // Fields need construction/destruction (not specified in source code)
	default : $flag = "";
}
*/

/*
switch ($val) // Function Flags
{
	case 0x00000001 : $flag = "FUNC_Final";         break; // Function is final (prebindable, non-overridable function).
	case 0x00000002 : $flag = "FUNC_Defined";       break; // Function has been defined (not just declared). Not used in source code.
	case 0x00000004 : $flag = "FUNC_Iterator";      break; // Function is an iterator.
	case 0x00000008 : $flag = "FUNC_Latent";        break; // Function is a latent state function.
	case 0x00000010 : $flag = "FUNC_PreOperator";   break; // Unary operator is a prefix operator.
	case 0x00000020 : $flag = "FUNC_Singular";      break; // Function cannot be reentered.
	case 0x00000040 : $flag = "FUNC_Net";           break; // Function is network-replicated. Not used in source code.
	case 0x00000080 : $flag = "FUNC_NetReliable";   break; // Function should be sent reliably on the network. Not used in source code.
	case 0x00000100 : $flag = "FUNC_Simulated";     break; // Function executed on the client side.
	case 0x00000200 : $flag = "FUNC_Exec";          break; // Executable from command line.
	case 0x00000400 : $flag = "FUNC_Native";        break; // Native function.
	case 0x00000800 : $flag = "FUNC_Event";         break; // Event function.
	case 0x00001000 : $flag = "FUNC_Operator";      break; // Operator function.
    case 0x00020000 : $flag = "FUNC_Static";        break; // Static function.
	case 0x00040000 : $flag = "FUNC_NoExport";      break; // Don't export intrinsic function to C++.
	case 0x00100000 : $flag = "FUNC_Const";         break; // Function doesn't modify this object.
	case 0x00200000 : $flag = "FUNC_Invariant";     break; // Return value is purely dependent on parameters; no state dependencies or internal state changes
	default : $flag = "";
}
*/

/*
switch ($val) // Class State Flags
{
	case 0x00000001 : $flag = "STATE_Editable";   break; // State should be user-selectable in UnrealEd
	case 0x00000002 : $flag = "STATE_Auto";       break; // State is automatic (the default state).
	case 0x00000004 : $flag = "STATE_Simulated";  break; // State executes on client side.
	default : $flag = "";
}
*/

/*
switch ($val) // Class Flags
{
	case 0x00000001 : $flag = "CLASS_Abstract";           break; // Class is abstract and can't be instantiated directly.
	case 0x00000002 : $flag = "CLASS_Compiled";           break; // Script has been compiled successfully
	case 0x00000004 : $flag = "CLASS_Config";             break; // Load object configuration at construction time.
	case 0x00000008 : $flag = "CLASS_Transient";          break; // This object type can't be saved; null it out at save time.
	case 0x00000010 : $flag = "CLASS_Parsed";             break; // Successfully parsed.
	case 0x00000020 : $flag = "CLASS_Localized";          break; // Class contains localized text. Not used in source code
	case 0x00000040 : $flag = "CLASS_SafeReplace";        break; // Objects of this class can be safely replaced with default or NULL
	case 0x00000080 : $flag = "CLASS_RuntimeStatic";      break; // Objects of this class are static during gameplay
	case 0x00000100 : $flag = "CLASS_NoExport";           break; // Don't export to C++ header
	case 0x00000200 : $flag = "CLASS_NoUserCreate";       break; // Don't allow users to create in the editor..
	case 0x00000400 : $flag = "CLASS_PerObjectConfig";    break; // Handle object configuration on a per-object basis, rather than per-class
	case 0x00000800 : $flag = "CLASS_NativeReplication";  break; // Replication handled in C++
	default : $flag = "";
}
*/




?>
