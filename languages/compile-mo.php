<?php
/**
 * Simple PO to MO compiler
 * 
 * Usage: php compile-mo.php
 */

function po_to_mo($po_file, $mo_file) {
    $po_content = file_get_contents($po_file);
    
    // Parse PO file
    $entries = [];
    $lines = explode("\n", $po_content);
    $current_msgid = '';
    $current_msgstr = '';
    $in_msgid = false;
    $in_msgstr = false;
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        if (empty($line) || $line[0] === '#') {
            // Save previous entry
            if ($current_msgid && $current_msgstr) {
                $entries[$current_msgid] = $current_msgstr;
            }
            $current_msgid = '';
            $current_msgstr = '';
            $in_msgid = false;
            $in_msgstr = false;
            continue;
        }
        
        if (strpos($line, 'msgid ') === 0) {
            if ($current_msgid && $current_msgstr) {
                $entries[$current_msgid] = $current_msgstr;
            }
            $current_msgid = parse_po_string(substr($line, 6));
            $current_msgstr = '';
            $in_msgid = true;
            $in_msgstr = false;
        } elseif (strpos($line, 'msgstr ') === 0) {
            $current_msgstr = parse_po_string(substr($line, 7));
            $in_msgid = false;
            $in_msgstr = true;
        } elseif ($line[0] === '"' && $in_msgid) {
            $current_msgid .= parse_po_string($line);
        } elseif ($line[0] === '"' && $in_msgstr) {
            $current_msgstr .= parse_po_string($line);
        }
    }
    
    // Save last entry
    if ($current_msgid && $current_msgstr) {
        $entries[$current_msgid] = $current_msgstr;
    }
    
    // Remove empty msgid (header)
    unset($entries['']);
    
    // Generate MO file
    $mo = generate_mo($entries);
    file_put_contents($mo_file, $mo);
    
    echo "Compiled: $po_file -> $mo_file\n";
    echo "Entries: " . count($entries) . "\n";
}

function parse_po_string($str) {
    $str = trim($str);
    if ($str[0] === '"' && $str[strlen($str) - 1] === '"') {
        $str = substr($str, 1, -1);
    }
    return stripcslashes($str);
}

function generate_mo($entries) {
    $originals = array_keys($entries);
    $translations = array_values($entries);
    
    // MO file header
    $magic = 0x950412de;
    $revision = 0;
    $count = count($entries);
    
    // Calculate offsets
    $originals_offset = 28;
    $translations_offset = $originals_offset + ($count * 8);
    $hash_offset = $translations_offset + ($count * 8);
    $hash_size = 0;
    
    // Build string table
    $originals_table = '';
    $translations_table = '';
    $strings = '';
    $offset = $hash_offset;
    
    foreach ($originals as $i => $original) {
        $len = strlen($original);
        $originals_table .= pack('VV', $len, $offset);
        $strings .= $original . "\0";
        $offset += $len + 1;
    }
    
    foreach ($translations as $translation) {
        $len = strlen($translation);
        $translations_table .= pack('VV', $len, $offset);
        $strings .= $translation . "\0";
        $offset += $len + 1;
    }
    
    // Build MO file
    $mo = pack('V', $magic);
    $mo .= pack('V', $revision);
    $mo .= pack('V', $count);
    $mo .= pack('V', $originals_offset);
    $mo .= pack('V', $translations_offset);
    $mo .= pack('V', $hash_size);
    $mo .= pack('V', $hash_offset);
    $mo .= $originals_table;
    $mo .= $translations_table;
    $mo .= $strings;
    
    return $mo;
}

// Run
$po_file = __DIR__ . '/wp-to-cf-zh_CN.po';
$mo_file = __DIR__ . '/wp-to-cf-zh_CN.mo';

if (!file_exists($po_file)) {
    die("Error: $po_file not found\n");
}

po_to_mo($po_file, $mo_file);
echo "Done!\n";
