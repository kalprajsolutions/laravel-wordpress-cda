# Contributing

Thanks for considering a contribution to `laravel-wordpress-cda`!

## Setup

Requirements: PHP 8.1+ and Composer.

```bash
git clone https://github.com/kalprajsolutions/laravel-wordpress-cda.git
cd laravel-wordpress-cda
composer install
```

## Checks

Run these before opening a PR (the CI workflow runs the same ones):

```bash
composer validate
find src -name '*.php' -print0 | xargs -0 -n1 php -l
```

There is no test suite yet. If you add behavior, a small PHPUnit test for it is very welcome.

## Opening a PR

1. Fork the repo and create a branch from `main`.
2. Keep the PR focused: one fix or one feature.
3. Describe what changed and why.

## Reporting issues

Use the issue templates so reports arrive with the details needed. Questions are welcome in Discussions.
