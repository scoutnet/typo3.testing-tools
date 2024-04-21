<?php

function xmlToArray(SimpleXMLElement $xml): array
{
    $parser = function (SimpleXMLElement $xml, array $collection = []) use (&$parser) {
        $nodes = $xml->children();
        $attributes = $xml->attributes();

        if (count($attributes) !== 0) {
            foreach ($attributes as $attrName => $attrValue) {
                $collection['attributes'][$attrName] = (string)$attrValue;
            }
        }

        if ($nodes->count() === 0) {
            $collection['value'] = (string)$xml;
            return $collection;
        }

        foreach ($nodes as $nodeName => $nodeValue) {
            if (count($nodeValue->xpath('../' . $nodeName)) < 2) {
                $collection[$nodeName] = $parser($nodeValue);
                continue;
            }

            $collection[$nodeName][] = $parser($nodeValue);
        }

        return $collection;
    };

    return [
        $xml->getName() => $parser($xml),
    ];
}

if ($argc !== 2) {
    die('please specify input file');
}

$xml = xmlToArray(simplexml_load_string(file_get_contents($argv[1])));
$xml = $xml['dataset'];
$all_columns = [];

foreach ($xml as $database => $values) {
    $all_columns[$database] = [];

    // if we only have one object for that database make this an array
    if (!array_is_list($values)) {
        $values = [$values];
        $xml[$database] = $values;
    }

    foreach ($values as $line) {
        foreach ($line as $column => $value) {
            $all_columns[$database][$column] = true;
        }
    }
}

foreach ($all_columns as $database => $columns) {
    $columns = array_keys($columns);
    print $database . str_repeat(',', count($columns)) . "\n";
    print ',"' . implode('","', $columns) . '"' . "\n";

    foreach ($xml[$database] as $line) {
        $values = [];
        foreach ($columns as $column) {
            $value = $line[$column]['value'] ?? null;

            if (is_numeric($value)) {
                $values[] = $value;
            } elseif ($value === null) {
                $values[] = '';
            } else {
                $values[] = '"' . $value . '"';
            }
        }

        print ',' . implode(',', $values) . "\n";
    }
}
