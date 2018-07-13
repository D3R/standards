D3R Coding Standards
=========
### Include in composer

```composer require d3r/standards```

### PHPCS
```
"phpcs_additional_args": {
	"--standard": "vendor/d3r/D3R-PHP.xml"
}
```

or use the stricter standard
```"phpcs_additional_args": {
	"--standard": "vendor/d3r/standards/D3R-DEV-PHP.xml"
}
```

### PHPCI

```
php_code_sniffer:
	allowed_warnings: 0
	allowed_errors: 0
	standard: "vendor/d3r/standards/D3R-PHP.yml"
```

### Dev environment
For your editor then we have a more strict standard so we can stage changes without breaking all builds
Check these out into your /clients/custom folder.

`git clone https://github.com/D3R/standards /clients/custom/standards`

then point your editor's phpci to one of the files either `/clients/custom/standards/D3R-DEV-PHP.xml` or `/clients/custom/standards/D3R-PHP.xml`
