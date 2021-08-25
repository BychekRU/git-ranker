<?php

require '../src/Git.php';

use bychekru\git_ranker\Git;
use bychekru\git_ranker\GitHub;
use bychekru\git_ranker\LocalGit;
use bychekru\git_ranker\Webhook;
use bychekru\git_ranker\DB;

Git::init([
    'app_dir' => __DIR__, // local app dir
    'repo_local_path' => __DIR__, // local repository path
    'repo_remote_path' => 'BychekRU/git_ranker', // username/repository
    'repo_branch' => 'main', // repo branch
    'git_path' => 'C:\Program Files\Git\bin', // path to local Git Bash instanse
    'mode' => 'remote', // local or remote
    'commits_per_page' => 15, // commits on page
    'process_rebased_count' => 100, // check integrity of last 100 commits
    'error_handler' => function($error){ // function will called when error will occur
        var_dump($error);
        exit();
    },
    'github' => [
        'username' => 'login', // github username
        'token' => 'ghp_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', // github token
        // if you use GitHub class for authentification, you can state
        'app_id' => '', // github app id
        'app_secret' => '', // github app secret
    ],
]);

//var_dump(Git::cmd('log --oneline -20'));

var_dump(Git::triggerUpdate());
//DB::getFirstCommit();
//LocalGit::getFirstCommitHash();

//GitHub::downloadDiff('XXXXXXXXXXXXXX', 'XXXXXXXXXXXXXX.diff');
//GitHub::downloadPatch('XXXXXXXXXXXXXX', 'XXXXXXXXXXXXXX.patch');
//GitHub::getFirstCommitHash();
/*Webhook::init([
    //'app_dir' => __DIR__,
    'repo_path' => 'BychekRU/git_ranker',
    //'allowed_extensions' => ['.php', '.json'],
    'not_updated_files' => [
        '/example.php',
    ],
]);
Webhook::run();*/

//var_dump(Git::getCommit('XXXXXXXXXXXXXX')->commit);

var_dump(Git::getLastCommandDuration());
