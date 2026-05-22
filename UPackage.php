<?php
/* UPackage:
 *  Description: PHP class to extract info from a UT package, such as:
 *  	- signature
 *  	- version
 *  	- GUID
 *  	- name table
 *  	- import table
 *  	- export table
 *  	- dependencies
 *  	- external classes
 *  	- internal classes (with or without objects)
 *  	- internal class tree
 *  	- objects (with query, limit and offset)
 *  	- object properties
 *  	- textures
 *  	- palettes
 *  	- sounds
 *  
 *  Credits: Feralidragon
 * 	Version: 0.2
 *  Date: 2013-05-24
 *  License: Creative Commons Attribution 3.0
 *  	http://creativecommons.org/licenses/by/3.0
 * 
 */

class UPackage {
	
	//Validation constants
	const UPACK_SIGNATURE = 0x9E2A83C1;
	const UPACK_VALIDVERS = 69;
	
	//Error constants
	const UPACK_ERR_EMPTYPATH   = 1;
	const UPACK_ERR_LOAD        = 2;
	const UPACK_ERR_INVALIDSIGN = 3;
	const UPACK_ERR_INVALIDVERS = 4;
	const UPACK_ERR_PHPNOGD     = 100;
	
	//Recognized property types
	protected static $PROPTYPES = array('unknown', 'byte', 'int', 'bool', 'float', 'object', 'name', 'string', 'class', 'array', 'struct', 'vector', 'rotator', 'str', 'map', 'fixed');
	
	//Static private caching properties
	protected static $loadedPackage  = false;
	protected static $header         = null;
	protected static $packageVersion = null;
	protected static $nameTable      = null;
	protected static $importTable    = null;
	protected static $exportTable    = null;
	protected static $dependencies   = null;
	protected static $outer_classes  = null;
	protected static $inner_classes  = null;
	protected static $classtree      = null;
	protected static $objects        = null;
	
	
	//Load package
	public static function loadPackage($package_path){
		if (is_null($package_path) || empty($package_path))
			throw new Exception("package_path empty!", self::UPACK_ERR_EMPTYPATH);
			
		self::reset();
		self::$loadedPackage = file_get_contents($package_path);
		if (self::$loadedPackage === false)
			throw new Exception("Loading of package $package_path failed.", self::UPACK_ERR_LOAD);
			
		self::loadHeader();
		if (self::getSignature() != self::UPACK_SIGNATURE){
			self::reset();
			throw new Exception("Invalid package signature.", self::UPACK_ERR_INVALIDSIGN);
		}
		
		self::$packageVersion = self::getVersion();
		/*if (!in_array(self::$packageVersion, explode(',', self::UPACK_VALIDVERS))){
			$msgVersion = self::$packageVersion;
			self::reset();
			throw new Exception("Invalid package version: $msgVersion.", self::UPACK_ERR_INVALIDVERS);
		}*/
	}
	

	//Unload package (reset cache)
	public static function reset(){
		self::$loadedPackage  = false;
		self::$nameTable      = null;
		self::$importTable    = null;
		self::$exportTable    = null;
		self::$header         = null;
		self::$packageVersion = null;
		self::$dependencies   = null;
		self::$outer_classes  = null;
		self::$inner_classes  = null;
		self::$classtree      = null;
		self::$objects        = null;
	}
	
	
	
	////////////////////////////////////////////////////////////////////////////////////////////////////
	// *** Internal ***
	////////////////////////////////////////////////////////////////////////////////////////////////////
	
