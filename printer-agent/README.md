# POS Printer Agent

Standalone Windows app that connects to your ERP with the Base URL, loads branches into a dropdown, syncs local printers, and polls kitchen/bar print jobs every 2 seconds.

> **After `git pull`:** see full setup steps in [SETUP.md](SETUP.md).

## Client setup

1. Run `PosPrinterAgent.exe`.
2. Enter the ERP Base URL (e.g. `http://erp-saas.test` or `https://your-company.example.com`).
3. Click **Connect** — branches load into the dropdown.
4. Pick the correct **Branch** from the dropdown; the branch picked on the PC is remembered.
5. In ERP **Printer Settings**, confirm the agent is **Online**, then choose Kitchen / Bar / Default printers and Save.

Saved settings are restored on next launch and the agent auto-connects.

## ERP logo

The agent automatically downloads the ERP POS slip logo when it connects and whenever the ERP logo changes. It saves the logo on the PC at:

```text
%LocalAppData%\PosPrinterAgent\slip_logo.png
```

No manual logo upload is needed. If the ERP logo is removed, the saved local copy is cleared too.

## Build a single self-contained EXE

```bat
dotnet publish -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -o publish
```

Output: `publish\PosPrinterAgent.exe`

## API used (no auth)

- `GET  /api/printer-agent/branches`
- `POST /api/printer-agent/heartbeat`
- `GET  /api/printer-agent/logo?branch_id=`
- `POST /api/printer-agent/printers`
- `GET  /api/printer-agent/jobs?branch_id=`
- `POST /api/printer-agent/jobs/{id}/ack`
