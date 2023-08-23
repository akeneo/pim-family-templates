# PIM Families Templates

## How to generate template ?

In order to generate template, you need to get the XLSX file enriched by Fef.
Then, you have to use the Makefile like that:
```shell
SOURCE_FILE=felix.xlsx make templates
```

## How to minify template ?

You can use the Makefile to do it:
```shell
make dist
```
