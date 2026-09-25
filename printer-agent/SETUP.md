# POS Printer Agent — Setup After Git Pull

Full steps to get the **POS Printer Agent** working on a Windows PC after pulling the latest ERP code.

---

## What this agent does

Standalone Windows app that:

1. Connects to your ERP using **only the Base URL** (no login / API token)
2. Loads branches into a dropdown
3. Syncs local Windows printers to the ERP
4. Polls kitchen/bar print jobs every **2 seconds** and prints them

---

## Prerequisites

| Requirement | Details |
|---|---|
| OS | Windows 10/11 (x64) |
| .NET SDK | **.NET 8** SDK ([download](https://dotnet.microsoft.com/download/dotnet/8.0)) |
| ERP | Laravel app running (Laragon / local / staging / production) |
| Network | PC can reach the ERP Base URL (same LAN or public URL) |
| Printers | Kitchen / Bar printers installed in Windows (USB / network) |

Check .NET is installed:

```bat
dotnet --version
```

You should see `8.x.x` or higher.

---

## Step 1 — Pull latest code

From the ERP project root (`c:\laragon\www\erp-saas` or your path):

```bat
git pull
```

Confirm the agent folder exists:

```bat
dir printer-agent
```

You should see files like `PosPrinterAgent.csproj`, `MainForm.cs`, `Program.cs`, etc.

---

## Step 2 — Update ERP database (Laravel side)

The printer agent needs database tables. From the **ERP project root**:

```bat
php artisan migrate
```

This creates / updates tables such as:

- `pos_printer_agent_heartbeats`
- printer agent job / config related tables

If your environment uses cached config/routes after deploy:

```bat
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

Make sure the ERP site is reachable in a browser, for example:

- Local Laragon: `http://erp-saas.test`
- Production: `https://your-company.example.com`

---

## Step 3 — Install / restore .NET packages

Open a terminal in the agent folder:

```bat
cd printer-agent
dotnet restore
```

---

## Step 4 — Build the agent (choose one)

### Option A — Quick run (dev)

```bat
cd printer-agent
dotnet run
```

This compiles and opens the agent window.

### Option B — Publish a single EXE (recommended for shop PCs)

```bat
cd printer-agent
dotnet publish -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -o publish
```

Output file:

```text
printer-agent\publish\PosPrinterAgent.exe
```

Copy `PosPrinterAgent.exe` to the kitchen/cashier PC (or run it from this folder).

> Self-contained publish means the target PC does **not** need the .NET runtime installed.

---

## Step 5 — Run and connect the agent

1. Double-click `PosPrinterAgent.exe` (or use `dotnet run` from Step 4A).
2. Enter the **ERP Base URL**, for example:
   - `http://erp-saas.test`
   - `https://your-company.example.com`
3. Click **Connect**.
4. Wait until branches load and status shows connected / running.
5. Select the correct **Branch** from the dropdown (changing branch restarts the agent for that branch).

Settings are saved under:

```text
%LocalAppData%\PosPrinterAgent\config.json
```

On next launch, the agent auto-connects with the last Base URL and branch.

---

## Step 6 — Configure printers in POS Restaurant

1. Open ERP → **POS Restaurant**.
2. Open **Print** (Printer Settings).
3. Confirm **Printer Agent Status** is **Online**.
4. Choose **Kitchen** and/or **Bar** printers from the list synced by the agent.
5. Click **Save**.

Place a test order that sends kitchen/bar slips. The agent should pick up the job within ~2 seconds and print.

---

## Step 7 — Keep the agent running on the shop PC

- Leave `PosPrinterAgent.exe` running while POS is in use.
- Closing to tray is fine if the tray icon is still present.
- For a permanent setup, you can pin the EXE to Startup (Windows Startup folder) so it launches after login.

---

## Quick checklist (after every git pull that touches printer-agent)

- [ ] `git pull`
- [ ] `php artisan migrate` (ERP root)
- [ ] `cd printer-agent` → `dotnet restore`
- [ ] Rebuild / republish EXE (`dotnet publish ...` or `dotnet run`)
- [ ] Run agent → Connect with Base URL → select Branch
- [ ] In POS Restaurant Print settings → Agent **Online** → select Kitchen/Bar printers → Save
- [ ] Print a test kitchen/bar slip

---

## API endpoints used (no auth)

These must be reachable at `{BaseUrl}/api/...`:

| Method | Path |
|---|---|
| `GET` | `/api/printer-agent/branches` |
| `POST` | `/api/printer-agent/heartbeat` |
| `POST` | `/api/printer-agent/printers` |
| `GET` | `/api/printer-agent/jobs?branch_id=` |
| `POST` | `/api/printer-agent/jobs/{id}/ack` |

---

## Troubleshooting

### Agent cannot connect / branches empty

- Check Base URL (no trailing slash required; do not include `/api` unless that is your real API root).
- Confirm ERP is up in the browser.
- Confirm PC can reach that host (firewall / DNS / Laragon virtual host).
- Test: open `{BaseUrl}/api/printer-agent/branches` in a browser — you should get JSON.

### Agent Online but nothing prints

- Confirm Kitchen/Bar printers are selected and saved in POS Restaurant Print settings.
- Confirm Windows can print a test page to that printer.
- Check the agent log panel for errors.
- Confirm the order actually enqueues kitchen/bar jobs (restaurant POS flow).

### Migration / table errors in Laravel

```bat
php artisan migrate
php artisan migrate:status
```

Look for the `pos_printer_agent_*` migrations and ensure they ran.

### Rebuild after code changes

If `git pull` updated files under `printer-agent/`, always rebuild:

```bat
cd printer-agent
dotnet publish -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -o publish
```

Then replace the old EXE on the shop PC with the new `publish\PosPrinterAgent.exe`.

---

## Related files

| Path | Purpose |
|---|---|
| `printer-agent/` | Windows agent source |
| `printer-agent/README.md` | Short overview + client usage |
| `app/Http/Controllers/PosPrinterAgentApiController.php` | Agent API |
| `app/Services/PosPrinterAgentService.php` | Jobs, heartbeat, printer sync |
| `routes/api.php` | `/api/printer-agent/*` routes |
| `database/migrations/2026_09_24_*_pos_printer_agent_*.php` | Agent DB tables |
