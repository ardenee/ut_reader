<?php

namespace net\shrimpworks\unreal\packages;

use net\shrimpworks\unreal\packages\compression\CompressedChunk;
use net\shrimpworks\unreal\packages\compression\CompressionFormat;
use net\shrimpworks\unreal\packages\entities\Export;
use net\shrimpworks\unreal\packages\entities\ExportedEntry;
use net\shrimpworks\unreal\packages\entities\ExportedField;
use net\shrimpworks\unreal\packages\entities\ExportedObject;
use net\shrimpworks\unreal\packages\entities\FieldTypes;
use net\shrimpworks\unreal\packages\entities\Import;
use net\shrimpworks\unreal\packages\entities\Name;
use net\shrimpworks\unreal\packages\entities\NameNumber;
use net\shrimpworks\unreal\packages\entities\Named;
use net\shrimpworks\unreal\packages\entities\ObjectFlag;
use net\shrimpworks\unreal\packages\entities\ObjectReference;
use net\shrimpworks\unreal\packages\entities\objects\ObjectFactory;
use net\shrimpworks\unreal\packages\entities\objects\ObjectHeader;
use net\shrimpworks\unreal\packages\entities\properties\ArrayProperty;
use net\shrimpworks\unreal\packages\entities\properties\BooleanProperty;
use net\shrimpworks\unreal\packages\entities\properties\ByteProperty;
use net\shrimpworks\unreal\packages\entities\properties\EnumProperty;
use net\shrimpworks\unreal\packages\entities\properties\FixedArrayProperty;
use net\shrimpworks\unreal\packages\entities\properties\FloatProperty;
use net\shrimpworks\unreal\packages\entities\properties\IntegerProperty;
use net\shrimpworks\unreal\packages\entities\properties\NameProperty;
use net\shrimpworks\unreal\packages\entities\properties\ObjectProperty;
use net\shrimpworks\unreal\packages\entities\properties\Property;
use net\shrimpworks\unreal\packages\entities\properties\PropertyType;
use net\shrimpworks\unreal\packages\entities\properties\StringProperty;
use net\shrimpworks\unreal\packages\entities\properties\StructProperty;
use net\shrimpworks\unreal\packages\entities\properties\UnknownArrayProperty;

class Package implements Closeable {

    const PKG_SIGNATURE = 0x9E2A83C1;
    private const MAX_PROPERTIES = 256;

    private const SHA1 = "SHA-1";

    private const PROPERTY_SIZE_MAP = [1, 2, 4, 12, 16];

    public const PackageFlag = [
        'AllowDownload' => 0x0001,
        'ClientOptional' => 0x0002,
        'ServerSideOnly' => 0x0004,
        'BrokenLinks' => 0x0008,
        'Unsecure' => 0x0010,
        'Need' => 0x8000
    ];

    private $reader;

    public $version;
    public $license;
    public $engineVersion;
    public $compressionFormat;
    public $compressedChunkCount;
    public $flags;
    public $names;
    public $exports;
    public $imports;
    public $objects;
    public $fields;

    private $loadedObjects;
    private $objectReferences;

