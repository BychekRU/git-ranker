<?php

namespace bychekru\git_ranker;

use ZipArchive;

class Webhook {
    private static
        $allowedExtensions = [],
        $notUpdatedFiles = [];

    public static function init($config) {
        Git::init($config);
        if (isset($config['allowed_extensions'])) self::$allowedExtensions = $config['allowed_extensions'];
        if (isset($config['not_updated_files'])) self::$notUpdatedFiles = $config['not_updated_files'];
    }

    public static function run() {
        $files = [];

        $filename = 'git_sync_' . date('Y-m-d H-i-s');
        $zipName = Git::getTempDir() . $filename . '.zip';
        $mode = Git::getMode();
        Git::setMode('remote');
        GitHub::downloadZip($zipName);
        Git::setMode($mode);

        $extractDir = Git::getTempDir() . $filename . '/';
        mkdir($extractDir);
        $unpackTo = rtrim(Git::getAppDir(),'\\/');

        $zip = new ZipArchive;
        if ($zip->open($zipName) === TRUE) {
            // get list of all files and dirs in the archieve
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $fn = $zip->getNameIndex($i);
                $files[] = (object) [
                    'name' => strchr($fn, '/'),
                    'filename' => $extractDir . $fn,
                    'target_filename' => $unpackTo . strchr($fn, '/'),
                ];
            }

            // extract it
            $zip->extractTo($extractDir);
            $zip->close();
        }

        foreach ($files as $file) {
            // if dir
            if (substr($file->filename, -1) == '/') {
                // check existence
                if (!file_exists($file->target_filename)) {
                    // create if not exists
                    mkdir($file->target_filename, 0777);
                }
            } else {
                // if file, check allowed extensions and files shouldn't be updated
                if (!self::allowedExtension($file->name) || !self::allowFileUpdate($file->name, $file->target_filename))
                    continue;
                file_put_contents($file->target_filename, file_get_contents($file->filename));
            }
        }
        // remove temp files
        self::deleteDir($extractDir);
        unlink($zipName);
    }

    private static function allowedExtension($path) {
        if (count(self::$allowedExtensions) == 0) return true;
        foreach (self::$allowedExtensions as $extension) {
            $extension = trim($extension);
            if (substr($path, -strlen($extension)) == $extension) return true;
        }
        return false;
    }

    private static function allowFileUpdate($file, $path) {
        if (!file_exists($path)) return true;
        return !in_array($file, self::$notUpdatedFiles);
    }

    public static function deleteDir($path) {
        foreach (scandir($path) as $name) {
            if ($name == '.' || $name == '..') continue;
            $name = $path . '/' . $name;
            if (is_dir($name)) self::deleteDir($name);
            else unlink($name);
        }
        rmdir($path);
    }
}
