<?php

class Makefile
{
    public $commands = [];

    public $actions = [];

    public $doLog = true;
    public $doScreen = true;
    
    public function __construct()
    {
        $repo = "git@github.com:mseag/web-cambodia.git";
        $code = "../code";

        $this->actions = [
            "push" => [
                "#run:install"
            ]
        ];

        $this->commands = [
            "clone" => [
                "provides" => [
                    "#folder:$code"
                ],
                "by" => [
                    "git clone $repo $code"
                ]
            ],
            "pull" => [
                "depends" => [
                    "clone"
                ],
                "by" => [
                    "cd $code && git pull origin master"
                ]
            ],
            "build" => [
                "depends" => [
                    "pull"
                ],
                "by" => [
                    "cd $code && npm install",
                    "cd $code && npm run generate"
                ]
            ],
            "rmapp" => [
                "by" => [
                    "rm -rf app"
                ]
            ],
            "install" => [
                "depends" => [
                    "build",
                    "rm_app"
                ],
                "by" => [
                    "cp -a $code/dist app"
                ]
            ],
        ];
    }
}