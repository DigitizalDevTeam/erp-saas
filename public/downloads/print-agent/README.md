# Print Agent downloads

Host the Windows Print Agent package here as:

`PosPrinterAgent.zip`

Download URL (from the web app):

`/downloads/print-agent/PosPrinterAgent.zip`

Rebuild and refresh after publishing:

```
dotnet publish printer-agent/PosPrinterAgent.csproj -c Release -o printer-agent/publish-v4
copy printer-agent\publish-v4\PosPrinterAgent.exe public\downloads\print-agent\PosPrinterAgent.exe
Compress-Archive -Path public\downloads\print-agent\PosPrinterAgent.exe -DestinationPath public\downloads\print-agent\PosPrinterAgent.zip -Force
```
