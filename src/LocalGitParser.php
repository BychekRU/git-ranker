<?php

namespace bychekru\git_ranker;

class LocalGitParser {
    /**
     * Parses commit diff into an array
     */
    public static function parseCommitDiff($diff) {
        $diff = explode("\n", $diff);

        $additions = 0;
        $deletions = 0;
        $files = [];

        $filename = '';
        $new_filename = '';

        $gatherPatch = 1;
        $patch = '';
        foreach ($diff as $line) {
            // gathering diff
            if (substr($line, 0, 2) == '@@') {
                $gatherPatch = 1;
                $patch .= $line . PHP_EOL;
            } else if ($gatherPatch == 1) {
                if (substr($line, 0, 10) == 'diff --git')
                    $gatherPatch = 0;
                else
                    $patch .= $line . PHP_EOL;
            }
            if (substr($line, 0, 5) == '--- a' || substr($line, 0, 5) == '--- /' || substr($line, 0, 6) == '--- "a') {
                // before process the next file, update an array for the current
                if (isset($files[$filename])) $files[$filename]->patch = $patch;

                if (isset($files[$new_filename])) $files[$new_filename]->patch = $patch;

                $patch = '';
                // previous/not-chaged filename or file created
                $filename = trim(trim(substr($line, 6)), '"');
                $files[$filename] = (object) [
                    'filename' => $filename,
                    'additions' => 0,
                    'deletions' => 0,
                    'changes' => 0,
                    'previous_filename' => ''
                ];
            } else if (substr($line, 0, 5) == '+++ b' || substr($line, 0, 5) == '+++ /' || substr($line, 0, 6) == '+++ "b') {
                // new/current filename or file deleted
                $new_filename = trim(trim(substr($line, 6)), '"');

                if ($filename != $new_filename) {
                    if ($filename == 'ev/null') {
                        // getting a file CREATED
                        $files[$new_filename] = $files[$filename];
                        $files[$new_filename]->filename = $new_filename;
                        $files[$new_filename]->status = 'created';
                    } else if ($new_filename == 'ev/null') {
                        // getting a file REMOVED
                        $files[$filename]->status = 'removed';
                    } else {
                        // getting a file RENAMED
                        $files[$new_filename] = $files[$filename];
                        $files[$new_filename]->previous_filename = $filename;
                        $files[$new_filename]->filename = $new_filename;
                        $files[$new_filename]->status = 'renamed';
                    }
                } else {
                    // getting a file JUST MODIFIED
                    $files[$filename]->status = 'modified';
                }
            } else if (substr($line, 0, 1) == '-') {
                // removed line
                $deletions++;
                $files[$filename]->deletions++;
            } else if (substr($line, 0, 1) == '+') {
                // added line
                $additions++;
                $files[$filename]->additions++;
            } else
                // renamed withot changes in file
                if (substr($line, 0, 11) == 'rename from') {
                    // before process the next file, update an array for the current
                    if (isset($files[$filename])) $files[$filename]->patch = $patch;

                    if (isset($files[$new_filename])) $files[$new_filename]->patch = $patch;

                    $patch = '';

                    $filename = trim(trim(substr($line, 12)), '"');
                    $files[$filename] = (object) [
                        'filename' => $filename,
                        'additions' => 0,
                        'deletions' => 0,
                        'changes' => 0,
                        'previous_filename' => ''
                    ];
                } else if (substr($line, 0, 9) == 'rename to') {
                    $new_filename = trim(trim(substr($line, 10)), '"');
                    $files[$new_filename] = $files[$filename];
                    $files[$new_filename]->previous_filename = $filename;
                    $files[$new_filename]->filename = $new_filename;
                    $files[$new_filename]->status = 'renamed';
                }
        }

        if(isset($files['ev/null'])) unset($files['ev/null']);

        if ($files[$filename]) $files[$filename]->patch = $patch;

        if ($files[$new_filename]) $files[$new_filename]->patch = $patch;

        foreach ($files as $filename => $file) {
            $files[$filename]->changes = $files[$filename]->additions + $files[$filename]->deletions;

            if ($file->status == 'renamed' || $file->filename != $filename)
                unset($files[$file->previous_filename]);
        }

        return $files;
    }

    /**
     * Parses commits list into an array
     */
    public static function parseCommitsList($str, $number) {
        $commits = [];

        $str = explode("\n", $str);

        $hash = '';
        $message = '';
        $foundEmptyLine = false;
        $notPatch = true;

        foreach ($str as $line) {
            if (substr($line, 0, 6) == 'commit') {
                $hash = substr($line, 7);
                // stop getting and parsing commits, if they exist in DB
                if (db\Commit::hasCommitInDB($hash)) return false;
                $commits[$hash] = (object) [
                    'number' => ($number--),
                    'sha' => $hash,
                    'commit' => (object) [
                        'author' => (object) [
                            'name' => '',
                            'email' => '',
                            'date' => '',
                        ],
                        'committer' => (object) [
                            'name' => '',
                            'email' => '',
                            'date' => '',
                        ],
                        'message' => '',
                    ]
                ];

                $foundEmptyLine = false;
                $notPatch = true;
            } else if (substr($line, 0, 7) == 'Author:') {
                $commits[$hash]->commit->author->name = explode(' <', substr($line, 8))[0];
                $commits[$hash]->commit->author->email = explode('>', explode(' <', substr($line, 8))[1])[0];
            } else if (substr($line, 0, 11) == 'AuthorDate:') {
                $commits[$hash]->commit->author->date = trim(substr($line, 12));
            } else if (substr($line, 0, 7) == 'Commit:') {
                $commits[$hash]->commit->committer->name = explode(' <', substr($line, 8))[0];
                $commits[$hash]->commit->committer->email = explode('>', explode(' <', substr($line, 8))[1])[0];
            } else if (substr($line, 0, 11) == 'CommitDate:') {
                $commits[$hash]->commit->committer->date = trim(substr($line, 12));
            } else if ($line == '' && !$foundEmptyLine) {
                $foundEmptyLine = true;
                $message = '';
            } else if ($line == '' && $foundEmptyLine && $notPatch) {
                //$foundEmptyLine = false;
                $notPatch = false;
                $commits[$hash]->commit->message = trim($message);
            } else if ($line == '' && $foundEmptyLine && !$notPatch) {
                $foundEmptyLine = false;
                $notPatch = true;
                $commits[$hash]->files = self::parseCommitDiff(trim($message));

                /* finally we constructed array, write it to db */
                db\Commit::addCommitToDB($commits[$hash]);
            } else if ($foundEmptyLine) {
                $message .= $line . PHP_EOL;
            }
        }

        return $commits;
    }
}