	protected static function loadHeader(){
		if (is_null(self::$header))
			self::$header = self::getHeader();
		return self::$header;
	}
	
	
	protected static function dexHexZero($n){
		$n = dechex($n);
		return ((strlen($n)%2) == 1 ? "0$n" : $n);	
	}
	
	
	protected static function get32Float($dword) {
		return (($dword & ((0x01<<23) - 0x01)) + (0x01<<23) * ($dword>>31 | 0x01)) * pow(2, (($dword>>23 & 0xFF) - 127) - 23);
	}
	
	
	protected static function getImageResource(&$texDFinal){
		if (empty($texDFinal['palette']['data']))
			return NULL;
		if (!function_exists('imagecreatetruecolor') || !function_exists('imagesetpixel') || !function_exists('imagetruecolortopalette'))
			throw new Exception("PHP Error: GD library doesn't seem to be installed.", self::UPACK_ERR_PHPNOGD);
			
		$img = imagecreatetruecolor($texDFinal['width'], $texDFinal['height']);
		$hdiv = min(16, $texDFinal['height']);
		$areas = max(1, ($texDFinal['height']/$hdiv));
		
		$tdata = array();
		for ($i = 0; $i < $areas; $i++)
			$tdata[] = substr($texDFinal['data'], $i*($hdiv*$texDFinal['width']), $hdiv*$texDFinal['width']);
		
		foreach ($tdata as $k=>&$t){
			for ($i = 0; $i < $hdiv; $i++){
				for ($j = 0; $j < $texDFinal['width']; $j++)
					imagesetpixel($img, $j, $i + $k*$hdiv, $texDFinal['palette']['data'][ord($t[$i*$texDFinal['width'] + $j])]);
			}
		}
		
		imagetruecolortopalette($img, false, 256);
		return $img;
	}
	
	////////////////////////////////////////////////////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////////////////////////////

	
	////////////////////////////////////////////////////////////////////////////////////////////////////
	// *** Public ***
	////////////////////////////////////////////////////////////////////////////////////////////////////
	
