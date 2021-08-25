<?php

namespace bychekru\git_ranker\DB;

use bychekru\git_ranker\Git;

use SQLite3;

class Repo {
    private static
        $db,
        $table;
    public static function init() {
        if (!self::$db) {
            $DBFilename = Git::getAppDir() . 'git-ranker.db';
            self::$db = new SQLite3($DBFilename);

            if (!self::isTableExists('repos')) {
                self::$db->query('CREATE table repos (id INTEGER PRIMARY KEY, source TEXT, table_name TEXT, first_commit TEXT)');
            }
        }

        if (Git::getMode() == 'local' && !self::isTableExists('local_user')) {
            $config = \bychekru\git_ranker\LocalGit::getUserConfig();
            if ($config) {
                self::$db->query('CREATE table local_user (id INTEGER PRIMARY KEY, parameter TEXT, value TEXT)');
                $stmt = self::$db->prepare('INSERT INTO local_user (parameter, value)
                    VALUES (\'git_username\', :git_username),
                           (\'git_email\', :git_email)');
                $stmt->bindParam(':git_username', $config->git_username);
                $stmt->bindParam(':git_email', $config->git_email);
                $result = $stmt->execute();
            }
        }

        self::initRepo();
    }

    public static function getLocalConfig() {
        $result = self::$db->query('SELECT * FROM local_user');

        $config = [];
        while ($res = $result->fetchArray(SQLITE3_ASSOC)) {
            $res = (object) $res;
            $config[$res->parameter] = $res->value;
        }

        return (object) $config;
    }

    private static function initRepo() {
        if (self::getFirstCommit()) {
            self::$table = self::getTableName();
            Commit::init(self::$db, self::$table);
            return true;
        }

        $firstCommit = Git::getFirstCommitHash();
        $table = self::generateTableName(Git::getRepoPath(), $firstCommit);
        self::$table = $table;

        $stmt = self::$db->prepare('INSERT INTO
            repos (source, table_name, first_commit)
            VALUES (:source, :table_name, :first_commit)');
        $stmt->bindParam(':source', Git::getRepoPath());
        $stmt->bindParam(':table_name', $table);
        $stmt->bindParam(':first_commit', $firstCommit);
        $result = $stmt->execute();

        if (!self::isTableExists($table)) {
            self::$db->query('CREATE table ' . $table . ' (id INTEGER PRIMARY KEY, hash TEXT, date DATE, author TEXT, message TEXT, files TEXT)');
        }

        return $result;
    }

    private static function getTableName() {
        $stmt = self::$db->prepare('SELECT table_name FROM repos WHERE source = :source');
        $stmt->bindParam(':source', Git::getRepoPath());

        return $stmt->execute()->fetchArray(SQLITE3_ASSOC)['table_name'];
    }

    private static function generateTableName($name, $firstCommit) {
        $arr = explode("/", trim($name, "/"));
        return preg_replace('/[^-_a-z\d]/ui', '', $arr[count($arr) - 1]) . '_' . $firstCommit;
    }

    public static function isTableExists($table) {
        $stmt = self::$db->prepare('SELECT name FROM sqlite_master WHERE name = :table');
        $stmt->bindParam(':table', $table);

        return $stmt->execute()->fetchArray(SQLITE3_ASSOC)['name'] == $table;
    }

    public static function getFirstCommit() {
        $stmt = self::$db->prepare('SELECT first_commit FROM repos WHERE source = :source');
        $stmt->bindParam(':source', Git::getRepoPath());

        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$result) return false;

        return $result['first_commit'];
    }
}