    public function __construct($packageFile) {
        $this->reader = new PackageReader($packageFile);

        $this->reader->moveTo(0);

        if ($this->reader->readInt() != self::PKG_SIGNATURE) throw new \InvalidArgumentException("Package does not seem to be an Unreal package");

        $this->loadedObjects = new \WeakHashMap();
        $this->objectReferences = new \WeakHashMap();

        $this->version = $this->reader->readShort();
        $this->reader->version = $this->version;

        $this->license = $this->reader->readShort();

        if ($this->version >= 249) {
            $headerSize = $this->reader->readInt();
        }
        if ($this->version >= 269) {
            $folderName = $this->reader->readString();
        }

        $this->flags = $this->reader->readInt();

        $nameCount = $this->reader->readInt();
        $namePos = $this->reader->readInt();

        $exportCount = $this->reader->readInt();
        $exportPos = $this->reader->readInt();

        $importCount = $this->reader->readInt();
        $importPos = $this->reader->readInt();

        if ($this->version >= 415) {
            $dependsPos = $this->reader->readInt();
        }

        if ($this->version >= 584) {
            $unknown = array_fill(0, 16, 0);
            $this->reader->readBytes($unknown, 0, 16);
        }

        if ($this->version < 68) {
            $this->reader->readInt();
            $this->reader->readInt();
        } else {
            $guid = array_fill(0, 16, 0);
            $this->reader->readBytes($guid, 0, 16);
            $generationCount = $this->reader->readInt();
            for ($i = 0; $i < $generationCount; $i++) {
                $this->reader->readInt();
                $this->reader->readInt();
                if ($this->version > 322) {
                    $this->reader->readInt();
                }
            }
        }

        $this->engineVersion = $this->version >= 245 ? $this->reader->readInt() : $this->version;

        if ($this->version >= 277) {
            $cookerVersion = $this->reader->readInt();
        }

        $this->compressionFormat = $this->version >= 334 ? CompressionFormat::fromFlag($this->reader->readInt()) : CompressionFormat::NONE;
        $this->compressedChunkCount = $this->version >= 334 ? $this->reader->readInt() : 0;
        if ($this->compressionFormat != CompressionFormat::NONE) {
            $chunks = array_fill(0, $this->compressedChunkCount, null);
            for ($i = 0; $i < $this->compressedChunkCount; $i++) {
                $chunks[$i] = new CompressedChunk(
                    $this->compressionFormat,
                    $this->reader->readInt(),
                    $this->reader->readInt(),
                    $this->reader->readInt(),
                    $this->reader->readInt()
                );
            }
            $this->reader->setChunks($chunks);
        }

        $this->names = $this->names($nameCount, $namePos);

        $this->exports = $this->exports($exportCount, $exportPos);

        $this->imports = $this->imports($importCount, $importPos);

        $this->objects = array_fill(0, count($this->exports), null);
        $this->fields = array_fill(0, count($this->exports), null);
        for ($i = 0; $i < count($this->exports); $i++) {
            $e = $this->exports[$i];
            if (FieldTypes::isField($e->classIndex)) {
                $this->fields[$i] = $e->asField();
            } else {
                $this->objects[$i] = $e->asObject();
            }
        }
    }

    public function close() {
        $this->reader->close();
    }

    public function sha1Hash() {
        return $this->reader->hash(self::SHA1);
    }

    public function flags() {
        return $this->fromFlags($this->flags);
    }

    public function packageImports() {
        $packages = [];
        foreach ($this->imports as $i) {
            if ($i->packageIndex->index == 0) $packages[] = $i;
        }
        return $packages;
    }

    public function rootExports() {
        $roots = [];
        foreach ($this->exports as $e) {
            if ($e->groupIndex->index == 0) $roots[] = $e;
        }
        return $roots;
    }

    public function exportsByClassName($className) {
        $exports = [];
        foreach ($this->exports as $ex) {
            $type = $ex->classIndex->get();
            if ($type instanceof Import && $type->name->name == $className) {
                $exports[] = $ex;
            }
        }
        return $exports;
    }

    public function objectsByClassName($className) {
        $exports = [];
        foreach ($this->objects as $ex) {
            if ($ex == null) continue;
            $type = $ex->classIndex->get();
            if ($type instanceof Import && $type->name->name == $className) {
                $exports[] = $ex;
            }
        }
        return $exports;
    }

    public function objectByRef($ref) {
        $resolved = $ref->get();
        if (!($resolved instanceof Export)) throw new \InvalidArgumentException("No exported object found for reference " . $ref);

        $exportedObject = $this->objects[$resolved->index];

        if ($exportedObject == null) throw new \InvalidArgumentException("Found export is not an object " . $ref);

        return $exportedObject;
    }

    public function objectByName($name) {
        foreach ($this->objects as $object) {
            if ($object == null) continue;

            if (strcasecmp($object->name->name, $name->name) == 0) return $object;
        }

        return null;
    }

    public function objectByExport($export) {
        $exportedObject = $this->objects[$export->index];
        if ($exportedObject == null) throw new \InvalidArgumentException("Found export is not an object " . $export);
        return $exportedObject;
    }

