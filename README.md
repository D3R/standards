D3R Coding Standards
=========

Check these out into your /clients/custom folder.

`git clone https://github.com/D3R/standards /clients/custom/d3r-standards`

For PSR you will want to point PHP Code Sniffer at the XML file.

```
"phpcs_additional_args": {
	"--standard": "/clients/custom/d3r-standards/D3R-PHP.xml",
    "-n": ""
}
```
