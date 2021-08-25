<?php

namespace bychekru\git_ranker\DB;

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
        $stmt = self::$db->prepare('INSERT INTO ' . self::$table . ' (id, hash, date, author, message, files) VALUES(:id, :hash, :date, :author, :message, :files)');
        $stmt->bindParam(':id', $commit->number);
        $stmt->bindParam(':hash', $commit->sha);
        $stmt->bindParam(':date', date('Y-m-d H:i:s', strtotime($commit->commit->author->date)));
        $stmt->bindParam(':author', $commit->commit->author->name);
        $stmt->bindParam(':message', $commit->commit->message);
        $stmt->bindParam(':files', json_encode($commit->files, JSON_UNESCAPED_UNICODE));
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

        $stmt = self::$db->prepare('SELECT id, hash FROM ' . self::$table . ' ORDER BY id DESC LIMIT :limit');
        $stmt->bindParam(':limit', Git::getProcessRebasedCount());
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

    public static function queryAllCommits() {
        $results = self::$db->query('SELECT * FROM ' . self::$table);
        $commits = [];
        while ($res = $results->fetchArray(SQLITE3_ASSOC)) {
            $res = (object) $res;
            $commits[$res->hash] = (object) [
                'sha' => $res->hash,
                'commit' => (object) [
                    'author' => (object) [
                        'name' => $res->author,
                        'date' => $res->date,
                    ],
                    'message' => $res->message,
                ],
                'files' => json_decode($res->files)
            ];
        }

        return $commits;
    }
}
