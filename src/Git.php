<?php

namespace bychekru\git_ranker;

// require files if autoload not enabled
if (!class_exists('bychekru\git_ranker\Webhook')) {
    require 'LocalGit.php';
    require 'LocalGitParser.php';
    require 'RemoteGit.php';
    require 'GitHub.php';
    require 'Profiler.php';
    require 'Webhook.php';
    require 'db/Repo.php';
    require 'db/Commit.php';
}

class Git {
    private static
        $repoLocalPath = '',
        $repoRemotePath = '',
        $repoBranch = '',
        $commitsPerPage = 15,
        $processRebasedCount = false,
        $gitPath = '',
        $mode = 'remote',
        $appDir = '',
        $tempDir = '',
        $errorHandler;

    public static function init($config) {
        if (isset($config['mode'])) self::$mode = $config['mode'];
        if (isset($config['error_handler']))
            self::$errorHandler = $config['error_handler'];
        else
            self::$errorHandler = function ($error) {
                var_dump($error);
                exit();
            };
        // we use Git Bash as it has less limitations, and works in 1.5 times faster
        if (isset($config['repo_local_path']))
            self::$repoLocalPath = self::getBashPath($config['repo_local_path']);
        if (isset($config['repo_remote_path']))
            self::$repoRemotePath = self::getGitHubPath($config['repo_remote_path']);
        if (isset($config['repo_path'])) {
            if (self::$mode == 'local')
                self::$repoLocalPath = self::getBashPath($config['repo_path']);
            else
                self::$repoRemotePath = self::getGitHubPath($config['repo_path']);
        }
        if (isset($config['git_path'])) self::$gitPath = $config['git_path'];
        if (isset($config['repo_branch'])) self::$repoBranch = $config['repo_branch'];
        if (isset($config['branch'])) self::$repoBranch = $config['branch'];
        if (isset($config['commits_per_page'])) self::$commitsPerPage = intval($config['commits_per_page']);
        if (isset($config['process_rebased_count'])) self::$processRebasedCount = intval($config['process_rebased_count']);

        if (isset($config['app_dir']))
            self::$appDir = self::processAppDir($config['app_dir']);
        else
            self::$appDir = __DIR__ . '/..';

        if (isset($config['temp_dir']))
            self::$tempDir = self::processAppDir($config['temp_dir']);
        else
            self::$tempDir = self::getAppDir() . 'temp';

        if (!file_exists(self::getTempDir())) mkdir(self::getTempDir(), 0777);

        if (isset($config['github'])) {
            GitHub::saveCredentials($config['github']);
        }

        db\Repo::init();
    }

    /**
     * Gets path adapted for Git Bash
     * 
     * @param string $path Path
     */
    public static function getBashPath($path) {
        $path = explode('/', '/' . str_replace(['\\', ':'], ['/', ''], $path));
        $path[1] = strtolower($path[1]);
        return join('/', $path);
    }

    /**
     * Gets path to repo though GitHub API
     */
    public static function getGitHubPath($path) {
        return 'https://api.github.com/repos/' . $path . '/';
    }

    private static function processAppDir($path) {
        return trim($path, '/\\');
    }

    /**
     * Runs git command
     * 
     * @param string $cmd Command to local git, without `git` keyword
     */
    public static function cmd($cmd) {
        return LocalGit::git($cmd, self::$gitPath);
    }

    /**
     * Returns object with commit data
     */
    public static function getCommit($hash) {
        Profiler::start();
        if (self::$mode == 'local') {
            return LocalGitParser::parseCommitsList(self::cmd('show ' . $hash), 1)[$hash];
        } else {
            return GitHub::load(self::getRepoPath() . 'commits/' . $hash);
        }
    }

    public static function getLastCommandDuration() {
        return Profiler::time();
    }

    /**
     * Runs commits DB update through local Git
     */
    public static function triggerLocalUpdate() {
        Profiler::start();
        $mode = self::getMode();
        self::$mode = 'local';
        db\Repo::init();
        $result = LocalGit::localUpdate();
        self::$mode = $mode;
        return $result;
    }

    /**
     * Runs commits DB update through remote Git
     */
    public static function triggerRemoteUpdate() {
        Profiler::start();
        $mode = self::getMode();
        self::$mode = 'remote';
        db\Repo::init();
        $result = RemoteGit::remoteUpdate();
        self::$mode = $mode;
        return $result;
    }

    /**
     * Runs commits DB update though default source
     */
    public static function triggerUpdate() {
        return (self::$mode == 'local') ? self::triggerLocalUpdate() : self::triggerRemoteUpdate();
    }

    public static function getMode() {
        return self::$mode;
    }

    public static function setMode($mode) {
        self::$mode = $mode;
    }

    public static function getRepoPath() {
        return (self::$mode == 'local') ? self::$repoLocalPath : self::$repoRemotePath;
    }

    public static function getRepoBranch() {
        return self::$repoBranch;
    }

    public static function getCommitsPerPage() {
        return self::$commitsPerPage;
    }

    public static function getProcessRebasedCount() {
        return self::$processRebasedCount;
    }

    public static function getAppDir() {
        return self::$appDir . '\\';
    }

    public static function getTempDir() {
        return self::$tempDir . '\\';
    }

    public static function error($error) {
        (self::$errorHandler)($error);
    }

    /**
     * Returns an array of all commits in the repository
     */
    public static function getAllCommits() {
        return db\Commit::queryAllCommits();
    }

    public static function getFirstCommitHash() {
        return (self::$mode == 'local') ? LocalGit::getFirstCommitHash() : GitHub::getFirstCommitHash();
    }
}