    private function names($count, $pos) {
        $names = [];

        $this->reader->moveTo($pos);

        for ($i = 0; $i < $count; $i++) {
            $this->reader->ensureRemaining(256); // more-or-less
            $names[$i] = new Name($this->reader->readString(), 0, $this->version >= 141 ? $this->reader->readLong() : $this->reader->readInt());
        }

        return $names;
    }

    private function exports($count, $pos) {
        assert($this->names != null && count($this->names) > 0);

        $exports = [];

        $this->reader->moveTo($pos);

        for ($i = 0; $i < $count; $i++) {
            $this->reader->ensureRemaining(128); // more-or-less, usually less
            $exports[$i] = $this->readExport($i);
        }

        return $exports;
    }

    private function imports($count, $pos) {
        assert($this->names != null && count($this->names) > 0);

        $imports = [];

        $this->reader->moveTo($pos);

        for ($i = 0; $i < $count; $i++) {
            $this->reader->ensureRemaining(40); // more-or-less, usually less
            $imports[$i] = $this->readImport($i);
        }

        return $imports;
    }

    private function objectReference($index) {
        if ($index == 0) return ObjectReference::NULL;
        else return $this->objectReferences->computeIfAbsent($index, function($i) { return new ObjectReference($this, $i); });
    }

    private function name($index) {
        return $this->names[$index];
    }

    private function name($name) {
        return new Name($this->names[$name->name]->name, $name->number, $this->names[$name->name]->flags);
    }

    private function readExport($index) {
        $classIndex = $this->objectReference($this->reader->readIndex());
        $superClassIndex = $this->objectReference($this->reader->readIndex());
        $groupIndex = $this->objectReference($this->reader->readInt());

        $name = $this->name($this->reader->readNameIndex());

        $archetype = $this->version >= 220
            ? $this->objectReference($this->reader->readInt())
            : ObjectReference::NULL;

        $flags = $this->version >= 195 ? $this->reader->readLong() : $this->reader->readInt();

        $size = $this->reader->readIndex();
        $pos = $size > 0 || $this->version >= 249
            ? $this->reader->readIndex()
            : 0;

        $components = [];
        if ($this->version >= 220 && $this->version < 543) {
            $componentCount = $this->reader->readInt();
            $components = [];
            if ($componentCount > 0) $this->reader->ensureRemaining(($componentCount * 12) + 28);
            for ($i = 0; $i < $componentCount; $i++) {
                $components[$this->name($this->reader->readNameIndex())] = $this->objectReference($this->reader->readInt());
            }
        }

        if ($this->version >= 220) {
            $exportFlags = $this->reader->readInt();
        }

        $netObjectCount = 0;
        if ($this->version >= 322) {
            $netObjectCount = $this->reader->readInt();
        }

        if ($this->version >= 220) {
            $guid = array_fill(0, 16, 0);
            $this->reader->readBytes($guid, 0, 16);
        }

        if ($this->version >= 487) {
            $packageFlags = $this->reader->readInt();
        }

        if ($netObjectCount > 0) {
            $netObjects = array_fill(0, $netObjectCount, null);
            for ($i = 0; $i < $netObjectCount; $i++) {
                $netObjects[$i] = $this->objectReference($this->reader->readIndex());
            }
        }

        return new ExportedEntry(
            $this, $index,
            $classIndex, $superClassIndex, $groupIndex,
            $name, $flags, $size, $pos,
            $components
        );
    }


    private function readImport($index) {
        $classPackage = $this->name($this->reader->readNameIndex());
        $className = $this->name($this->reader->readNameIndex());
        $packageIndex = $this->objectReference($this->reader->readInt());
        $name = $this->name($this->reader->readNameIndex());

        return new Import(
            $this, $index,
            $classPackage, $className, $packageIndex, $name
        );
    }


