# Config helper

Adds some useful commands for managing config:

```shell
config:rename                         Rename config.
config:add-module-dependencies        Enforce module dependencies in config.
config:move-module-config             Move config info config/install folder in a module.
```

**Note**: The `config:move-module-config` command is somewhat is similar to the
[`config:export:content:type`](https://drupalconsole.com/docs/en/commands/config-export-content-type) command the
[Drupal Console](https://drupalconsole.com/), but that command does not add the dependencies needed for our
requirements.

## Coding standards

Our coding are checked by GitHub Actions (cf.
[.github/workflows/pr.yml](.github/workflows/pr.yml)). Use the commands below to
run the checks locally.

### PHP

```sh
docker run --rm --volume ${PWD}:/app --workdir /app itkdev/php8.3-fpm composer install
# Fix (some) coding standards issues
docker run --rm --volume ${PWD}:/app --workdir /app itkdev/php8.3-fpm composer coding-standards-apply
# Check that code adheres to the coding standards
docker run --rm --volume ${PWD}:/app --workdir /app itkdev/php8.3-fpm composer coding-standards-check
```

### Markdown

```sh
docker run --rm --volume $PWD:/md peterdavehello/markdownlint markdownlint --ignore vendor --ignore LICENSE.md '**/*.md' --fix
docker run --rm --volume $PWD:/md peterdavehello/markdownlint markdownlint --ignore vendor --ignore LICENSE.md '**/*.md'
```

## Code analysis

We use [PHPStan](https://phpstan.org/) for static code analysis.

Running statis code analysis on a standalone Drupal module is a bit tricky, so we use a helper script to run the
analysis:

```sh
./scripts/code-analysis
```
