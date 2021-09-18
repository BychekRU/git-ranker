<?php

namespace bychekru\git_ranker;

class LocalGit {
    public static function git($command, $git_path) {
        $cmd = 'cd ' . Git::getBashPath(Git::getRepoPath()) . ' && ';
        if (substr_count($command, 'commit ') > 0) {
            $config = db\Repo::getLocalConfig();
            // while working through PHP functions Windows doesn't see not only
            // environment variables and also git config data, put in manually
            $cmd .= 'git config --global user.email "' . $config->git_email . '" && git config --global user.name "' . $config->git_username . '" && ';
        }

        $cmd .= 'git ' . $command;
        if (substr_count($cmd, ' -') > 0) $cmd .= ' 2>&1';

        $descriptors = [
            '0' => ['pipe', 'r'],
            '1' => ['pipe', 'w'],
            '2' => ['pipe', 'w'],
        ];

        if (!file_exists($git_path . '\sh.exe')) {
            return Git::error('Can\'t find local Git Bash instance');
        }

        $process = proc_open('"' . $git_path . '\sh.exe"', $descriptors, $pipes);

        if (!is_resource($process)) {
            return Git::error('Can\'t run Git Bash with proc_open()');
        }

        fwrite($pipes[0], $cmd);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        proc_close($process);

        return $output;
    }

    public static function localUpdate() {
        $totalCommitLength = intval(Git::cmd('rev-list --count ' . Git::getRepoBranch()));
        if (!$totalCommitLength) {
            return Git::error('Can\'t receive correct response from local Git Bash');
        }

        if (Git::getProcessRebasedCount()) {
            self::processRebasedCommits($totalCommitLength);
        }

        $totalDBCommitLength = db\Commit::getCommitCount();

        $diff = $totalCommitLength - $totalDBCommitLength;
        if ($diff > 0) {
            $limit = ($diff < Git::getCommitsPerPage()) ? $diff : Git::getCommitsPerPage();
            $offset = 0;
            while ($offset <= $totalCommitLength) {
                $commitsString = Git::cmd('log -p --skip=' . $offset . ' -' . $limit . ' --format=fuller');
                if (!LocalGitParser::parseCommitsList($commitsString, $totalCommitLength - $offset)) break;

                $offset += $limit;
            }
        }

        return [
            'status' => 'ok',
            'elapsed_time' => Profiler::time(),
            'total_commits' => $totalCommitLength,
            'total_db_commits' => $totalDBCommitLength,
            'commits_updated' => $totalCommitLength - $totalDBCommitLength,
        ];
    }

    private static function processRebasedCommits($totalCommitLength) {
        $hashes = explode("\n", trim(Git::cmd('log --pretty=tformat:%H -' . Git::getProcessRebasedCount())));
        $commitHashes = [];
        foreach ($hashes as $hash) {
            $commitHashes[($totalCommitLength--)] = trim($hash);
        }

        db\Commit::processLastCommitsHashes($totalCommitLength + Git::getProcessRebasedCount(), $commitHashes);
    }

    public static function getFirstCommitHash() {
        $firstCommitHash = db\Repo::getFirstCommit();
        if ($firstCommitHash) return $firstCommitHash;

        $totalCommitLength = intval(Git::cmd('rev-list --count ' . Git::getRepoBranch()));
        if (!$totalCommitLength) {
            return Git::error('Can\'t receive correct response from local Git Bash');
        }

        $firstCommitHash = trim(Git::cmd('log --pretty=tformat:%H --skip=' . ($totalCommitLength - 1) . ' -1'));

        return $firstCommitHash;
    }

    public static function getUserConfig() {
        // gets git config path
        $gitConfigPath = 'C:\Users\\' . get_current_user() . '\.gitconfig';
        if (file_exists($gitConfigPath)) {
            // and parse it
            $config = self::simpleINIParser($gitConfigPath);
            return (object) [
                'git_username' => $config->user->name,
                'git_email' => $config->user->email,
            ];
        } else {
            Git::error('Can\'t find local git config file :(');
        }
    }

    // PHP parse_ini_file() function can fail to parse .gitconfig
    public static function simpleINIParser($path) {
        $file = file($path);

        $result = (object) [];
        $section = '';
        foreach ($file as $line) {
            $line = trim($line);
            if (!strlen($line)) continue;
            if (substr($line, 0, 1) == '[') {
                $section = explode(']', explode('[', $line)[1])[0];
                $result->$section = (object) [];
            } else {
                list($key, $value) = explode('=', $line);
                $key = trim($key);
                $result->$section->$key = trim($value);
            }
        }

        return $result;
    }
}