    public function object($export) {
        $existing = $this->loadedObjects->get($export->pos);
        if ($existing != null) return $existing;

        if ($export->size <= 0) throw new \RuntimeException(sprintf("Export %s has no associated object data!", $export->name));

        if ($export->classIndex->index == 0) return null;

        $this->reader->moveTo($export->pos);

        $header = null;
        if ($export->flags()->contains(ObjectFlag::HasStack)) {
            $node = $this->reader->readIndex();
            $header = new ObjectHeader(
                $node, $this->reader->readIndex(), $this->reader->readLong(), $this->reader->readInt(),
                $node != 0 ? $this->reader->readIndex() : 0
            );
        }

        if ($this->version >= 322) {
            $netIndex = $this->reader->readIndex();
        }

        $properties = $this->readProperties();
        $postPropsPosition = $this->reader->currentPosition();
        $newObject = ObjectFactory::newInstance($this, $this->reader, $export, $header, $properties, $postPropsPosition);
        $this->loadedObjects->put($export->pos, $newObject);

        return $newObject;
    }

    private function readProperties() {
        $properties = [];
        for ($i = 0; $i < self::MAX_PROPERTIES; $i++) {
            $p = $this->readProperty();

            if ($p->name->equals(Name::NONE())) break;
            else {
                if ($p instanceof ArrayProperty\ArrayItem && !empty($properties)) {

                    $lastProperty = $properties[count($properties) - 1];
                    if ($lastProperty instanceof ArrayProperty) {
                        array_pop($properties);
                        $properties[] = $lastProperty->add($p);
                    } else if ($lastProperty->name->equals($p->name)) {
                        array_pop($properties);
                        $properties[] = new ArrayProperty($p->property);
                    } else $properties[] = $p->property;
                } else $properties[] = $p;
            }
        }
        return $properties;
    }

    private function readProperty() {
        $name = $this->name($this->reader->readNameIndex());


        if ($name->equals(Name::NONE())) return new NameProperty($this, $name, $name);

        if ($this->version > 220) return $this->readPropertyUE3($name);

        $propInfo = $this->reader->readByte();

        $type = $propInfo & 0b00001111;
        $size = ($propInfo & 0b01110000) >> 4;
        $boolOrArrayFlag = ($propInfo & 0b10000000) != 0;

        $propType = PropertyType::get($type);

        if ($propType == null) {
            throw new \RuntimeException(sprintf("Unknown property type index %d for property %s", $type, $name->name));
        }

        $structType = null;
        if ($propType == PropertyType::StructProperty) {
            $structIdx = $this->reader->readIndex();
            $structType = $structIdx >= 0 ? StructProperty\StructType::get($this->names[$structIdx]) : null;
            if ($structType == null) {
                throw new \RuntimeException(sprintf("Unknown struct type index %d for property %s", $structIdx, $name->name));
            }
        }

        $size = match ($size) {
            0, 1, 2, 3, 4 => self::PROPERTY_SIZE_MAP[$size],
            5 => $this->reader->readByte() & 0xFF,
            6 => $this->reader->readShort(),
            7 => $this->reader->readInt(),
            default => throw new \InvalidArgumentException(sprintf("Unknown property field size %d", $size))
        };

        $arrayIndex = 0;
        if ($boolOrArrayFlag && $propType != PropertyType::BoolProperty) {
            $arrayIndex = $this->reader->readByte();
        }

        $property = $this->createProperty($name, $propType, $structType, $size, $boolOrArrayFlag);

        if ($boolOrArrayFlag && $propType != PropertyType::BoolProperty) {
            return new ArrayProperty\ArrayItem($property, $arrayIndex);
        }

        return $property;
    }

    private function readPropertyUE3($name) {
        $typeName = $this->name($this->reader->readNameIndex());
        $propType = PropertyType::get($typeName);

        if ($propType == null) {
            throw new \RuntimeException(sprintf("Unknown property type named %s for property %s", $typeName->name, $name->name));
        }

        if ($propType == PropertyType::ByteProperty) $propType = PropertyType::EnumProperty;

        $size = $this->reader->readInt();
        $arrayIndex = $this->reader->readInt();

        $structType = $propType == PropertyType::StructProperty
            ? StructProperty\StructType::get($this->name($this->reader->readNameIndex()))
            : null;

        $booleanFlag = $propType == PropertyType::BoolProperty && $this->reader->readInt() > 0;

        $property = $this->createProperty($name, $propType, $structType, $size, $booleanFlag);

        if ($propType == PropertyType::ArrayProperty && !($property instanceof ArrayProperty)) {
            return new ArrayProperty\ArrayItem($property, $arrayIndex);
        }

        return $property;
    }

