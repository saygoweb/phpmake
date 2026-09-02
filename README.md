# PHP Make

A (very) simple build runner inspired by make.

## TL;DR

Make.php has a json configuration file, the `makefile.json`, that describes targets that may have dependencies. Each target can optionall specify an output `provides` that can be checked, skipping the target if the output is already present. Each target provides
a `by` key which is the array of commands to execute to complete the task.

## Usage

```
Usage: make.php options target
 Options:
  help                displays this help
  file=makefile.json  load rules from the given makefile
  target=target       build the given target
  key=value           sets the variable 'key' to the given value

target can be either the last option or given by the target= option.
```

## Building and installing

`phpmake` can build and install itself using its own `makefile.json`.

```
# First run: bootstrap with the raw script
php make.php build       # packages make.php -> make.phar
php make.php install      # copies make.phar into ~/.local/bin

# After ~/.local/bin is on your PATH, use the phar directly
make.phar build
make.phar install
```

Targets in the bundled `makefile.json`:

| target      | effect                                                         |
|-------------|---------------------------------------------------------------|
| `phar`      | build `make.phar` (needs `php -d phar.readonly=0`, handled)   |
| `build`     | alias for `phar`                                             |
| `install`   | `build`, then copy `make.phar` to the install dir            |
| `uninstall` | remove `make.phar` from the install dir                      |
| `clean`     | delete the local `make.phar`                                 |

The install directory defaults to `$MAKE_BIN`, then `~/.local/bin`. Override it
by passing a path to the helper, e.g. `php install.php /usr/local/bin`.

## Example makefile.json

```json
{
    "variables": {
        "repo": "git@github.com:saygoweb/phpmake.git",
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

