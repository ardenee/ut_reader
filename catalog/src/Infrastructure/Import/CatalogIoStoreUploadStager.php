<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;

/**
 * Stages one complete HTTP multipart IoStore container family under the
 * existing jobs/incoming retention root before enqueueing durable work.
 */
final class CatalogIoStoreUploadStager
{
    public function __construct(private readonly string $storageRoot) {}

    /**
     * @param array<string,mixed> $tocFile Normal PHP upload field
     * @param array<string,mixed> $dataFiles PHP multiple-file upload field
     * @return array{path:string,utoc_sha256:string,partition_count:int,total_bytes:int}
     */
    public function stage(array $tocFile,array $dataFiles):array
    {
        $tocName=self::cleanName((string)($tocFile['name']??''));
        if(!str_ends_with(strtolower($tocName),'.utoc'))throw new RuntimeException('Upload requires a .utoc TOC.');
        self::assertUploaded($tocFile);
        $base=substr($tocName,0,-5);
        $nameList=$dataFiles['name']??[];
        $tempList=$dataFiles['tmp_name']??[];
        $errList=$dataFiles['error']??[];
        if(!is_array($nameList)||!is_array($tempList)||!is_array($errList)||count($nameList)>128) {
            throw new RuntimeException('IoStore data partitions must be a bounded multipart file set.');
        }
        $uploaded=[];
        foreach($nameList as $index=>$name){
            $name=self::cleanName((string)$name);
            if(!str_ends_with(strtolower($name),'.ucas'))throw new RuntimeException('IoStore data upload contains a non-UCAS file.');
            $file=['name'=>$name,'tmp_name'=>$tempList[$index]??'','error'=>$errList[$index]??UPLOAD_ERR_NO_FILE];
            self::assertUploaded($file);
            $lower=strtolower($name);
            if(isset($uploaded[$lower]))throw new RuntimeException('Duplicate IoStore data partition filename.');
            $uploaded[$lower]=$file;
        }
        $tmp=(string)$tocFile['tmp_name'];
        $toc=new Uedb5IoStoreTocReader($tmp);
        $count=(int)($toc->header()['partition_count']??0);
        if($count<1||count($uploaded)!==$count)throw new RuntimeException('Uploaded IoStore partition count differs from TOC.');
        $ordered=[$tocName=>$tocFile];
        for($i=0;$i<$count;$i++){
            $expected=$base.($i>0?'_s'.$i:'').'.ucas';
            $key=strtolower($expected);
            if(!isset($uploaded[$key]))throw new RuntimeException('Missing expected IoStore data partition: '.$expected);
            $ordered[$expected]=$uploaded[$key];
        }
        $root=rtrim($this->storageRoot,'/\\');
        if($root==='')throw new RuntimeException('Missing incoming IoStore storage root.');
        $destination=$root.'/jobs/incoming/iostore/'.bin2hex(random_bytes(16));
        if(!mkdir($destination,0700,true))throw new RuntimeException('Could not create private IoStore upload staging directory.');
        $bytes=0;
        try{
            foreach($ordered as $name=>$field){
                $target=$destination.'/'.$name;
                if(!move_uploaded_file((string)$field['tmp_name'],$target))throw new RuntimeException('Could not retain uploaded IoStore file: '.$name);
                $size=filesize($target);
                if($size===false||$size<1)throw new RuntimeException('Uploaded IoStore component was empty.');
                $bytes+=$size;
            }
            $retained=$destination.'/'.$tocName;
            if(!hash_equals($toc->tocSha256(),hash_file('sha256',$retained)))throw new RuntimeException('Staged IoStore TOC checksum changed.');
            return ['path'=>$retained,'utoc_sha256'=>$toc->tocSha256(),'partition_count'=>$count,'total_bytes'=>$bytes];
        }catch(Throwable $e){
            foreach(array_keys($ordered) as $name)if(is_file($destination.'/'.$name))@unlink($destination.'/'.$name);
            @rmdir($destination);
            throw $e;
        }
    }

    private static function cleanName(string $name):string
    {
        if($name===''||strlen($name)>160||preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D',$name)!==1
            ||str_contains($name,'..'))throw new RuntimeException('Unsafe IoStore upload filename.');
        return $name;
    }

    /** @param array<string,mixed> $file */
    private static function assertUploaded(array $file):void
    {
        if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||
           !is_uploaded_file((string)($file['tmp_name']??''))) {
            throw new RuntimeException('IoStore component was not a successful HTTP upload.');
        }
    }
}
