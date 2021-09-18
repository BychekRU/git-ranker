<?php

namespace bychekru\git_ranker;

class Rank {
    private static
        $allowedExtensions = [],
        $ignoredExtensions = [],
        $ignoredFiles = [],
        $specialCommits = [],
        $customLinkedFiles = [],
        $fileProcessCallback,
        $rankTemplate = '// Authors: ${rank}',
        $templateFinder;

    public static function setConfig($config) {
        if (isset($config['allowed_extensions'])) self::$allowedExtensions = $config['allowed_extensions'];
        if (isset($config['ignored_extensions'])) self::$ignoredExtensions = $config['ignored_extensions'];
        if (isset($config['ignored'])) self::$ignoredFiles = $config['ignored'];
        if (isset($config['commits'])) self::$specialCommits = $config['commits'];
        if (isset($config['custom_linking'])) self::$customLinkedFiles = $config['custom_linking'];
        if (isset($config['file_process_callback'])) self::$fileProcessCallback = $config['file_process_callback'];
        else self::$fileProcessCallback = function ($file, $content) {
        };
        if (isset($config['template'])) self::$rankTemplate = $config['template'];
        if (isset($config['template_finder'])) self::$templateFinder = $config['template_finder'];
        else self::$templateFinder = function ($line) {
            return substr_count($line, '// Authors:');
        };
    }

    private static function has($name, $commit) {
        return self::processSpecialCommit($commit, $name) && in_array($name, Authors::inCommit($commit)) ? 1 : 0;
    }

    private static function countAuthors($commit) {
        $i = 0;
        foreach (Authors::getTracked() as $author)
            if (self::has($author, $commit))
                $i++;

        return $i;
    }

    private static function clearUpdateRatingChanges($file) {
        (self::$fileProcessCallback)($file, explode("\n", $file->patch));
    }

    private static function calcRank($who, $commit, $file) {
        return self::has($who, $commit) ? ($file->changes / self::countAuthors($commit)) : 0;
    }

    private static function calcFileRank($obj, $commit, $file) {
        $obj->changes += $file->changes;
        $obj->additions += $file->additions;
        $obj->deletions += $file->deletions;

        if ($commit) self::clearUpdateRatingChanges($file);

        foreach ($obj->rank as $author => $number) {
            $obj->rank[$author] += $commit ? self::calcRank($author, $commit, $file) : $file->rank[$author];
        }
    }

    private static function calcRankPercent($who, $data) {
        $totalRank = array_sum($data->rank);
        return round(($data->rank[$who] / $totalRank) * 100, 2, PHP_ROUND_HALF_DOWN);
    }

    private static function processSpecialCommit($commit, $who) {
        $hash = $commit->sha;

        // commit not exists in custom array, don't apply this rule
        if (!isset(self::$specialCommits[$hash]))
            return true;
        else {
            //var_dump(in_array($who, self::$specialCommits[$hash]));
            return in_array($who, self::$specialCommits[$hash]);
        }
    }

    private static function allowedExtension($path) {
        foreach (self::$ignoredExtensions as $extension) {
            $extension = trim($extension);
            if (substr($path, -strlen($extension)) == $extension) return false;
        }
        if (count(self::$allowedExtensions) == 0) return true;
        foreach (self::$allowedExtensions as $extension) {
            $extension = trim($extension);
            if (substr($path, -strlen($extension)) == $extension) return true;
        }
        return false;
    }

    private static function allowedFile($path) {
        foreach (self::$ignoredFiles as $file) {
            $file = trim($file);
            if (substr($path, -strlen($file)) == $file) return false;
        }
        return true;
    }

    public static function calc() {
        Profiler::start();
        // get all commits
        $data = Git::getAllCommits();

        $files = [];
        $files_link = [];

        foreach ($data as $commit) {
            foreach ($commit->files as $file) {
                // process allowed/ignored extensions
                if (!self::allowedExtension($file->filename)) continue;

                if (!isset($files[$file->filename])) {
                    $authors = [];
                    foreach (Authors::getTracked() as $author) {
                        $authors[$author] = 0;
                    }

                    $files[$file->filename] = (object) [
                        'status' => $file->status,
                        'changes' => 0,
                        'additions' => 0,
                        'deletions' => 0,
                        'rank' => $authors,
                    ];
                }

                $files[$file->filename]->status = $file->status;

                self::calcFileRank($files[$file->filename], $commit, $file);

                if ($file->previous_filename) {
                    $files_link[$file->filename] = $file->previous_filename;
                }
            }
        }

        // respect renamed files
        foreach ($files_link as $file => $old_name) {
            self::calcFileRank($files[$file], false, $files[$old_name]);
            unset($files[$old_name]);
        }

        // process custom linking
        foreach (self::$customLinkedFiles as $file => $old_name) {
            // TODO: add error reporting in config
            self::calcFileRank($files[$file], false, $files[$old_name]);
            unset($files[$old_name]);
        }

        // delete removed files
        foreach ($files as $file => $data) {
            if ($data->status == 'removed') unset($files[$file]);
        }

        // delete ignored files
        foreach ($files as $file => $data) {
            if (!self::allowedFile($file)) unset($files[$file]);
        }

        // calc rating percent
        foreach ($files as $file => $data) {
            $authors = [];
            foreach (Authors::getTracked() as $author) {
                $authors[$author] = self::calcRankPercent($author, $data);
            }

            array_multisort(array_values($authors), SORT_DESC, $authors);

            $str = [];
            foreach ($authors as $author => $rank) {
                $str[] = $author . ($rank > 0 ? ' (' . $rank . '%)' : '');
            }

            $files[$file]->rating = join(', ', $str);
        }

        return $files;
    }

    public static function write($files = []) {
        $root = Git::getRepoPath();
        if (count($files) < 1) $files = self::calc();

        $updatedFiles = [];

        foreach ($files as $file => $data) {
            $content = file($root . $file);
            $i = 0;
            $oldRating = '';

            foreach ($content as $line) {
                if ((self::$templateFinder)($line)) {
                    $oldRating = $line;

                    $content[$i] = str_replace(['${rank}', '${rating}'], $data->rating, self::$rankTemplate) . PHP_EOL;
                    break; // if the needed line found, stop going further
                }

                $i++;
            }

            if (isset($content[$i])) {
                // don't write when no changes
                if ($oldRating != $content[$i]) {
                    $updatedFiles[] = $file;
                    file_put_contents($root . $file, join('', $content));
                }
            }
        }

        return [
            'status' => 'ok',
            'elapsed_time' => Profiler::time(),
            'updated_files' => count($updatedFiles),
            'updated_files_list' => $updatedFiles,
        ];
    }
}
