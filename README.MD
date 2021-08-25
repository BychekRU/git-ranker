# Support

Currently supports only:
* Git Bash on Windows
* GitHub API

Rating calculation will be soon implemented.

# Example
```php
<?php
require '../vendor/autoload.php';

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
```
