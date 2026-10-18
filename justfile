# List available commands
default:
    @just --list

# Run test suite via Pest
test *args:
    composer test -- {{ args }}

# Validate composer.json strictly
validate:
    composer validate --strict

# Run all checks (validate + test)
check: validate test

# Synchronize Composer dependencies and lockfile
update:
    composer update
