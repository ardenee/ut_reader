<?php
/*
Explanation of Key Parts:
Namespace: Mapped from Java package to PHP namespace.
File Handling: Uses SplFileObject for reading files line-by-line.
Regular Expressions: Translated Java regex to PHP preg_match and preg_split.
Class and Methods: Maintained similar structure and naming conventions.
Anonymous Classes: Used for SimpleValue, ListValue, and MapValue to mimic Java's inner classes and records.
*/

namespace Net\Shrimpworks\Unreal\Packages;

use SplFileObject;

class IntFile {

    private static $DEFAULT_CHARSET = 'UTF-8';
    private static $SECTION = '/\s*\[([^\]]*)\]\s*/';
    private static $KEY_VALUE = '/\s*([^=]*)=(.*)/';
    private static $MAP_VALUE = '/\s*\((.*)\)/';
    private static $MAP_SUB = '/.*?([\s]*?,?([^=]*)=\(([^)]*)\).*?).*?/';
    private static $MAP_VALUE_SPLIT = '/,(?=(?:[^\"]*\"[^\"]*\")*[^\"]*$)/';

    private $sections;

    public function __construct($intFile, $syntheticRoot = false, $encoding = null) {
        $this->sections = [];

        $encoding = $encoding ?: self::$DEFAULT_CHARSET;

        $file = new SplFileObject($intFile, 'r');
        $section = null;

        if ($syntheticRoot) {
            $section = new Section('root', []);
            $this->sections[] = $section;
        }

        while (!$file->eof()) {
            $line = trim($file->fgets());
            if (empty($line) || strpos($line, ';') === 0) continue;

            if (preg_match(self::$SECTION, $line, $matches)) {
                $section = new Section(trim($matches[1]), []);
                $this->sections[] = $section;
            } else if ($section !== null && preg_match(self::$KEY_VALUE, $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);

                if (preg_match(self::$MAP_VALUE, $value, $mapMatches)) {
                    $vals = [];
                    $mapContent = $mapMatches[1];
                    $subMatches = preg_split(self::$MAP_VALUE_SPLIT, $mapContent);
                    foreach ($subMatches as $subMatch) {
                        if (preg_match(self::$KEY_VALUE, $subMatch, $kvMatches)) {
                            $vals[trim($kvMatches[1])] = trim(str_replace('"', '', $kvMatches[2]));
                        }
                    }
                    $value = new MapValue($vals);
                } else {
                    $value = new SimpleValue($value);
                }

                if (isset($section->values[$key])) {
                    if ($section->values[$key] instanceof ListValue) {
                        $section->values[$key]->values[] = $value;
                    } else {
                        $section->values[$key] = new ListValue([$section->values[$key], $value]);
                    }
                } else {
                    $section->values[$key] = $value;
                }
            }
        }
    }

    public function section($section) {
        foreach ($this->sections as $sec) {
            if (strcasecmp($sec->name, $section) === 0) {
                return $sec;
            }
        }
        return null;
    }

    public function sections() {
        return array_map(function($s) { return $s->name; }, $this->sections);
    }

    public function __toString() {
        return sprintf("IntFile [sections=%s]", json_encode($this->sections));
    }

    public static function SimpleValue($value) {
        return new class($value) implements Value {
            private $value;

            public function __construct($value) {
                $this->value = $value;
            }

            public function __toString() {
                return $this->value;
            }
        };
    }

    public static function ListValue($values) {
        return new class($values) implements Value {
            public $values;

            public function __construct($values) {
                $this->values = $values;
            }

            public function get($index) {
                return $this->values[$index];
            }

            public function __toString() {
                return json_encode($this->values);
            }
        };
    }

    public static function MapValue($value) {
        return new class($value) implements Value {
            private $value;

            public function __construct($value) {
                $this->value = $value;
            }

            public function get($key) {
                return $this->value[$key];
            }

            public function getOrDefault($key, $defaultValue) {
                return $this->value[$key] ?? $defaultValue;
            }

            public function containsKey($key) {
                return array_key_exists($key, $this->value);
            }

            public function __toString() {
                return json_encode($this->value);
            }
        };
    }
}

interface Value {
}

class Section {

    public $name;
    public $values;

    public function __construct($name, $values) {
        $this->name = $name;
        $this->values = $values;
    }

    public function value($key) {
        return $this->values[$key] ?? null;
    }

    public function keys() {
        return array_keys($this->values);
    }

    public function asList($key) {
        $val = $this->value($key);
        if ($val instanceof ListValue) return $val;
        if ($val !== null) return new ListValue([$val]);
        return new ListValue([]);
    }

    public function __toString() {
        return sprintf("Section [name=%s, values=%s]", $this->name, json_encode($this->values));
    }
}