    private function createProperty($name, $type, $structType, $size, $arrayFlag) {

        $startPos = $this->reader->position();

        try {
            switch ($type) {
                case PropertyType::BoolProperty:
                    return new BooleanProperty($this, $name, $arrayFlag);
                case PropertyType::ByteProperty:
                    return new ByteProperty($this, $name, $this->reader->readByte());
                case PropertyType::EnumProperty:
                    return new EnumProperty($this, $name, $this->name($this->reader->readNameIndex()));
                case PropertyType::IntProperty:
                    return new IntegerProperty($this, $name, $this->reader->readInt());
                case PropertyType::FloatProperty:
                    return new FloatProperty($this, $name, $this->reader->readFloat());
                case PropertyType::StrProperty:
                case PropertyType::StringProperty:
                    return new StringProperty($this, $name, $this->reader->readString($size));
                case PropertyType::NameProperty:
                    return new NameProperty($this, $name, $name->equals(Name::NONE()) ? Name::NONE() : $this->name($this->reader->readNameIndex()));
                case PropertyType::ObjectProperty:
                    return new ObjectProperty($this, $name, $this->objectReference($this->reader->readIndex()));
                case PropertyType::StructProperty:
                    switch ($structType) {
                        case StructProperty\StructType::PointRegion():
                            return new StructProperty\PointRegionProperty($this, $name, $this->objectReference($this->reader->readIndex()),
                                $this->reader->readInt(), $this->reader->readByte());
                        case StructProperty\StructType::Scale():
                            return new StructProperty\ScaleProperty($this, $name, $this->reader->readFloat(), $this->reader->readFloat(), $this->reader->readFloat(),
                                $this->reader->readFloat(), $this->reader->readByte());
                        case StructProperty\StructType::Rotator():
                            return new StructProperty\RotatorProperty($this, $name, $this->reader->readInt(), $this->reader->readInt(), $this->reader->readInt());
                        case StructProperty\StructType::Color():
                            return new StructProperty\ColorProperty($this, $name, $this->reader->readByte(), $this->reader->readByte(), $this->reader->readByte(),
                                $this->reader->readByte());
                        case StructProperty\StructType::Sphere():
                            return new StructProperty\SphereProperty($this, $name, $this->reader->readFloat(), $this->reader->readFloat(), $this->reader->readFloat(),
                                $this->reader->readFloat());
                        case StructProperty\StructType::Vector():
                        default:
                            if ($size == 12) {
                                return new StructProperty\VectorProperty($this, $name, $this->reader->readFloat(), $this->reader->readFloat(),
                                    $this->reader->readFloat());
                            }
                            return new StructProperty\UnknownStructProperty($this, $name);
                    }
                case PropertyType::RotatorProperty:
                    return new StructProperty\RotatorProperty($this, $name, $this->reader->readInt(), $this->reader->readInt(), $this->reader->readInt());
                case PropertyType::VectorProperty:
                    return new StructProperty\VectorProperty($this, $name, $this->reader->readFloat(), $this->reader->readFloat(), $this->reader->readFloat());
                case PropertyType::ArrayProperty:
                    $arraySize = $this->reader->readIndex();
                    if (strcasecmp($name->name, "ReferencedTextures") == 0) {
                        $items = [];
                        for ($i = 0; $i < $arraySize; $i++) {
                            $items[] = new ObjectProperty(
                                $this, $name, $this->objectReference($this->reader->readIndex())
                            );
                        }
                        return new ArrayProperty($this, $name, $items);
                    }
                    return new UnknownArrayProperty($this, $name, $arraySize);
                case PropertyType::FixedArrayProperty:
                    return new FixedArrayProperty($this, $name, $this->objectReference($this->reader->readIndex()), $this->reader->readIndex());
                default:
                    throw new \InvalidArgumentException("Cannot read unsupported property type " . $type->name());
            }
        } finally {
            if ($this->reader->position() - $startPos < $size) {
                $this->reader->moveRelative($size - ($this->reader->position() - $startPos));
            }
        }
    }

}


