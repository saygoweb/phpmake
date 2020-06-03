# PHP Make

A (very) simple build runner inspired by make.

It has a json configuration file, the `makefile.json`, that describes targets that have optional dependencies.
Each target can provide an output that can be checked, skipping the target if necessary. Each target provides
a `by` key which is the array of commands to execute to complete the task.

## Example makefile.json

```json
{
    "variables": {
        "repo": "git@github.com:saygoweb/php-make.git",
        "codePath": "../code"
    },
    "clone": {
        "provides": [
            "#folder:{{codePath}}"
        ],
        "by": [
            "git clone {{repo}} {{codePath}}"
        ]
    },
    "pull": {
        "depends": [
            "clone"
        ],
        "by": [
            "cd {{codePath}} && git pull origin master"
        ]
    },
    "build": {
        "depends": [
            "pull"
        ],
        "by": [
            "cd {{codePath}} && npm install",
            "cd {{codePath}} && npm run generate"
        ]
    },
    "rm_app": {
        "by": [
            "rm -r app"
        ]
    },
    "install": {
        "depends": [
            "build",
            "rm_app"
        ],
        "by": [
            "cp -a {{codePath}}/dist app"
        ]
    }
}
```