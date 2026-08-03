<?php
declare(strict_types=1);

return [
    'version' => '202608030001',
    'description' => 'Expand compact term storage so values longer than 200 bytes remain fully reconstructable.',
    'up' => static function (PDO $db, \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema): void {
        $schema->requireTable('ue_terms');

        $statement = $db->prepare(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_terms" '
            . 'AND COLUMN_NAME="value_prefix" LIMIT 1'
        );
        $statement->execute();
        $dataType = strtolower((string)($statement->fetchColumn() ?: ''));
        if ($dataType === '') {
            throw new RuntimeException('ue_terms.value_prefix is missing.');
        }
        if ($dataType !== 'mediumblob') {
            $db->exec('ALTER TABLE ue_terms MODIFY COLUMN value_prefix MEDIUMBLOB NOT NULL');
        }
    },
];
