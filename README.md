D3R Coding Standards
=========
## Installation
```composer require --dev d3r/standards```

## PHPCS
```
"phpcs_additional_args": {
	"--standard": "vendor/d3r/standards/D3R-PHP.xml"
}
```

or use the stricter standard
```"phpcs_additional_args": {
	"--standard": "vendor/d3r/standards/D3R-DEV-PHP.xml"
}
```

## PHPCI
```
php_code_sniffer:
	allowed_warnings: 0
	allowed_errors: 0
	standard: "vendor/d3r/standards/D3R-PHP.xml"
```

## Dev environment
For your editor I suggest checking this package out globally:

`composer global require d3r/standards`

Then point your editor's phpcs and phpcbf to `/Users/<username>/.composer/vendor/d3r/standards/D3R-PHP.xml`.

## Licence Checker
You will automatically get licence checking in CI if you are using the standard D3R GitHub Workflow.

If you need to add specific packages, vendors or licences to your local project, you can create a `.allowed-licenses.php` config file using the [base config](./.allowed-licenses.php) as a starting point.
