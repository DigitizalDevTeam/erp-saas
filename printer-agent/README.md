# POS Printer Agent

Standalone Windows app that connects to your ERP with **only the Base URL** (no login / API token), loads branches into a dropdown, syncs local printers, and polls kitchen/bar print jobs every 2 seconds.

> **After `git pull`:** see full setup steps in [SETUP.md](SETUP.md).

## Client setup

1. Run `PosPrinterAgent.exe`.
2. Enter the ERP Base URL (e.g. `http://erp-saas.test` or `https://your-company.example.com`).
3. Click **Connect** — the agent loads branches and starts automatically.
4. Pick the correct **Branch** from the dropdown if needed (changing branch restarts the agent).
5. In POS Restaurant → **Print** (Printer Settings), confirm **Printer Agent Status** is **Online**, then choose Kitchen / Bar printers and Save.

Saved settings are restored on next launch and the agent auto-connects.

## Build a single self-contained EXE

```bat
dotnet publish -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -o publish
```

Output: `publish\PosPrinterAgent.exe`

## API used (no auth)

- `GET  /api/printer-agent/branches`
- `POST /api/printer-agent/heartbeat`
- `POST /api/printer-agent/printers`
- `GET  /api/printer-agent/jobs?branch_id=`
- `POST /api/printer-agent/jobs/{id}/ack`