	public static function getHeaderValue($struct_type, $returnHex=false){
		
		$data = self::loadHeader();
		switch ($struct_type){
			case 'u_signature':		$offset = 0;	$dl = 4; 	break;
			case 'package_version':	$offset = 4; 	$dl = 2;	break;
			case 'license_mode': 	$offset = 6;	$dl = 2; 	break;
			case 'u_flags': 		$offset = 8; 	$dl = 4;	break;
			case 'name_count': 		$offset = 12; 	$dl = 4;	break;
			case 'name_table': 		$offset = 16; 	$dl = 4;	break;
			case 'export_count': 	$offset = 20; 	$dl = 4;	break;
			case 'export_table': 	$offset = 24; 	$dl = 4;	break;
			case 'import_count': 	$offset = 28; 	$dl = 4;	break;
			case 'import_table': 	$offset = 32; 	$dl = 4;	break;
			case 'guid': 			$offset = 36; 	$dl = 16;	break;
			default:
				return false;
		}
	
		$ref = substr($data, $offset, $dl);
		$hexArray = array();
		for ($i = 0; $i<strlen($ref); $i++)
			$hexArray[] = self::dexHexZero(ord($ref[$i]));
		$value = implode('', array_reverse($hexArray));
		if (!$returnHex)
			return hexdec($value);
		return $value;
	}
	
	
	public static function INDEX(&$data, $offset=0, &$l=5){
		//Get index size (optimized)
		$l = 5;
		$output = ord($data[$offset]);
		
		if (($output & 0x40) == 0) // 6th bit
		{
			$l = 1;
		} else {
			for ($i = 1; $i < 4; $i++)
			{
				if ((ord($data[$offset+$i]) & 0x80) == 0)
				{
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
	
	
	public static function BYTE(&$data, $offset=0){
		return ord($data[$offset]);
	}
	
	
	public static function WORD(&$data, $offset=0){
		return ((ord($data[$offset+1])<<8) + ord($data[$offset]));
	}
	
	
	public static function DWORD(&$data, $offset=0){
		return ((ord($data[$offset+3])<<24) + (ord($data[$offset+2])<<16) + (ord($data[$offset+1])<<8) + ord($data[$offset]));
	}
	
	
	public static function QWORD(&$data, $offset=0){
		return ((ord($data[$offset+7])<<56) + (ord($data[$offset+6])<<48) + (ord($data[$offset+5])<<40) + (ord($data[$offset+4])<<32) + 
			(ord($data[$offset+3])<<24) + (ord($data[$offset+2])<<16) + (ord($data[$offset+1])<<8) + ord($data[$offset]));
	}
	
	
	public static function getHeader(){
		if (self::$loadedPackage === false)
			return null;
		return substr(self::$loadedPackage, 0, 52);
	}
	
	
	public static function getSignature($bHumanReadable=false){
		return (self::$loadedPackage !== false ? self::getHeaderValue('u_signature', $bHumanReadable) : null);
	}
	
	
	public static function getVersion($bHumanReadable=false){
		return (self::$loadedPackage !== false ? self::getHeaderValue('package_version', $bHumanReadable) : null);
	}
	
	
	public static function getGUID($bHumanReadable=false){
		return (self::$loadedPackage !== false ? self::getHeaderValue('guid', $bHumanReadable) : null);
	}
	
	
	public static function getNameCount(){
		return (self::$loadedPackage !== false ? self::getHeaderValue('name_count') : null);
	}
	
	
	public static function getNameTable(){
		if (!is_null(self::$nameTable))
			return self::$nameTable;
		if (self::$loadedPackage === false)
			return null;
		
		$tOffset = self::getHeaderValue('name_table');
		$namesCount = self::getNameCount();
		$nme_n = 0;
		$namesTable = array();
		for ($i = 0; $i < $namesCount; $i++)
		{
			$nme_length = ord(substr(self::$loadedPackage, $tOffset + $nme_n, 1));
			$nme_n++;
			$namesTable[] = substr(self::$loadedPackage, $tOffset + $nme_n, $nme_length - 1);
			$nme_n += ($nme_length + 4);
		}
		
		self::$nameTable = $namesTable;
		unset($namesTable);
		return self::$nameTable;
	}
	
	
	public static function getImportCount(){
		return (self::$loadedPackage !== false ? self::getHeaderValue('import_count') : null);
	}
	
	
	public static function getImportTable(){
		if (!is_null(self::$importTable))
			return self::$importTable;
		if (self::$loadedPackage === false)
			return null;
			
		$tOffset = self::getHeaderValue('import_table');
		$importSize = self::getImportCount();
		$importData = substr(self::$loadedPackage, $tOffset, $importSize*19);
		$importDataTable = array();
		$lastMainIndex = $l = 0;
		
		for ($i = 0; $i < $importSize; $i++)
		{
			$subImportData = substr($importData, 0, 19);
			$importDataTable[$i]['ClassPkgIndex']   = self::INDEX($subImportData, $lastMainIndex, $l);
			$lastMainIndex += $l;
			$importDataTable[$i]['ClassNameIndex']  = self::INDEX($subImportData, $lastMainIndex, $l);
			$lastMainIndex += $l;
			$importDataTable[$i]['PackageObjIndex'] = self::DWORD($subImportData, $lastMainIndex);
			$lastMainIndex += 4;
			$importDataTable[$i]['ObjNameIndex']    = self::INDEX($subImportData, $lastMainIndex, $l);
			$lastMainIndex += $l;
			$importData = substr($importData, $lastMainIndex);
			$lastMainIndex = 0;
		}
		
		self::$importTable = $importDataTable;
		unset($importData, $subImportData, $importDataTable);
		return self::$importTable;
	}
	
	
	public static function getExportCount(){
		return (self::$loadedPackage !== false ? self::getHeaderValue('export_count') : null);
	}
	
	
	public static function getExportTable(){
		if (!is_null(self::$exportTable))
			return self::$exportTable;
		if (self::$loadedPackage === false)
			return null;
		
		$tOffset = self::getHeaderValue('export_table');
		$exportSize = self::getExportCount();
		$exportData = substr(self::$loadedPackage, $tOffset, $exportSize*33);
		$exportDataTable = array();
		$lastMainIndex = $l = 0;
		
		for ($i = 0; $i < $exportSize; $i++)
		{
			$subExportData = substr($exportData, 0, 33);
		
			$exportDataTable[$i]['ClassIndex']       = self::INDEX($subExportData, $lastMainIndex, $l);
			$lastMainIndex += $l;
			$exportDataTable[$i]['SuperIndex']       = self::INDEX($subExportData, $lastMainIndex, $l);
			$lastMainIndex += $l;
			$exportDataTable[$i]['PackageIndex']     = self::DWORD($subExportData, $lastMainIndex);
			$lastMainIndex += 4;
			$exportDataTable[$i]['ObjNameIndex']     = self::INDEX($subExportData, $lastMainIndex, $l);
			$lastMainIndex += $l;
			$exportDataTable[$i]['ObjFlags']         = self::DWORD($subExportData, $lastMainIndex);
			$lastMainIndex += 4;
			$exportDataTable[$i]['SerialSize']       = self::INDEX($subExportData, $lastMainIndex, $l);
			$lastMainIndex += $l;
			if ($exportDataTable[$i]['SerialSize'] > 0){
				$exportDataTable[$i]['SerialOffset'] = self::INDEX($subExportData, $lastMainIndex, $l);
				$lastMainIndex += $l;
			}
			$exportData = substr($exportData, $lastMainIndex);
			$lastMainIndex = 0;
		}
		
		self::$exportTable = $exportDataTable;
		unset($exportData, $subExportData, $exportDataTable);
		return self::$exportTable;
	}
	
	
	public static function getDependencies(){
		if (!is_null(self::$dependencies))
			return self::$dependencies;
		if (self::$loadedPackage === false)
			return null;
			
		self::getNameTable();
		self::getImportTable();
		
		$dependencies = array();
		foreach (self::$importTable as $i=>&$importRegistry){
			if (self::$nameTable[$importRegistry['ClassNameIndex']] == "Package" && empty($importRegistry['PackageObjIndex']))
				$dependencies[] = self::$nameTable[$importRegistry['ObjNameIndex']];
		}
		
		self::$dependencies = $dependencies;
		unset($dependencies);
		return self::$dependencies;
	}
	
	
	public static function getOuterClasses($include_index=false){
		if (!is_null(self::$outer_classes[(int)$include_index]))
			return self::$outer_classes[(int)$include_index];
		if (self::$loadedPackage === false)
			return null;
			
		self::getNameTable();
		self::getImportTable();
		
		$classes = array();
		foreach (self::$importTable as $i=>&$importRegistry){
			if (self::$nameTable[$importRegistry['ClassNameIndex']] == "Class")
				$classes[] = ($include_index ? 
					array('name' => self::$nameTable[$importRegistry['ObjNameIndex']], 'index' => $i, 'isclass' => true):
					self::$nameTable[$importRegistry['ObjNameIndex']]);
		}
		
		self::$outer_classes[(int)$include_index] = $classes;
		unset($classes);
		return self::$outer_classes[(int)$include_index];
	}
	
	
	public static function getInnerClasses($include_indexes=false, $include_objects=false){
		$key = (int)$include_indexes + ((int)$include_objects*2);
		if (!empty(self::$inner_classes[$key]))
			return self::$inner_classes[$key];
		if (self::$loadedPackage === false)
			return null;
			
		self::getNameTable();
		self::getExportTable();
		
		$classes = array();
		foreach (self::$exportTable as $i=>&$exportRegistry){
			$is_class = (empty($exportRegistry['ClassIndex']) && !empty($exportRegistry['SuperIndex']) && empty($exportRegistry['PackageIndex']));
			if ($include_objects || $is_class){
				$classes[] = ($include_indexes ?
					array('name' => self::$nameTable[$exportRegistry['ObjNameIndex']], 'index' => $i, 'isclass' => $is_class, 
						'ssize' => $exportRegistry['SerialSize'], 'soffset' => ($exportRegistry['SerialSize'] > 0 ? $exportRegistry['SerialOffset'] : false),
						'sindex' => ($is_class ? $exportRegistry['SuperIndex'] : $exportRegistry['ClassIndex']),
						'objflags' => $exportRegistry['ObjFlags']):
					self::$nameTable[$exportRegistry['ObjNameIndex']]);
			}
		}
		
		self::$inner_classes[$key] = $classes;
		unset($classes);
		return self::$inner_classes[$key];
	}
	
	
	public static function getClassTree(){
		if (!empty(self::$classtree))
			return self::$classtree;
		if (self::$loadedPackage === false)
			return null;
		
		$out_classes = self::getOuterClasses(true);
		$in_classes = self::getInnerClasses(true);
		
		$tree = array();
		if (empty($in_classes))
			return $tree;
			
		$out_tree = array();
		foreach ($out_classes as $o)
			$out_tree[$o['index']] = $o;
		$in_tree = array();
		foreach ($in_classes as $i)
			$in_tree[$i['index']] = $i;
			
		foreach ($in_tree as $itree){
			$classSeq = array($itree['index']+1);
			$sindex = $itree['sindex'];
			while ($sindex > 0){
				$classSeq[] = $sindex;
				$sindex = $in_tree[$sindex-1]['sindex'];
			}
			$classSeq[] = $sindex;
			$classSeq = array_reverse($classSeq);
			
			$t = &$tree;
			foreach ($classSeq as $k=>$cS){
				$idx = ($cS < 0 ? -$cS-1 : $cS-1);
				if ($cS < 0)
					$seltree = &$out_tree;
				else
					$seltree = &$in_tree;
				
				if (!isset($t[$seltree[$idx]['name']]))
					$t[$seltree[$idx]['name']] = array();
				$t = &$t[$seltree[$idx]['name']];
			}
		}
		
		self::$classtree = $tree;
		unset($tree);
		return self::$classtree;
	}
	
	
	public static function getObject($index, &$tableType=null){
		if ($index == 0)
			return NULL;
		
		if ($index < 0){
			self::getImportTable();
			$tableType = 'import';
			return self::$importTable[-$index-1];
		}
		
		$tableType = 'export';
		self::getExportTable();
		return self::$exportTable[$index-1];
	}
	
	
	public static function getObjects($query=0, $limit=null, $offset=null){
		$query = strtolower("$query");
		$key = "{$query}_l{$limit}_o{$offset}";
		if (!empty(self::$objects[$query]))
			return self::$objects[$query];
		if (self::$loadedPackage === false)
			return null;
			
		
		
		$out_classes = self::getOuterClasses(true);
		$in_classes = self::getInnerClasses(true, true);
		
		$tree = array();
		if (empty($in_classes))
			return $tree;
			
		$out_tree = array();
		foreach ($out_classes as $o)
			$out_tree[$o['index']] = $o;
		$in_tree = array();
		foreach ($in_classes as $i)
			$in_tree[$i['index']] = $i;
			
		$tquery = $qcommon = array();
		$is_class = false;
		foreach ($in_tree as $itree){
			$classSeq = array($itree['index']+1);
			$sindex = $itree['sindex'];
			while ($sindex > 0){
				$classSeq[] = $sindex;
				$sindex = $in_tree[$sindex-1]['sindex'];
			}
			$classSeq[] = $sindex;
			$classSeq = array_reverse($classSeq);
			
			$t = &$tree;
			$tpath = $trealpath  = array();
			foreach ($classSeq as $k=>$cS){
				$idx = ($cS < 0 ? -$cS-1 : $cS-1);
				if ($cS < 0)
					$seltree = &$out_tree;
				else
					$seltree = &$in_tree;
				
				$is_class = $seltree[$idx]['isclass'];
				if (!isset($t[$seltree[$idx]['name']]))
					$t[$seltree[$idx]['name']] = ($is_class ? array() : 
						array('ssize' => $seltree[$idx]['ssize'], 'soffset' => $seltree[$idx]['soffset'], 'objflags' => $seltree[$idx]['objflags']));
				$t = &$t[$seltree[$idx]['name']];
				if (!empty($query)){
					$trealpath[] = $seltree[$idx]['name'];
					$tpath[] = strtolower($seltree[$idx]['name']);
				}
			}
			
			if (!empty($query)){
				if (!in_array($query, $tpath)){
					$trem = &$tree[$trealpath[0]];
					if (!empty($qcommon)){
						foreach ($trealpath as $l=>$tp){
							if (!in_array($tp, $qcommon)){
								$trem = &$trem[$tp];
								break;
							}
						}
					}
					unset($trem);
				}
				else if (empty($tquery)){
					$tquery = &$tree;
					$qcommon = $trealpath;
					foreach ($trealpath as $tp){
						$tquery = &$tquery[$tp];
						if ($query == strtolower($tp))
							break;
					}
					
					$last_tp = end($trealpath);
					if (!$is_class && $query == strtolower($last_tp))
						break;
				}
			}
		}
		
		if (!empty($query))
			$tree = &$tquery;
		if (empty($tree['soffset']) || empty($tree['ssize']))
			$tree = array_slice($tree, (int)$offset, $limit, true);
		
		self::$objects[$query] = $tree;
		unset($tree);
		return self::$objects[$query];
	}
	
	
	public static function getObjectProperties($ssize, $soffset, &$propertiesSSize=0, $objFlags=0x00000000){
		
		if (self::$loadedPackage === false || empty($ssize) || empty($soffset)){
			$propertiesSSize = 0;
			return null;
		}
		
		//Check other flags first (such as state blocks)
		$stpointer = $l = 0;
		$propData = substr(self::$loadedPackage, $soffset, $ssize);
		if (($objFlags & 0x02000000) == 0x02000000){
			$stateFrameNode         = self::INDEX($propData, $stpointer, $l);
			$stpointer += $l;
			$stateFrameStateNode    = self::INDEX($propData, $stpointer, $l);
			$stpointer += $l;
			$stateFrameProbeMask    = self::QWORD($propData, $opointer);
			$stpointer += 8;
			$stateFrameLatentAction = self::DWORD($propData, $opointer);
			$stpointer += 4;
			if ($stateFrameNode != 0){
				self::INDEX($propData, $stpointer, $l);
				$stpointer += $l;
			}
		}
		
		
		//Start getting properties
		self::getNameTable();
		$properties = array();
		$propData   = substr($propData, $stpointer, $ssize - $stpointer);
		$opointer   = $l = 0;
		
		//Get properties first
		$curPropName = self::$nameTable[self::INDEX($propData, $opointer, $l)];
		while (strtolower($curPropName) != 'none'){
			$props     = array('name' => $curPropName);
			$opointer += $l;
			$infoByte  = self::BYTE($propData, $opointer);
			$opointer += 1;
			
			//Property type
			$props['type'] = self::$PROPTYPES[$infoByte & 0x0F];
			if ($props['type'] == 'struct'){
				$props['subtype'] = self::$nameTable[self::INDEX($propData, $opointer, $l)];
				$opointer        += $l;
			}
			
			//Property length
			$lenInfo = (($infoByte >> 4) & 0x07);
			switch ($lenInfo){
				case 0:		$props['length'] = 1;		break;
				case 1:		$props['length'] = 2;		break;
				case 2:		$props['length'] = 4;		break;
				case 3:		$props['length'] = 12;		break;
				case 4:		$props['length'] = 16;		break;
				case 5:
					$props['length'] = self::BYTE($propData, $opointer);
					$opointer       += 1;
					break;
				case 6:
					$props['length'] = self::WORD($propData, $opointer);
					$opointer       += 2;
					break;
				case 7:
					$props['length'] = self::DWORD($propData, $opointer);
					$opointer       += 4;
					break;
				default:
					$props['length'] = 1;
			}
			
			//Property special flags
			$upBitFlag = (bool)($infoByte >> 7);
			if ($props['type'] != 'bool' && $upBitFlag){
				if (count($properties) > 0){
					$prev_el = &$properties[count($properties)-1];
					if ($prev_el['name'] == $props['name'] && !isset($prev_el['subtype'])){
						$prev_el['subtype'] = 'array';
						$prev_el['index']   = 0;
					}
				}
				
				if (@$prev_el['index'] >= 16383){
					$array_i   = (self::DWORD($propData, $opointer) & 0x3FFFFFFF);
					$opointer += 4;
				} else if (@$prev_el['index'] >= 127){
					$array_i   = (self::WORD($propData, $opointer) & 0x7FFF);
					$opointer += 2;
				} else {
					$array_i   = self::BYTE($propData, $opointer);
					$opointer += 1;
				}
				
				$props['aggtype'] = 'array';
				$props['index'] = $array_i;
			}
			
			
			
			//Get value
			switch ($props['type']){
				case 'byte':
					$props['val'] = self::BYTE($propData, $opointer);
					$opointer    += $props['length'];
					break;
				case 'int':
					$props['val'] = self::DWORD($propData, $opointer);
					if (PHP_INT_SIZE == 8 && $props['val'] > 0x7FFFFFFF)
						$props['val'] -= 0x100000000;
					$opointer    += $props['length'];
					break;
				case 'bool':
					$props['val'] = $upBitFlag;
					break;
				case 'object':
					$props['val'] = self::INDEX($propData, $opointer, $l);
					$opointer    += $props['length'];
					break;
				case 'float':
					$props['val'] = self::get32Float(self::DWORD($propData, $opointer));
					$opointer    += $props['length'];
					break;
				case 'str':
					$len = self::INDEX($propData, $opointer, $l);
					$opointer    += $l;
					$props['val'] = substr($propData, $opointer, $len-1);
					$opointer += $len;
					break;
				case 'name':
					$props['val'] = self::$nameTable[self::INDEX($propData, $opointer, $l)];
					$opointer    += $l;
					break;
				case 'struct':
					switch (strtolower($props['subtype'])){
						case 'color':
							$props['val']['R'] = self::BYTE($propData, $opointer);
							$opointer++;
							$props['val']['G'] = self::BYTE($propData, $opointer);
							$opointer++;
							$props['val']['B'] = self::BYTE($propData, $opointer);
							$opointer++;
							$props['val']['A'] = self::BYTE($propData, $opointer);
							$opointer++;
							break;
						case 'vector':
							$props['val']['X'] = self::get32Float(self::DWORD($propData, $opointer));
							$opointer += 4;
							$props['val']['Y'] = self::get32Float(self::DWORD($propData, $opointer));
							$opointer += 4;
							$props['val']['Z'] = self::get32Float(self::DWORD($propData, $opointer));
							$opointer += 4;
							break;
						case 'rotator':
							$props['val']['Pitch'] = self::DWORD($propData, $opointer);
							$opointer += 4;
							$props['val']['Yaw']   = self::DWORD($propData, $opointer);
							$opointer += 4;
							$props['val']['Roll']  = self::DWORD($propData, $opointer);
							$opointer += 4;
							break;
						case 'scale':
							$props['val']['X'] = self::get32Float(self::DWORD($propData, $opointer));
							$opointer += 4;
							$props['val']['Y'] = self::get32Float(self::DWORD($propData, $opointer));
							$opointer += 4;
							$props['val']['Z'] = self::get32Float(self::DWORD($propData, $opointer));
							$opointer += 4;
							$props['val']['SheerRate'] = self::DWORD($propData, $opointer);
							$opointer += 4;
							$props['val']['SheerAxis'] = self::BYTE($propData, $opointer);
							$opointer += 1;
							break;
						case 'pointregion':
							$props['val']['Zone']       = self::INDEX($propData, $opointer, $l);
							$opointer += $l;
							$props['val']['iLeaf']      = self::DWORD($propData, $opointer);
							$opointer += 4;
							$props['val']['ZoneNumber'] = self::BYTE($propData, $opointer);
							$opointer += 1;
							break;
						default:
							$opointer += $props['length'];
					}
					break;
				case 'class':
					//Check later
				case 'string':
				case 'array':
				case 'vector':
				case 'rotator':
				case 'map':
				case 'fixed':
					//Unknown
				default:
					$opointer += $props['length'];
			}
			
			$properties[] = $props;
			$curPropName  = self::$nameTable[self::INDEX($propData, $opointer, $l)];
		}
		
		$propertiesSSize = $opointer + 1;
		return $properties;
	}
	
	
	public static function getTexture($ssize, $soffset, $getImgResource=true, $desired_max_resbits=0){
		if (self::$loadedPackage === false || empty($ssize) || empty($soffset))
			return null;
			
		self::getExportTable();
			
		$properties_size = 0;
		$texProps        = self::getObjectProperties($ssize, $soffset, $properties_size);
		$texData         = substr(self::$loadedPackage, $soffset + $properties_size, $ssize - $properties_size);
		$texpointer      = $l = 0;
		
		$palette       = null;
		$compressLevel = 0;
		foreach ($texProps as $tPro){
			$tname = strtolower($tPro['name']);
			if ($tname == 'palette'){
				$palette = $tPro;
				unset($palette['name']);
				break;
			}
		}
		unset($texProps);
		
		if (!empty($palette)){
			$tbtype = null;
			$palObj = self::getObject($palette['val'], $tbtype);
			if ($tbtype == 'export')
				$palette = self::getPalette($palObj['SerialSize'], $palObj['SerialOffset'], array('stream_data' => $getImgResource));
		}
		
		
		$mipcount    = self::BYTE($texData, $texpointer);
		$texpointer += 1;

		for ($m = $mipcount; $m > 0; $m--){
			$widthOffset = self::DWORD($texData, $texpointer);
			$texpointer += 4;
			$texSize = self::INDEX($texData, $texpointer, $l);
			$texpointer += $l;
			$texBytemap  = substr($texData, $texpointer, $texSize);
			$texpointer += $texSize;
			$texW = self::DWORD($texData, $texpointer);
			$texpointer += 4;
			$texH = self::DWORD($texData, $texpointer);
			$texpointer += 4;
			
			if ($desired_max_resbits > 0){
				$bitsW = self::BYTE($texData, $texpointer);
				$texpointer++;
				$bitsH = self::BYTE($texData, $texpointer);
				$texpointer++;
				if (max($bitsW, $bitsH) <= $desired_max_resbits)
					break;
			} else
				break;
		}
		
		$texDFinal = array(
			'palette'  => &$palette,
			'mipcount' => &$mipcount,
			'size'     => &$texSize,
			'width'    => &$texW,
			'height'   => &$texH,
			'data'     => &$texBytemap,
		);
		
		if ($getImgResource)
			return self::getImageResource($texDFinal);
		return $texDFinal;
	}
	
	
	public static function getPalette($ssize, $soffset, $options=array()){
		if (self::$loadedPackage === false || empty($ssize) || empty($soffset))
			return null;
			
		$defOptions = array(
			'ignoreAlpha' => true,
			'stream_data' => true,
			'raw_data'    => false,
		);
		$options = array_merge($defOptions, $options);
		
		self::getExportTable();
			
		$properties_size = 0;
		self::getObjectProperties($ssize, $soffset, $properties_size);
		$palData    = substr(self::$loadedPackage, $soffset + $properties_size, $ssize);
		$palpointer = $l = 0;

		$color_count = self::INDEX($palData, $palpointer, $l);
		$pal_fdata   = array('color_count' => $color_count);
		$palpointer += $l;
		
		if ($options['raw_data']){
			$pal_fdata['data'] = '';
			for ($i = 0; $i < $color_count; $i++){
				$pal_fdata['data'] .= substr($palData, $palpointer, ($options['ignoreAlpha'] ? 3 : 4));
				$palpointer += 4;
			}
		} else if ($options['stream_data']){
			$pal_fdata['data'] = array();
			for ($i = 0; $i < $color_count; $i++){
				$d = substr($palData, $palpointer, ($options['ignoreAlpha'] ? 3 : 4));
				if ($options['ignoreAlpha'])
					$pal_fdata['data'][] = (ord($d[0])<<16) + (ord($d[1])<<8) + ord($d[2]);
				else
					$pal_fdata['data'][] = (ord($d[0])<<24) + (ord($d[1])<<16) + (ord($d[2])<<8) + ord($d[3]);
				$palpointer += 4;
			}
		} else {
			$colors = array();
			for ($i = 0; $i < $color_count; $i++){
				$colors[$i]['R']     = self::BYTE($palData, $palpointer);
				$palpointer++;
				$colors[$i]['G']     = self::BYTE($palData, $palpointer);
				$palpointer++;
				$colors[$i]['B']     = self::BYTE($palData, $palpointer);
				$palpointer++;
				if (!$options['ignoreAlpha'])
					$colors[$i]['A'] = self::BYTE($palData, $palpointer);
				$palpointer++;
			}
			$pal_fdata['colors'] = &$colors;
		}
		return $pal_fdata;
	}
	
	
	public static function getSound($ssize, $soffset){
		if (self::$loadedPackage === false || empty($ssize) || empty($soffset))
			return null;
			
		self::getNameTable();
		self::getExportTable();
			
		$properties_size = 0;
		$sndProps        = self::getObjectProperties($ssize, $soffset, $properties_size);
		$sndData         = substr(self::$loadedPackage, $soffset + $properties_size, $ssize - $properties_size);
		$sndpointer      = $l = 0;
		
		$format      = self::$nameTable[self::INDEX($sndData, $sndpointer, $l)];
		$sndpointer += ($l + 4);
		$size        = self::INDEX($sndData, $sndpointer, $l);
		$sndpointer += $l;
		$data        = substr($sndData, $sndpointer, $size);
		
		return array(
			'size'   => &$size,
			'format' => &$format,
			'data'   => &$data,
		);
	}
}