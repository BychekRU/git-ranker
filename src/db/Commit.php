<?php

namespace bychekru\git_ranker\db;

use bychekru\git_ranker\Git;

class Commit {
    private static
        $db,
        $table;

    public static function init($db, $table) {
        self::$db = $db;
        self::$table = $table;
    }

    public static function hasCommitInDB($hash) {
        $stmt = self::$db->prepare('SELECT * FROM ' . self::$table . ' WHERE hash = :hash');
        $stmt->bindParam(':hash', $hash);
        $result = $stmt->execute();

        while ($res = $result->fetchArray(SQLITE3_ASSOC)) {
            $res = (object) $res;
            return $res->hash == $hash;
        }

        return false;
    }

    public static function addCommitToDB($commit) {
        $author = $commit->commit->author->name . ' <' . $commit->commit->author->email . '>';
        $committer = $commit->commit->committer->name . ' <' . $commit->commit->committer->email . '>';
        $authorDate = date('Y-m-d H:i:s', strtotime($commit->commit->author->date));
        $commitDate = date('Y-m-d H:i:s', strtotime($commit->commit->committer->date));
        $files = json_encode($commit->files, JSON_UNESCAPED_UNICODE);

        $stmt = self::$db->prepare('INSERT INTO ' . self::$table . ' (id, hash, author_date, author, commit_date, committer, message, files) VALUES(:id, :hash, :author_date, :author, :commit_date, :committer, :message, :files)');
        $stmt->bindParam(':id', $commit->number);
        $stmt->bindParam(':hash', $commit->sha);
        $stmt->bindParam(':author_date', $authorDate);
        $stmt->bindParam(':author', $author);
        $stmt->bindParam(':commit_date', $commitDate);
        $stmt->bindParam(':committer', $committer);
        $stmt->bindParam(':message', $commit->commit->message);
        $stmt->bindParam(':files', $files);
        $result = $stmt->execute();

        return $result;
    }

    public static function getCommitCount() {
        return intval(self::$db->query('SELECT COUNT(id) as count FROM ' . self::$table)->fetchArray(SQLITE3_ASSOC)['count']);
    }

    public static function processLastCommitsHashes($totalCommitLength, $commitHashes) {
        if ($totalCommitLength - self::getCommitCount() < 0) {
            self::deleteCommitIDMoreThan($totalCommitLength);
        }

        $processRebasedCount = Git::getProcessRebasedCount();
        $stmt = self::$db->prepare('SELECT id, hash FROM ' . self::$table . ' ORDER BY id DESC LIMIT :limit');
        $stmt->bindParam(':limit', $processRebasedCount);
        $result = $stmt->execute();

        $needsDeletion = false;
        while ($res = $result->fetchArray(SQLITE3_ASSOC)) {
            $res = (object) $res;

            if ($res->hash != $commitHashes[$res->id])
                $needsDeletion = true;
            else {
                if ($needsDeletion)
                    self::deleteCommitIDMoreThan($res->id);
                break;
            }
        }

        return true;
    }

    public static function deleteCommitIDMoreThan($id) {
        $stmt = self::$db->prepare('DELETE FROM ' . self::$table . ' WHERE id > :id');
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        return true;
    }

    /**
     * Gets all repository commits
     * 
     * @param boolean $noFiles Don't attach file info
     * @param boolean $hash Select a specific commit by its hash
     */
    public static function queryAllCommits($noFiles = false, $hash = false) {
        $queryFields = '*';
        if ($noFiles) $queryFields = 'id, hash, author_date, author, commit_date, committer, message';
        if ($hash) {
            $stmt = self::$db->prepare('SELECT ' . $queryFields . ' FROM ' . self::$table . ' WHERE hash = :hash');
            $stmt->bindParam(':hash', $hash);
            $results = $stmt->execute();
        } else {
            $results = self::$db->query('SELECT ' . $queryFields . ' FROM ' . self::$table);
        }

        $commits = [];
        while ($res = $results->fetchArray(SQLITE3_ASSOC)) {
            $res = (object) $res;
            $commits[$res->hash] = (object) [
                'sha' => $res->hash,
                'commit' => (object) [
                    'author' => (object) [
                        'name' => $res->author,
                        'date' => $res->author_date,
                    ],
                    'committer' => (object) [
                        'name' => $res->committer,
                        'date' => $res->commit_date,
                    ],
                    'message' => $res->message,
                ],
            ];

            if (!$noFiles) $commits[$res->hash]->files = json_decode($res->files);
            if ($hash) return $commits[$res->hash];
        }

        return $commits;
    }

    public static function getCommit($hash, $noFiles = false) {
        return self::queryAllCommits($noFiles, $hash);
    }
}
