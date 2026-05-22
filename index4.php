<?php

/* UPackage usage example 
 * (var_dump shows the data and its structure, 
 * 	run it at least once to know what kind of info you're going to get out of this class)
 */

require_once 'UPackage.php';

echo '<pre>';

$packages = array('test.utx');
		
$ttotal = microtime(true);
foreach ($packages as $package){
	try {
		
		//-- Load package and get GUID
		echo "Loading package $package\n";
		UPackage::loadPackage($package);
		echo "GUID: ".UPackage::getGUID(true)."\n";
		
		$t = microtime(true);
		
		//var_dump(UPackage::getSignature(true));
		//var_dump(UPackage::getVersion());
		//var_dump(UPackage::getNameTable());
		//var_dump(UPackage::getImportTable());
		//var_dump(UPackage::getExportTable());
		//var_dump(UPackage::getDependencies());
		//var_dump(UPackage::getOuterClasses());
		//var_dump(UPackage::getInnerClasses());
		//var_dump(UPackage::getClassTree());
		//var_dump(UPackage::getObjects());
		
		//-- Get level summary
		/*$levelInfo = UPackage::getObjects('LevelSummary');
		$levelInfo = @$levelInfo['LevelSummary'];
		if (!empty($levelInfo['ssize'])){
			$prop_size = 0;
			$op = UPackage::getObjectProperties($levelInfo['ssize'], $levelInfo['soffset'], $prop_size, $levelInfo['objflags']);
			var_dump($op);
		}*/
		
		//-- Get and save sounds
		/*$snds = UPackage::getObjects('Sound', 10);
		foreach ($snds as $sndname=>$snd){
			var_dump($sndname);
			$sound = UPackage::getSound($snd['ssize'], $snd['soffset']);
			if (!empty($sound['data']))
				file_put_contents("savedsounds/$sndname.".strtolower($sound['format']), $sound['data']);
		}*/

		//-- Get and save textures (map screenshot or any)
		//$texs = UPackage::getObjects('Texture', 5, 2);
		$texs = UPackage::getObjects('Screenshot', 1);
		if (!empty($texs['ssize'])){
			$img = UPackage::getTexture($texs['ssize'], $texs['soffset']);
			if (!empty($img))
				imagepng($img, "savedimages/Screenshot-".reset(explode('.', $package)).".png", 9);
		} else {
			foreach ($texs as $texname=>$tex){
				var_dump($texname);
				$img = UPackage::getTexture($tex['ssize'], $tex['soffset']);
				if (!empty($img))
					imagepng($img, "savedimages/$texname.png", 9);
			}
		}
		
		echo "CPU Time = ".(microtime(true) - $t)." seconds \n";
		
	} catch (Exception $e) {
		echo "ERROR: ".$e->getMessage()."\n";
	}
	echo "_____________________________________________________________________\n\n";
}
echo "Total CPU Time = ".(microtime(true) - $ttotal)." seconds \n";
