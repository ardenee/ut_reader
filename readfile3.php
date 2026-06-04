<?php


$file = 'test.utx';


// Texture Class - Represents a single texture
class Texture
{
    public $name;
    public $data;  // Texture data (binary or metadata)
    public $properties;  // Additional properties (e.g., width, height)

    public function __construct($name, $data = null, $properties = [])
    {
        $this->name = $name;
        $this->data = $data;
        $this->properties = $properties;
    }
}

// Header Class - Represents the header of the .utx file
class Header
{
    public $magic;
    public $version;
    public $compressionFlags;
    public $numTextures;
    public $nameOffset;
    public $dataOffset;
    public $fileSize;
    public $flags;
    public $stringTableOffset;
    public $nameTableOffset;
    public $exportTableOffset;
    public $importTableOffset;
    public $checksum;

    public function __construct($data)
    {
        $this->magic = unpack('A4', substr($data, 0, 4))[1];
        $this->version = unpack('V', substr($data, 4, 4))[1];
        $this->compressionFlags = unpack('V', substr($data, 8, 4))[1];
        $this->numTextures = unpack('V', substr($data, 12, 4))[1];
        $this->nameOffset = unpack('V', substr($data, 16, 4))[1];
        $this->dataOffset = unpack('V', substr($data, 20, 4))[1];
        $this->fileSize = unpack('V', substr($data, 24, 4))[1];
        $this->flags = unpack('V', substr($data, 28, 4))[1];
        $this->stringTableOffset = unpack('V', substr($data, 32, 4))[1];
        $this->nameTableOffset = unpack('V', substr($data, 36, 4))[1];
        $this->exportTableOffset = unpack('V', substr($data, 40, 4))[1];
        $this->importTableOffset = unpack('V', substr($data, 44, 4))[1];
        $this->checksum = unpack('V', substr($data, 48, 4))[1];
    }
}

// UTXFile Class - Represents the entire .utx file
class UTXFile
{
    public $header;
    public $textures = [];

    private $fileContent;
    private $offset = 0;

    public function __construct($filePath)
    {
        $this->fileContent = file_get_contents($filePath);
        if ($this->fileContent === false) {
            throw new Exception("Failed to read file: " . $filePath);
        }

        $this->header = $this->readHeader();
        $this->parseTextures();
    }

    // Read the header information from the .utx file
    private function readHeader()
    {
        return new Header($this->fileContent);
    }

    // Parse textures or other assets in the .utx file
    private function parseTextures()
    {
        $numTextures = $this->header->numTextures;

        for ($i = 0; $i < $numTextures; $i++) {
            $texture = $this->parseTexture();
            $this->textures[] = $texture;
        }
    }

    // Parse a single texture
    private function parseTexture()
    {
        $textureNameLength = unpack('V', substr($this->fileContent, $this->offset, 4))[1];
        $this->offset += 4;

        $textureName = substr($this->fileContent, $this->offset, $textureNameLength);
        $this->offset += $textureNameLength;

        // Assuming we're extracting a basic set of texture properties for now
        $texture = new Texture($textureName);

        // Adjust parsing logic based on the version (just an example)
        if ($this->header->version >= 100) {
            // For newer versions, we might extract additional properties or metadata
            $texture->properties = $this->parseTextureProperties();
        }

        return $texture;
    }

    // Example of version-specific property extraction
    private function parseTextureProperties()
    {
        // Example: Extracting width, height, format, etc.
        $width = unpack('V', substr($this->fileContent, $this->offset, 4))[1];
        $this->offset += 4;

        $height = unpack('V', substr($this->fileContent, $this->offset, 4))[1];
        $this->offset += 4;

        // Add more properties as necessary (e.g., format type, flags, etc.)

        return [
            'width' => $width,
            'height' => $height,
        ];
    }

    // Output all textures and their properties for easy reading
    public function getTextures()
    {
        return $this->textures;
    }

    // Output header information
    public function getHeader()
    {
        return $this->header;
    }
}

// Example usage:
try {
    $utxParser = new UTXFile('test.utx');

    // Get the header
    $header = $utxParser->getHeader();
    echo "Magic: " . $header->magic . "\n";
    echo "Version: " . $header->version . "\n";
    echo "Compression Flags: " . $header->compressionFlags . "\n";
    echo "Number of Textures: " . $header->numTextures . "\n";
    echo "Name Table Offset: " . $header->nameTableOffset . "\n";
    echo "Data Table Offset: " . $header->dataOffset . "\n";
    echo "File Size: " . $header->fileSize . "\n";
    echo "Flags: " . $header->flags . "\n";
    echo "String Table Offset: " . $header->stringTableOffset . "\n";
    echo "Export Table Offset: " . $header->exportTableOffset . "\n";
    echo "Import Table Offset: " . $header->importTableOffset . "\n";
    echo "Checksum: " . $header->checksum . "\n";

    // Get all textures
    $textures = $utxParser->getTextures();
    echo "Textures found: \n";
    foreach ($textures as $texture) {
        echo "Texture Name: " . $texture->name . "\n";
        print_r($texture->properties);  // Print texture properties (e.g., width, height)
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
