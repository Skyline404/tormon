<?php
class BEncode {
    public static function decode($string) {
        $pos = 0;
        return self::decodeElement($string, $pos);
    }

    private static function decodeElement($string, &$pos) {
        if ($pos >= strlen($string)) return null;
        $char = $string[$pos];

        if ($char === 'd') {
            $pos++;
            $dict = array();
            while ($pos < strlen($string) && $string[$pos] !== 'e') {
                $key = self::decodeElement($string, $pos);
                if ($key === null) return null;
                $val = self::decodeElement($string, $pos);
                if ($val === null) return null;
                $dict[$key] = $val;
            }
            $pos++;
            return $dict;
        } elseif ($char === 'l') {
            $pos++;
            $list = array();
            while ($pos < strlen($string) && $string[$pos] !== 'e') {
                $val = self::decodeElement($string, $pos);
                if ($val === null) return null;
                $list[] = $val;
            }
            $pos++;
            return $list;
        } elseif ($char === 'i') {
            $pos++;
            $end = strpos($string, 'e', $pos);
            if ($end === false) return null;
            $int = substr($string, $pos, $end - $pos);
            $pos = $end + 1;
            return (int)$int;
        } elseif (is_numeric($char)) {
            $colon = strpos($string, ':', $pos);
            if ($colon === false) return null;
            $len = (int)substr($string, $pos, $colon - $pos);
            $pos = $colon + 1;
            $str = substr($string, $pos, $len);
            $pos += $len;
            return $str;
        }
        return null;
    }

    public static function getFilesList($torrentString) {
        $decoded = self::decode($torrentString);
        if (!$decoded || !isset($decoded['info'])) return array();

        $info = $decoded['info'];
        $files = array();

        if (isset($info['files'])) {
            // Multi-file torrent
            foreach ($info['files'] as $f) {
                if (isset($f['path'])) {
                    // path is a list of strings
                    $files[] = implode('/', $f['path']);
                }
            }
        } elseif (isset($info['name'])) {
            // Single-file torrent
            $files[] = $info['name'];
        }

        return $files;
    }
}
?>
