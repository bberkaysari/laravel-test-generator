# Contributing to Laravel Test Generator

Thank you for considering contributing to Laravel Test Generator! 🎉

## Development Setup

1. Fork the repository
2. Clone your fork:
   ```bash
   git clone https://github.com/YOUR_USERNAME/laravel-test-generator.git
   cd laravel-test-generator
   ```

3. Install dependencies:
   ```bash
   composer install
   ```

4. Create a new branch:
   ```bash
   git checkout -b feature/your-feature-name
   ```

## Running Tests

```bash
# Run all tests
composer test

# Run specific test
vendor/bin/phpunit tests/Unit/Scanner/ModelScannerTest.php

# Run with coverage (requires Xdebug)
composer test:coverage
```

## Code Quality

Before submitting a PR, ensure your code passes all checks:

```bash
# Static analysis
composer analyse

# Code formatting
composer format

# Check formatting without fixing
composer format:check
```

## Coding Standards

- Follow PSR-12 coding standards
- Write meaningful variable and method names
- Add PHPDoc comments for all public methods
- Keep methods small and focused
- Write tests for new features

## Commit Messages

Use clear and descriptive commit messages:

```
✅ Good:
- Add ModelScanner for Laravel models
- Fix ProjectScanner path resolution
- Update README with installation steps

❌ Bad:
- fixed bug
- updated stuff
- wip
```

## Pull Request Process

1. Update the README.md with details of changes if needed
2. Ensure all tests pass
3. Update documentation if you changed APIs
4. The PR will be merged once approved by maintainers

## Reporting Bugs

Use the [bug report template](.github/ISSUE_TEMPLATE/bug_report.md) when reporting bugs.

## Feature Requests

Use the [feature request template](.github/ISSUE_TEMPLATE/feature_request.md) for new features.

## Questions?

Feel free to open an issue with your question!

## Code of Conduct

- Be respectful and inclusive
- Provide constructive feedback
- Focus on the code, not the person
- Help others learn and grow

Thank you for contributing! 🚀
