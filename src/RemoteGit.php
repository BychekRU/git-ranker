<?php

namespace bychekru\git_ranker;

class RemoteGit {
    private static function parseRemoteCommits($commits, $number) {
        foreach ($commits as $commit) {
            // stop query and parse commits if they exist in DB
            if (db\Commit::hasCommitInDB($commit->sha)) return false;
            $files = GitHub::load($commit->url)->files;

            $commit->files = [];

            foreach ($files as $file)
                $commit->files[$file->filename] = (object) [
                    'filename' => $file->filename,
                    'additions' => $file->additions,
                    'deletions' => $file->deletions,
                    'changes' => $file->changes,
                    'previous_filename' => property_exists($file, 'previous_filename') ? $file->previous_filename : '',
                    'status' => $file->status,
                    'patch' => $file->patch,
                ];

            $commit->number = ($number--);

            db\Commit::addCommitToDB($commit);
        }

        return true;
    }

    public static function remoteUpdate() {
        $totalCommitLength = GitHub::getCommitCount();
        if (Git::getProcessRebasedCount()) {
            self::processRebasedCommits($totalCommitLength);
        }

        $totalDBCommitLength = db\Commit::getCommitCount();

        if ($totalCommitLength > $totalDBCommitLength) {
            $limit = Git::getCommitsPerPage();
            $offset = 0;
            while ($offset <= $totalCommitLength) {
                $commitsArray = GitHub::load(Git::getRepoPath() . 'commits?sha=' . Git::getRepoBranch() . '&page=' . (($offset / $limit) + 1) . '&per_page=' . $limit);
                if (!self::parseRemoteCommits($commitsArray, $totalCommitLength - $offset)) break;

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
        $hashes = GitHub::load(Git::getRepoPath() . 'commits?sha=' . Git::getRepoBranch() . '&page=1&per_page=' . Git::getProcessRebasedCount());
        $commitHashes = [];
        foreach ($hashes as $commit) {
            $commitHashes[($totalCommitLength--)] = $commit->sha;
        }

        db\Commit::processLastCommitsHashes($totalCommitLength + Git::getProcessRebasedCount(), $commitHashes);
    }
}
