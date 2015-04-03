<?php

/**
 * File System monitor | FSMon
 * @version 1.0.3
 * @author j4ck <rustyj4ck@gmail.com>
 * @link https://github.com/rustyJ4ck/FSMon
 */

set_time_limit(0);

$root_dir = $this_dir = dirname(__FILE__) . DIRECTORY_SEPARATOR;

// read config

$config = @include($this_dir . 'config.php');

if (isset($config['root'])) {
    $root_dir = $config['root'];
}

$files_preg = @$config['files'];

// server name

$SERVER_NAME = @$config['server'] ? $config['server'] : @$_SERVER['SERVER_NAME'];
$SERVER_NAME = $SERVER_NAME ? $SERVER_NAME : 'localhost';

$precache = $cache = array();

console::start();

$first_run = false;

// read cache

$cache_file = $this_dir . '.cache';

if (file_exists($cache_file)) {
    $precache = $cache = unserialize(file_get_contents($cache_file));
} else {
    $first_run = true;
}

// scan 

$result = array();

$checked_ids = array();

$tree = array();
fs::build_tree($root_dir, $tree, @$config['ignore_dirs'], $files_preg);

$files = $tree['files'];

foreach ($files as $f) {

    console::log("...%s", $f);

    $id = fs::file_id($f);

    $checked_ids [] = $id;

    if (isset($cache[$id])) {
        // present

        $csumm = fs::crc_file($f);
        if ($cache[$id]['crc'] != $csumm) {
            // modded
            $cache[$id]['crc']  = $csumm;
            $cache[$id]['file'] = $f;
            $result[]           = array('file' => $f, 'result' => 'modified');
        } else {
            // old one
        }
    } else {
        // new
        $csumm              = fs::crc_file($f);
        $cache[$id]['crc']  = $csumm;
        $cache[$id]['file'] = $f;
        $result[]           = array('file' => $f, 'result' => 'new');
    }

}

// check for deleted files

$deleted = !empty($precache) ? array_diff(array_keys($precache), $checked_ids) : false;

if (!empty($deleted)) {
    foreach ($deleted as $id) {
        $result[] = array('file' => $precache[$id]['file'], 'result' => 'deleted');
        unset($cache[$id]);
    }
}

//
// Changes detected
//

if (!empty($result)) {
    $buffer = '';

    console::log('Reporting...');

    foreach ($result as $r) {

        $line = sprintf(
            "[%10s]\t%s\t%s kb\t%s",
            $r['result'],
            $r['file'],
            @round(filesize($r['file']) / 1024, 1),
            @date('d.m.Y H:i', filemtime($r['file']))
        );

        console::log($line);

        $buffer .= $line;
        $buffer .= PHP_EOL;
    }

    if ($first_run) {
        $buffer = "[First Run]\n\n" . $buffer;
    }

    // log 

    if (@$config['log']) {

        $logs_dir = dirname(__FILE__) . '/logs/' . date('Ym');
        @mkdir($logs_dir, 0770, 1);

        file_put_contents($logs_dir . '/' . date('d-H-i') . '.log', $buffer);
    }

    // mail

    if (@$config['mail']['enable'] && !$first_run) {

        $from = @$config['mail']['from'] ? $config['mail']['from'] : 'root@localhost';
        $to   = @$config['mail']['to'] ? $config['mail']['to'] : 'root@localhost';

        if ($to === 'root@localhost') {
            echo "Empty mail@to";
        } else {

            $subject = "FSMon report for " . $SERVER_NAME;
            $buffer .= "\n\nGenerated by FSMon | " . date('d.m.Y H:i') . '.';

            console::log('Message to %s', $to);

            mailer::send(
                $from,
                $to,
                $subject,
                $buffer
            );
        }
    }
} else {
    console::log('All clear');
}

//
// save result
//

file_put_contents(
    $cache_file
    ,
    serialize($cache)
);

console::log('Done');

//
// Done
//

