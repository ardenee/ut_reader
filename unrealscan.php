<?php

//https://www.php.net/manual/en/class.directoryiterator.php
$dir = new DirectoryIterator(dirname(__FILE__));

foreach ($dir as $fileinfo) 
{
    if (!$fileinfo->isDot()) 
	{
        var_dump($fileinfo->getFilename());
    }
}



// https://www.php.net/manual/en/function.sha1-file.php
//echo $ent . ' (SHA1: ' . sha1_file($ent) . ')', PHP_EOL;



//INSERT INTO `files` (`id`, `FileName`, `FileSize`, `FileHash`, `FileType`, `FileVersion`, `FileGUID`, `FileGame`) VALUES (NULL, 'Unknown', '0', 'Unknown', '0', '0', 'Unknown', '0')





?>
00000001-002C-0000-A4AC-901E66EDD111