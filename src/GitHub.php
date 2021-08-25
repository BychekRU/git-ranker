<?php

namespace bychekru\git_ranker;

class GitHub {
    private static
        $username = '',
        $userToken = '',
        $appID = '',
        $appSecret = '',
        $headers = [];

    /**
     * Makes a curl request to GitHub
     * 
     * @param string $url URL
     * @param string $mode `user` (by default), `app`, `custom`
     * @param string[] $post Array of post params
     * @param string $customAccept Customizing `Accept` header lets to download diff, patch, etc.
     */
    public static function load($url, $mode = 'user', $post = [], $customAccept = 'application/vnd.github.v3+json') {
        $token = $mode == 'custom' ? $post : ($mode == 'app' ? self::$appSecret : self::$userToken);

        $ch = curl_init($url);

        $headers = [
            'Accept: ' . $customAccept,
            'User-Agent: ' . self::$username,
        ];

        if ($mode != 'app') {
            $headers[] = 'Authorization: token ' . $token;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($mode == 'file') curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if (gettype($post) == 'array' && count($post) > 0) {
            if ($mode == 'app') {
                $post['client_id'] = self::$appID;
                $post['client_secret'] = self::$appSecret;
            }

            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, 'self::processHeaders');
        self::$headers = [];


        $content = curl_exec($ch);
        curl_close($ch);

        if ($mode == 'file') return $content;
        else {
            $content = json_decode($content);
            self::checkGitHubApiError($content);
            return $content;
        }
    }

    public static function checkGitHubApiError($answer) {
        if (!$answer)
            Git::error('Please make sure you state your GitHub username');

        else if (is_object($answer) && $answer->message) {
            if ($answer->message == 'Bad credentials')
                Git::error('You use incorrect token');

            if ($answer->message == 'Not Found')
                Git::error('Can\'t find the requested repository');
        }
    }

    public static function saveCredentials($data) {
        if (isset($data['username'])) self::$username = $data['username'];
        if (isset($data['token'])) self::$userToken = $data['token'];
        if (isset($data['app_id'])) self::$appID = $data['app_id'];
        if (isset($data['app_secret'])) self::$appSecret = $data['app_secret'];
    }

    private static function processHeaders($ch, $line) {
        list($key, $value) = explode(':', $line, 2);
        self::$headers[trim($key)] = trim($value);
        return strlen($line);
    }

    public static function getLastResponseHeaders() {
        return self::$headers;
    }

    public static function getFirstCommitHash() {
        $firstCommitHash = db\Repo::getFirstCommit();
        if ($firstCommitHash) return $firstCommitHash;

        GitHub::load(Git::getRepoPath() . 'commits?sha=' . Git::getRepoBranch() . '&page=1&per_page=' . Git::getCommitsPerPage());

        $lastPage = intval(explode('page=', GitHub::getLastResponseHeaders()['link'])[3]);

        $lastCommitsPage = GitHub::load(Git::getRepoPath() . 'commits?sha=' . Git::getRepoBranch() . '&page=' . $lastPage . '&per_page=' . Git::getCommitsPerPage());
        $firstCommitHash = $lastCommitsPage[count($lastCommitsPage) - 1]->sha;

        return $firstCommitHash;
    }

    public static function getCommitCount() {
        $commitsLength = GitHub::load(Git::getRepoPath() . 'compare/' . self::getFirstCommitHash() . '...' . Git::getRepoBranch());
        // max total_commits is 10000, using ahead_by property
        return intval($commitsLength->ahead_by + 1);
    }

    private static function download($params, $filename) {
        Profiler::start();
        $content = self::load(...$params);
        if ($filename) {
            $noSlashes = strpos($filename, '/') === false && strpos($filename, '\\') === false;
            file_put_contents($noSlashes ? Git::getAppDir() . $filename : $filename, $content);
            return true;
        } else {
            return $content;
        }
    }

    public static function downloadDiff($hash, $filename = false) {
        return self::download([Git::getRepoPath() . 'commits/' . $hash, 'file', false, 'application/vnd.github.v3.diff'], $filename);
    }

    public static function downloadPatch($hash, $filename = false) {
        return self::download([Git::getRepoPath() . 'commits/' . $hash, 'file', false, 'application/vnd.github.v3.patch'], $filename);
    }

    public static function downloadZip($filename = false) {
        return self::download([Git::getRepoPath() . 'zipball/' . Git::getRepoBranch(), 'file'], $filename);
    }

    public static function downloadTar($filename = false) {
        return self::download([Git::getRepoPath() . 'tarball/' . Git::getRepoBranch(), 'file'], $filename);
    }
}