class console
{

    private static $time;

    static function start()
    {
        self::$time = microtime(1);
    }

    static function log()
    {
        $args   = func_get_args();
        $format = array_shift($args);
        $format = '%.5f| ' . $format;
        array_unshift($args, self::time());
        echo vsprintf($format, $args);
        echo PHP_EOL;
    }

    private static function time()
    {
        return microtime(1) - self::$time;
    }
}

/**
 * Mail helper
 */
class mailer
{

    static function send($from, $to, $subject, $message)
    {

        $headers = 'From: ' . $from . "\r\n" .
            'Reply-To: ' . $from . "\r\n" .
            "Content-Type: text/plain; charset=\"utf-8\"\r\n" .
            'X-Mailer: PHP/fsmon';

        return mail($to, $subject, $message, $headers);
    }

}

/**
 * FileSystem helpers
 */
class fs
{

    const DS              = DIRECTORY_SEPARATOR;
    const IGNORE_DOT_DIRS = true;

    /**
     * Find files
     */
    public static function scan_dir_for_files($o_dir, $files_preg = '')
    {
        $ret = array();
        $dir = @opendir($o_dir);
        while (false !== ($file = @readdir($dir))) {
            $path = $o_dir . /*DIRECTORY_SEPARATOR .*/
                $file;
            if ($file !== '..' && $file !== '.' && !is_dir($path)
                && (empty($files_preg) || (!empty($files_preg) && preg_match("#{$files_preg}#", $file)))
            ) {
                $ret [] = $path;
            }
        }
        @closedir($dir);

        return $ret;
    }

    /**
     * Scan dirs. One level
     */
    public static function scan_dir_for_dirs($o_dir)
    {

        $ret = array();
        $dir = @opendir($o_dir);

        while (false !== ($file = @readdir($dir))) {
            $path = $o_dir /*. DIRECTORY_SEPARATOR*/ . $file;
            if ($file !== '..' && $file !== '.' && is_dir($path)) {
                $ret [] = $path;
            }
        }

        @closedir($dir);

        return $ret;
    }

    /**
     * Build tree
     *
     * @desc build tree
     * @param string|array root
     * @param array &buffer
     * @param array dir filters
     * @param string file regex filter
     * @return array['files' = [...], 'dirs' = [...]]
     */
    public static function build_tree($root_path, array &$data, $dirs_filter = array(), $files_preg = '.*')
    {

        if (empty($root_path)) {
            return;
        }

        if (!is_array($root_path)) {
            $root_path = array($root_path);
        }

        $dirs  = array();
        $files = array();

        if (empty($data)) {
            $data['files'] = array();
            $data['dirs']  = array();
        }

        foreach ($root_path as $path) {
            $_path = $path; //no-slash

            if (substr($path, -1, 1) != self::DS) {
                $path .= self::DS;
            }

            console::log("ls %s", $_path);

            $skipper = false;

            if (self::IGNORE_DOT_DIRS) {
                $exPath  = explode(self::DS, $_path);
                $dirname = array_pop($exPath);
                $skipper = (substr($dirname, 0, 1) === '.');
            }

            if (!$skipper && (empty($dirs_filter) || !in_array($_path, $dirs_filter))) {
                $allDirs        = self::scan_dir_for_dirs($path);
                $dirs           = array_merge($dirs, self::scan_dir_for_dirs($path));
                $files          = array_merge($files, self::scan_dir_for_files($path, $files_preg));
                $data['dirs'][] = $path;
                $data['files']  = array_merge($data['files'], $files);

                self::build_tree($allDirs, $data, $dirs_filter, $files_preg);
            } else {
                console::log("...skipped %s", $_path);
            }
        }


    }


    /**
     * unique file name
     */
    public static function file_id($path)
    {
        return md5($path);
    }

    /**
     * Checksum
     */
    public static function crc_file($path)
    {
        return sprintf("%u", crc32(file_get_contents($path)));
    }
}

