<?php
declare(strict_types=1);
/**
 * Source-only regression for file-info summary/examiner parity.
 * No DB connections or writes. Real route HTML is checked separately.
 */
$source=file_get_contents(dirname(__DIR__,2).'/catalog/file-info.php');
$assert=static function(bool $condition,string $reason):void{
 if(!$condition) throw new RuntimeException($reason);
};
$assert(str_contains($source,'function file_info_identity_card('),'Shared identity presenter missing');
$assert(str_contains($source,'<div class="card hero" id="top">'),'Examiner-style hero missing');
$assert(str_contains($source,'<h2>Catalogue metadata &amp; package identity</h2>'),'Metadata heading missing');
$assert(str_contains($source,'<div class="two-col"><table><tbody>'),'Two-column identity layout missing');
$assert(str_contains($source,'class="file-info-tabs"'),'Examiner-like navigation missing');
foreach(['names','imports','exports','uses','used-by'] as $tab){
 $assert(str_contains($source,"&amp;tab=".$tab),'Missing navigation tab '.$tab);
}
foreach(['FPackageId (IoStore index)','GUID (catalogue)','Source IoStore file','File size','MD5','SHA1'] as $field){
 $assert(str_contains($source,$field),'Required identity field dropped '.$field);
}
$assert(str_contains($source,'catalog_file_feedback_form_html($id)'),'Feedback form lost');
$assert(str_contains($source,'file_info_imports_table('),'Bounded import preview lost');
$assert(str_contains($source,'file_info_related_files_table('),'Relationship diagnostics lost');
$assert(str_contains($source, "=== 'failed')"),
 'Failed-file diagnostic route lost');
echo json_encode(['ok'=>true,'summary_layout'=>'examiner-matched','classic_zen_metadata'=>true,
 'tabs'=>5,'diagnostics_retained'=>true,'database_writes'=>0],JSON_PRETTY_PRINT)."\n";
