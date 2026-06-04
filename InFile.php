
<?php

class IntFile {

    private const DEFAULT_CHARSET = 'UTF-8';

    private const SECTION = '/\s*\[([^]]*)\]\s*/';
    private const KEY_VALUE = '/\s*([^=]*)=(.*)/';

    private const MAP_VALUE = '/\s*\((.*)\)/';
    private const MAP_SUB = '/.*?([\s]*?,?([^=]*)=\(([^)]*)\).*?).*?/';
    private const MAP_VALUE_SPLIT = '/,(?=(?:[^\"]*\"[^\"]*\")*[^\"]*$)/';

    private $sections;

    public function __construct($intFile, $syntheticRoot = false, $encoding = self::DEFAULT_CHARSET) {
        $this->sections = [];

        $channel = fopen($intFile, 'r');
        if (!$channel) {
            throw new Exception("Failed to open file: $intFile");
        }

        $section = null;
        if ($syntheticRoot) {
            $section = new Section("root", []);
            $this->sections[] = $section;
        }

        while (($r = fgets($channel)) !== false) {
            if (preg_match(self::SECTION, $r, $m)) {
                $section = new Section(trim($m[1]), []);
                $this->sections[] = $section;
            } elseif ($section !== null) {
                if (preg_match(self::KEY_VALUE, $r, $m)) {
                    $k = trim($m[1]);
                    $v = trim($m[2]);

                    if (preg_match(self::MAP_VALUE, $v, $m)) {
                        $vals = [];
                        foreach (preg_split(self::MAP_VALUE_SPLIT, trim($m[1])) as $s) {
                            if (preg_match(self::KEY_VALUE, $s, $m)) {
                                $vals[trim($m[1])] = str_replace('"', '', trim($m[2]));
                            }
                        }
                        $value = new MapValue($vals);
                    } else {
                        $value = new SimpleValue($v);
                    }

                    $current = $section->getValue($k);
                    if ($current instanceof ListValue) {
                        $current->addValue($value);
                    } elseif ($current !== null) {
                        $section->setValue($k, new ListValue([$current, $value]));
                    } else {
                        $section->setValue($k, $value);
                    }
                }
            }
        }

        fclose($channel);
    }

    public function section($section) {
        foreach ($this->sections as $s) {
            if (strcasecmp($s->getName(), $section) === 0) {
                return $s;
            }
        }
        return null;
    }

    public function sections() {
        return array_map(function($s) {
            return $s->getName();
        }, $this->sections);
    }

    public function __toString() {
        return sprintf("IntFile [sections=%s]", json_encode($this->sections));
    }
}

class Section {

    private $name;
    private $values;

    public function __construct($name, $values) {
        $this->name = $name;
        $this->values = $values;
    }

    public function getName() {
        return $this->name;
    }

    public function getValue($key) {
        return $this->values[$key] ?? null;
    }

    public function setValue($key, $value) {
        $this->values[$key] = $value;
    }

    public function keys() {
        return array_keys($this->values);
    }

    public function asList($key) {
        $val = $this->getValue($key);
        if ($val instanceof ListValue) return $val;
        if ($val !== null) return new ListValue([$val]);
        return new ListValue([]);
    }

    public function __toString() {
        return sprintf("Section [name=%s, values=%s]", $this->name, json_encode($this->values));
    }
}

interface Value {
}

class SimpleValue implements Value {

    private $value;

    public function __construct($value) {
        $this->value = $value;
    }

    public function __toString() {
        return $this->value;
    }
}

class ListValue implements Value {

    private $values;

    public function __construct($values) {
        $this->values = $values;
    }

    public function get($index) {
        return $this->values[$index];
    }

    public function addValue($value) {
        $this->values[] = $value;
    }

    public function __toString() {
        return json_encode($this->values);
    }
}

class MapValue implements Value {

    private $value;

    public function __construct($value) {
        $this->value = $value;
    }

    public function get($key) {
        return $this->value[$key] ?? null;
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
}
?>


