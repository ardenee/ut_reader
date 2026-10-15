<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
 'version'=>'202610150002',
 'description'=>'IoStore package names and bounded metadata indexing state',
 'up'=>static function(PDO $db,SchemaInspector $inspector):void {
  $table='ue_iostore_packages';
  $inspector->requireTable($table);
  $inspector->ensureColumn($table,'package_name',
    'ALTER TABLE ue_iostore_packages ADD COLUMN package_name VARCHAR(1024) NULL AFTER package_id');
  $inspector->ensureColumn($table,'metadata_status',
    "ALTER TABLE ue_iostore_packages ADD COLUMN metadata_status ENUM('indexed','ready','failed') NOT NULL DEFAULT 'indexed' AFTER publication_status");
  $inspector->ensureColumn($table,'metadata_error',
    'ALTER TABLE ue_iostore_packages ADD COLUMN metadata_error VARCHAR(1024) NULL AFTER metadata_status');
  foreach(['name_count','import_count','export_count','dependency_count'] as $column){
   $inspector->ensureColumn($table,$column,
     'ALTER TABLE ue_iostore_packages ADD COLUMN '.$column.' INT UNSIGNED NOT NULL DEFAULT 0');
  }
  $inspector->ensureIndex($table,'idx_iostore_container_name',
    'ALTER TABLE ue_iostore_packages ADD KEY idx_iostore_container_name (container_id,package_name(191))');
  $inspector->ensureIndex($table,'idx_iostore_metadata_status',
    'ALTER TABLE ue_iostore_packages ADD KEY idx_iostore_metadata_status (container_id,metadata_status,toc_index)');
 }
];
