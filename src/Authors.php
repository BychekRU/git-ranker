<?php

namespace bychekru\git_ranker;

class Authors {
    private static
        $config,
        $authorLinking = [],
        $ignoredAuthors = [];

    /**
     * Gets authors list for given repository
     * 
     * @param boolean $withDetails Separate by author/committer/co-author or not
     * @param boolean $onlyNames Show only names or names with commit count
     */
    public static function getList($withDetails = false, $onlyNames = true) {
        return self::getListWithCommitCount($withDetails, $onlyNames);
    }

    /**
     * Gets authors list for given repository grouped by type (author/committer/co-author)
     * 
     * @param boolean $withDetails Separate by author/committer/co-author or not
     * @param boolean $onlyNames Show only names or names with commit count
     */
    public static function getListByType($withDetails = true, $onlyNames = true) {
        return self::getListWithCommitCount($withDetails, $onlyNames);
    }

    /**
     * Gets commits amount by all authors
     * 
     * @param boolean $withDetails Separate by author/committer/co-author or not
     * @param boolean $onlyNames Show only names or names with commit count
     */
    public static function getListWithCommitCount($withDetails = false, $onlyNames = false, $commit = false) {
        if ($commit) {
            $allCommits = [];
            $allCommits[$commit] = db\Commit::getCommit($commit, true);
        } else {
            $allCommits = db\Commit::queryAllCommits(true);
        }
        $authors = [];
        $committers = [];
        $coAuthors = [];

        foreach ($allCommits as $commit) {
            $author = self::processAuthor($commit->commit->author->name);
            $committer = self::processAuthor($commit->commit->committer->name);
            $authors[] = $author;
            if ($author != $committer) $committers[] = $committer;
            if (substr_count(mb_strtolower($commit->commit->message), 'co-authored-by')) {
                preg_match_all('/\sCo-Authored-By: (.*)/i', $commit->commit->message, $matches);
                foreach ($matches[1] as $coAuthor) {
                    $coAuthor = self::processAuthor($coAuthor);
                    if ($author != $coAuthor && $committer != $coAuthor) $coAuthors[] = $coAuthor;
                }
            }
        }

        if (!$withDetails) return self::processType(array_merge($authors, $committers, $coAuthors), $onlyNames);

        else return [
            'authors' => self::processType($authors, $onlyNames),
            'committers' => self::processType($committers, $onlyNames),
            'co_authors' => self::processType($coAuthors, $onlyNames),
        ];

        return;
    }

    /**
     * Gets commits amount by all authors grouped by type (author/committer/co-author)
     * 
     * @param boolean $withDetails Separate by author/committer/co-author or not
     * @param boolean $onlyNames Show only names or names with commit count
     */
    public static function getListByTypeWithCommitCount($withDetails = true, $onlyNames = false) {
        return self::getListWithCommitCount($withDetails, $onlyNames);
    }

    /**
     * Gets authors' list in a commit
     * 
     * @param boolean $commit False by default, processes all commits in repository if not specified
     * @param boolean $withDetails Separate by author/committer/co-author or not
     * @param boolean $onlyNames Show only names or names with commit count
     */
    public static function inCommit($commit = false, $withDetails = false, $onlyNames = true) {
        return self::getListWithCommitCount($withDetails, $onlyNames, $commit);
    }

    public static function getTracked(){
        return array_map(function($author){
            return self::processAuthor($author);
        }, array_keys(self::$authorLinking));
    }

    private static function processType($authors, $onlyNames) {
        $authors = array_count_values($authors);
        foreach ($authors as $author => $count) {
            $basicAuthor = self::findLinking($author);
            if ($basicAuthor) {
                unset($authors[$author]);
                if ($basicAuthor !== true) {
                    if (!isset($authors[$basicAuthor])) $authors[$basicAuthor] = 0;
                    $authors[$basicAuthor] += $count;
                }
            } else if (self::$config->ignore_not_included) {
                unset($authors[$author]);
            }
        }
        return $onlyNames ? array_keys($authors) : $authors;
    }

    /**
     * Smart function for processing `ignored` and `authors` input arrays
     */
    private static function findLinking($author) {
        foreach (self::$ignoredAuthors as $link) {
            $link = self::processAuthor($link);
            if (substr($link, -3) == '<*>') {
                $link = self::processAuthor($link, true);
                if (self::processAuthor($author, true) == $link) return true;
            } else {
                if ($author == $link) return true;
            }
        }

        foreach (self::$authorLinking as $key => $link) {
            $key = self::processAuthor($key);
            if (gettype($link) == 'array') {
                foreach ($link as $item) {
                    if (substr(trim($item), -3) == '<*>') {
                        $item = self::processAuthor($item, true);
                        if (self::processAuthor($author, true) == $item) {
                            return self::processAuthor($key, true);
                        }
                    } else {
                        $item = self::processAuthor($item);
                        if ($author == $item) return $key;
                    }
                }
            } else {
                if (substr(trim($link), -3) == '<*>') {
                    $link = self::processAuthor($link, true);
                    if (self::processAuthor($author, true) == $link)
                        return self::processAuthor($key, true);
                } else {
                    $link = self::processAuthor($link);
                    if ($author == $link) return $key;
                }
            }
        }

        return false;
    }

    private static function processAuthor($author, $force = false) {
        if (self::$config->ignore_emails || $force) $author = explode(' <', $author)[0];
        return trim($author);
    }

    /**
     * Sets configuration
     */
    public static function setConfig($config) {
        self::$config = (object) $config;
        if ($config['ignored']) self::$ignoredAuthors = $config['ignored'];
        if ($config['authors']) self::$authorLinking = $config['authors'];
    }
}
