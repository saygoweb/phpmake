# PHP Make

A (very) simple build runner inspired by make.

## TL;DR

Make.php has a json configuration file, the `makefile.json`, that describes targets that may have dependencies. Each target can optionall specify an output `provides` that can be checked, skipping the target if the output is already present. Each target provides
a `by` key which is the array of commands to execute to complete the task.

## Usage

```
Usage: make.php [options] [target]
 Options:
  -h, --help          displays this help
  -f, --file FILE     load rules from the given makefile (default makefile.json)
  -t, --target NAME   build the given target
  key=value           sets the variable 'key' to the given value

The target can be either the first plain argument or given by --target.
Options may also be written without dashes, e.g. 'file=build.json'.
```

The options may be written with or without dashes, so `make.phar -f build.json
install` and `make.phar file=build.json install` are the same command. Running
with no target, or with `-h`/`--help`, prints the usage above.

make exits `0` when the target completes, and `1` when the target is unknown,
the makefile is missing or unreadable, an option is not recognised, or a
command returns a non-zero code.

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
| `test`      | run the test suite                                           |
| `clean`     | delete the local `make.phar`                                 |

The install directory defaults to `$MAKE_BIN`, then `~/.local/bin`. Override it
by passing a path to the helper, e.g. `php install.php /usr/local/bin`.

## Tests

```
php make.php test     # or: php tests/run.php
```

The suite covers the command line argument parsing directly, then runs
`make.php` and a freshly built `make.phar` as subprocesses to check the
messages and exit codes of both.

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

