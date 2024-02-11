# typo3.testing-tools
Testing extensions for ScoutNet Typo3 Plugins

# usage of tools
db converter tool:

```foreach file (*.xml); do filename="$(basename $file .xml)"; php ~/Develop/scoutnet/typo3.testing-tools/Tools/convert.php "$filename.xml" > "$filename.csv"; done```
