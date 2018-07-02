D3R Coding Standards
=========

### For using in a composer project

```composer require d3r/standards```

For phpcs:
```
"phpcs_additional_args": {
	"--standard": "vendor/d3r/D3R-PHP.xml"
}
```

### If using globally for your editor
Check these out into your /clients/custom folder.

`git clone https://github.com/D3R/standards /clients/custom/d3r-standards`

For PSR you will want to point PHP Code Sniffer at the XML file.

```
"phpcs_additional_args": {
	"--standard": "/clients/custom/d3r-standards/D3R-PHP.xml"
}
```
