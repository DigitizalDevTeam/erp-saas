namespace PosPrinterAgent;

public partial class MainForm : Form
{
    private AgentConfig _config = AgentConfig.Load();
    private System.Windows.Forms.Timer? _pollTimer;
    private CancellationTokenSource? _loopCts;
    private bool _running;
    private bool _busy;
    private bool _forceExit;
    private DateTime _lastPrinterSync = DateTime.MinValue;

    private readonly TextBox _txtBaseUrl = new();
    private readonly TextBox _txtDeviceCode = new();
    private readonly Label _lblBranch = new();
    private readonly CheckBox _chkAutoStart = new();
    private readonly CheckBox _chkPrintAsImage = new();
    private readonly Button _btnConnect = new();
    private System.Windows.Forms.Timer? _pairTimer;
    private readonly bool _startInTray;
    private readonly Button _btnUploadLogo = new();
    private readonly Label _lblSlipLogo = new();
    private string _appliedTemplate = "";
    private bool _logoErrorLogged;
    private readonly Button _btnStop = new();
    private readonly Button _btnExit = new();
    private readonly Label _lblStatus = new();
    private readonly Label _lblLastPoll = new();
    private readonly Label _lblPrinters = new();
    private readonly TextBox _txtLog = new();
    private readonly NotifyIcon _tray = new();

    public MainForm(bool startInTray = false)
    {
        _startInTray = startInTray;
        Text = "POS Printer Agent";
        Width = 640;
        Height = 560;
        StartPosition = FormStartPosition.CenterScreen;
        FormBorderStyle = FormBorderStyle.FixedSingle;
        MaximizeBox = false;

        BuildUi();
        LoadConfigIntoUi();
        SetupTray();
        FormClosing += MainForm_FormClosing;
        Shown += async (_, _) => await OnShownAsync();
    }

    private void BuildUi()
    {
        var y = 16;
        Controls.Add(MakeLabel("ERP Base URL", 16, y));
        _txtBaseUrl.Left = 140;
        _txtBaseUrl.Top = y - 2;
        _txtBaseUrl.Width = 360;
        Controls.Add(_txtBaseUrl);

        _btnConnect.Text = "Connect";
        _btnConnect.Left = 510;
        _btnConnect.Top = y - 3;
        _btnConnect.Width = 90;
        _btnConnect.Click += async (_, _) => await ConnectAndStartAsync();
        Controls.Add(_btnConnect);
        y += 40;

        Controls.Add(MakeLabel("Device code", 16, y));
        _txtDeviceCode.Left = 140;
        _txtDeviceCode.Top = y - 2;
        _txtDeviceCode.Width = 200;
        _txtDeviceCode.ReadOnly = true;
        _txtDeviceCode.Font = new Font("Consolas", 12f, FontStyle.Bold);
        Controls.Add(_txtDeviceCode);

        _lblBranch.AutoSize = false;
        _lblBranch.Left = 350;
        _lblBranch.Top = y;
        _lblBranch.Width = 250;
        _lblBranch.Height = 22;
        _lblBranch.Text = "Branch: not paired";
        Controls.Add(_lblBranch);
        y += 36;

        _chkAutoStart.Text = "Start with Windows";
        _chkAutoStart.Left = 140;
        _chkAutoStart.Top = y;
        _chkAutoStart.Width = 170;
        _chkAutoStart.CheckedChanged += (_, _) => ApplyAutoStartSetting();
        Controls.Add(_chkAutoStart);

        _chkPrintAsImage.Text = "Print as image (debug)";
        _chkPrintAsImage.Left = 320;
        _chkPrintAsImage.Top = y;
        _chkPrintAsImage.Width = 200;
        _chkPrintAsImage.CheckedChanged += (_, _) =>
        {
            _config.PrintAsImage = _chkPrintAsImage.Checked;
            _config.Save();
            Log(_config.PrintAsImage
                ? "Debug mode ON: print jobs are saved as images in " + AgentConfig.DebugImagesFolder
                : "Debug mode OFF: jobs print on the printer.");
        };
        Controls.Add(_chkPrintAsImage);
        y += 36;

        Controls.Add(MakeLabel("Slip logo", 16, y));
        _btnUploadLogo.Left = 140;
        _btnUploadLogo.Top = y - 3;
        _btnUploadLogo.Width = 120;
        _btnUploadLogo.Click += (_, _) => UploadOrChangeSlipLogo();
        Controls.Add(_btnUploadLogo);

        _lblSlipLogo.AutoSize = false;
        _lblSlipLogo.Left = 270;
        _lblSlipLogo.Top = y;
        _lblSlipLogo.Width = 330;
        _lblSlipLogo.Height = 22;
        Controls.Add(_lblSlipLogo);
        RefreshSlipLogoUi();
        y += 40;

        _btnStop.Text = "Stop";
        _btnStop.Left = 140;
        _btnStop.Top = y;
        _btnStop.Width = 100;
        _btnStop.Enabled = false;
        _btnStop.Click += (_, _) => StopAgent();
        Controls.Add(_btnStop);

        _btnExit.Text = "Exit";
        _btnExit.Left = 250;
        _btnExit.Top = y;
        _btnExit.Width = 100;
        _btnExit.Click += (_, _) => ExitApplication();
        Controls.Add(_btnExit);
        y += 40;

        _lblStatus.AutoSize = false;
        _lblStatus.Left = 16;
        _lblStatus.Top = y;
        _lblStatus.Width = 580;
        _lblStatus.Height = 22;
        _lblStatus.Text = "Status: Offline";
        Controls.Add(_lblStatus);
        y += 24;

        _lblLastPoll.AutoSize = false;
        _lblLastPoll.Left = 16;
        _lblLastPoll.Top = y;
        _lblLastPoll.Width = 580;
        _lblLastPoll.Height = 22;
        _lblLastPoll.Text = "Last poll: —";
        Controls.Add(_lblLastPoll);
        y += 24;

        _lblPrinters.AutoSize = false;
        _lblPrinters.Left = 16;
        _lblPrinters.Top = y;
        _lblPrinters.Width = 580;
        _lblPrinters.Height = 22;
        _lblPrinters.Text = "Local printers: " + PrinterDiscovery.GetInstalledPrinters().Count;
        Controls.Add(_lblPrinters);
        y += 28;

        _txtLog.Left = 16;
        _txtLog.Top = y;
        _txtLog.Width = 590;
        _txtLog.Height = 180;
        _txtLog.Multiline = true;
        _txtLog.ScrollBars = ScrollBars.Vertical;
        _txtLog.ReadOnly = true;
        _txtLog.Font = new Font("Consolas", 9f);
        Controls.Add(_txtLog);
    }

    private void RefreshSlipLogoUi()
    {
        var has = SlipLogoStore.HasSlipLogo();
        _btnUploadLogo.Text = has ? "Change Logo" : "Upload Logo";
        _lblSlipLogo.Text = has ? "Slip logo: saved on this PC" : "Slip logo: not saved yet";
    }

    private void UploadOrChangeSlipLogo()
    {
        using var dlg = new OpenFileDialog
        {
            Title = "Select slip logo",
            Filter = "Image files|*.png;*.jpg;*.jpeg;*.bmp;*.gif|All files|*.*",
            CheckFileExists = true,
            Multiselect = false,
        };
        if (dlg.ShowDialog(this) != DialogResult.OK)
        {
            return;
        }

        try
        {
            SlipLogoStore.SaveSlipLogoFromFile(dlg.FileName);
            RefreshSlipLogoUi();
            AppendLog("Slip logo saved. It will be used on Pay & Print slips.");
        }
        catch (Exception ex)
        {
            MessageBox.Show(this, "Could not save logo: " + ex.Message, "POS Printer Agent",
                MessageBoxButtons.OK, MessageBoxIcon.Error);
            AppendLog("Slip logo save failed: " + ex.Message);
        }
    }

    private static Label MakeLabel(string text, int x, int y)
    {
        return new Label
        {
            Text = text,
            Left = x,
            Top = y,
            AutoSize = true,
        };
    }

    private void SetupTray()
    {
        _tray.Text = "POS Printer Agent";
        _tray.Icon = SystemIcons.Application;
        _tray.Visible = true;
        _tray.DoubleClick += (_, _) => ShowMainWindow();

        var menu = new ContextMenuStrip();
        menu.Items.Add("Open", null, (_, _) => ShowMainWindow());
        menu.Items.Add("Exit", null, (_, _) => ExitApplication());
        _tray.ContextMenuStrip = menu;
    }

    private void ShowMainWindow()
    {
        Show();
        WindowState = FormWindowState.Normal;
        Activate();
    }

    private void LoadConfigIntoUi()
    {
        _txtBaseUrl.Text = _config.BaseUrl;
        _txtDeviceCode.Text = _config.EnsuredDeviceCode();
        _chkAutoStart.Checked = _config.AutoStartWithWindows;
        _chkPrintAsImage.Checked = _config.PrintAsImage;
        RefreshBranchLabel();
        RefreshSlipLogoUi();
    }

    private void RefreshBranchLabel()
    {
        _lblBranch.Text = _config.BranchId > 0
            ? $"Branch: #{_config.BranchId} (paired)"
            : "Branch: not paired";
    }

    private void SaveUiSettings()
    {
        _config.BaseUrl = _txtBaseUrl.Text.Trim();
        _config.AutoStartWithWindows = _chkAutoStart.Checked;
        _config.PrintAsImage = _chkPrintAsImage.Checked;
        _config.PollIntervalMs = 2000;
        _config.Save();
    }

    private void ApplyAutoStartSetting()
    {
        _config.AutoStartWithWindows = _chkAutoStart.Checked;
        _config.Save();
        try
        {
            using var key = Microsoft.Win32.Registry.CurrentUser.OpenSubKey(
                @"Software\Microsoft\Windows\CurrentVersion\Run", writable: true);
            if (key == null)
            {
                return;
            }

            if (_chkAutoStart.Checked)
            {
                var exe = Environment.ProcessPath ?? Application.ExecutablePath;
                key.SetValue("PosPrinterAgent", $"\"{exe}\" --tray");
                Log("Auto-start with Windows: ON");
            }
            else
            {
                key.DeleteValue("PosPrinterAgent", throwOnMissingValue: false);
                Log("Auto-start with Windows: OFF");
            }
        }
        catch (Exception ex)
        {
            Log("Auto-start setting failed: " + ex.Message);
        }
    }

    private async Task ApplyHeartbeatAsync(ApiClient client, CancellationToken ct)
    {
        var info = await client.HeartbeatAsync(ct);
        var template = (info.InvoiceTemplate ?? "elegant").Trim().ToLowerInvariant();
        if (template is not ("classic" or "compact"))
        {
            template = "elegant";
        }

        if (!string.Equals(_appliedTemplate, template, StringComparison.Ordinal))
        {
            _appliedTemplate = template;
            Log("Invoice template from ERP: " + template + ".");
        }

        try
        {
            if (await client.DownloadLogoIfChangedAsync(info.LogoHash, ct))
            {
                _logoErrorLogged = false;
                RefreshSlipLogoUi();
                Log("POS logo saved on this PC (" + SlipLogoStore.SlipLogoPath + ").");
            }
        }
        catch (Exception ex)
        {
            if (!_logoErrorLogged)
            {
                _logoErrorLogged = true;
                Log("Logo download failed: " + ex.Message);
            }
        }
    }

    private async Task OnShownAsync()
    {
        if (_startInTray)
        {
            Hide();
        }

        if (string.IsNullOrWhiteSpace(_config.BaseUrl))
        {
            return;
        }

        try
        {
            await ConnectAndStartAsync();
        }
        catch (Exception ex)
        {
            Log("Auto-start skipped: " + ex.Message);
        }
    }

    /// <summary>
    /// Save settings, ask the ERP for this PC's pairing (set from a
    /// logged-in browser session) and start polling when paired.
    /// No manual URL/branch setup is needed after the one-time pairing.
    /// </summary>
    private async Task ConnectAndStartAsync()
    {
        var baseUrl = _txtBaseUrl.Text.Trim();
        if (string.IsNullOrWhiteSpace(baseUrl))
        {
            MessageBox.Show(this, "Please enter the ERP Base URL.", "POS Printer Agent",
                MessageBoxButtons.OK, MessageBoxIcon.Warning);
            return;
        }

        SaveUiSettings();
        _btnConnect.Enabled = false;
        SetStatus("Checking pairing…");

        try
        {
            var status = await ApiClient.FetchPairStatusAsync(baseUrl, _config.EnsuredDeviceCode(), CancellationToken.None);
            if (status.Paired && status.BranchId > 0)
            {
                ApplyPairing(status);
                StopPairTimer();
                await StartAgentAsync();
                return;
            }

            SetStatus("Not paired — pair this PC from ERP Printer Settings");
            Log($"Not paired yet. In ERP: Printer Settings → enter device code {_config.EnsuredDeviceCode()} → Pair Agent.");
            StartPairTimer();
        }
        catch (Exception ex)
        {
            SetStatus("Offline — connection failed");
            Log("Connect failed: " + ex.Message);
        }
        finally
        {
            _btnConnect.Enabled = true;
        }
    }

    private void ApplyPairing(PairStatusDto status)
    {
        _config.BranchId = status.BranchId;
        _config.Save();
        _lblBranch.Text = string.IsNullOrWhiteSpace(status.BranchName)
            ? $"Branch: #{status.BranchId} (paired)"
            : $"Branch: {status.BranchName} (paired)";
        Log($"Paired to branch #{status.BranchId}" + (string.IsNullOrWhiteSpace(status.BranchName) ? "" : $" ({status.BranchName})") + ".");
    }

    private void StartPairTimer()
    {
        if (_pairTimer != null)
        {
            return;
        }

        _pairTimer = new System.Windows.Forms.Timer { Interval = 5000 };
        _pairTimer.Tick += async (_, _) =>
        {
            if (_running || _busy)
            {
                return;
            }

            try
            {
                var status = await ApiClient.FetchPairStatusAsync(
                    _config.BaseUrl, _config.EnsuredDeviceCode(), CancellationToken.None);
                if (status.Paired && status.BranchId > 0)
                {
                    ApplyPairing(status);
                    StopPairTimer();
                    await StartAgentAsync();
                }
            }
            catch
            {
                // ERP unreachable; keep waiting for pairing.
            }
        };
        _pairTimer.Start();
    }

    private void StopPairTimer()
    {
        _pairTimer?.Stop();
        _pairTimer?.Dispose();
        _pairTimer = null;
    }

    private async Task StartAgentAsync()
    {
        if (_running)
        {
            StopAgent(silent: true);
        }

        _running = true;
        _btnStop.Enabled = true;
        _txtBaseUrl.Enabled = false;
        SetStatus("Online — starting…");

        _loopCts = new CancellationTokenSource();
        try
        {
            using var client = new ApiClient(_config);
            await ApplyHeartbeatAsync(client, _loopCts.Token);
            await SyncPrintersAsync(client, _loopCts.Token);
            SetStatus("Online");
            Log($"Connected to branch #{_config.BranchId}. Polling every 2s.");
        }
        catch (Exception ex)
        {
            Log("Start failed: " + ex.Message);
            SetStatus("Offline — start failed");
            StopAgent(silent: true);
            throw;
        }

        _pollTimer = new System.Windows.Forms.Timer
        {
            Interval = Math.Max(1000, _config.PollIntervalMs),
        };
        _pollTimer.Tick += async (_, _) => await PollOnceAsync();
        _pollTimer.Start();
    }

    private void StopAgent(bool silent = false)
    {
        _running = false;
        StopPairTimer();
        _pollTimer?.Stop();
        _pollTimer?.Dispose();
        _pollTimer = null;
        _loopCts?.Cancel();
        _loopCts?.Dispose();
        _loopCts = null;

        _btnStop.Enabled = false;
        _txtBaseUrl.Enabled = true;
        SetStatus("Offline");
        if (!silent)
        {
            Log("Agent stopped.");
        }
    }

    private void ExitApplication()
    {
        _forceExit = true;
        StopAgent(silent: true);
        try
        {
            _tray.Visible = false;
            _tray.Dispose();
        }
        catch
        {
            // Ignore tray disposal errors during shutdown.
        }

        Application.Exit();
    }

    private async Task PollOnceAsync()
    {
        if (!_running || _busy)
        {
            return;
        }

        _busy = true;
        try
        {
            using var client = new ApiClient(_config);
            var ct = _loopCts?.Token ?? CancellationToken.None;

            await ApplyHeartbeatAsync(client, ct);

            if ((DateTime.UtcNow - _lastPrinterSync).TotalMinutes >= 5)
            {
                await SyncPrintersAsync(client, ct);
            }

            var jobs = await client.ClaimJobsAsync(ct);
            _lblLastPoll.Text = "Last poll: " + DateTime.Now.ToString("HH:mm:ss") +
                                $" — {jobs.Count} job(s)";

            foreach (var job in jobs)
            {
                try
                {
                    if (_config.PrintAsImage)
                    {
                        var savedPath = SlipPrinter.SaveJobAsImage(job, AgentConfig.DebugImagesFolder);
                        await client.AckAsync(job.Id, true, "debug image: " + savedPath, ct);
                        Log($"Job #{job.Id} saved as image (debug): {savedPath}");
                        continue;
                    }

                    Log($"Printing job #{job.Id} ({job.Zone}) → {job.SystemPrinterName}");
                    SlipPrinter.PrintJob(job);
                    await client.AckAsync(job.Id, true, null, ct);
                    Log($"Job #{job.Id} done.");
                }
                catch (Exception ex)
                {
                    Log($"Job #{job.Id} failed: {ex.Message}");
                    try
                    {
                        await client.AckAsync(job.Id, false, ex.Message, ct);
                    }
                    catch (Exception ackEx)
                    {
                        Log($"Ack failed for #{job.Id}: {ackEx.Message}");
                    }
                }
            }

            SetStatus("Online");
        }
        catch (Exception ex)
        {
            SetStatus("Online — poll error (retrying)");
            Log("Poll error: " + ex.Message);
        }
        finally
        {
            _busy = false;
        }
    }

    private async Task SyncPrintersAsync(ApiClient client, CancellationToken ct)
    {
        var printers = PrinterDiscovery.GetInstalledPrinters();
        _lblPrinters.Text = "Local printers: " + printers.Count;
        await client.SyncPrintersAsync(printers, ct);
        _lastPrinterSync = DateTime.UtcNow;
        Log($"Synced {printers.Count} printer(s) to ERP.");
    }

    private void SetStatus(string text)
    {
        if (InvokeRequired)
        {
            BeginInvoke(() => _lblStatus.Text = "Status: " + text);
            return;
        }

        _lblStatus.Text = "Status: " + text;
    }

    private void Log(string message)
    {
        var line = $"[{DateTime.Now:HH:mm:ss}] {message}{Environment.NewLine}";
        if (InvokeRequired)
        {
            BeginInvoke(() => AppendLog(line));
            return;
        }

        AppendLog(line);
    }

    private void AppendLog(string line)
    {
        _txtLog.AppendText(line);
        if (_txtLog.TextLength > 20000)
        {
            _txtLog.Text = _txtLog.Text[^12000..];
        }
    }

    private void MainForm_FormClosing(object? sender, FormClosingEventArgs e)
    {
        // Closing the window (X) keeps the agent alive in the tray.
        // Use Exit to permanently stop polling and terminate the process.
        if (!_forceExit && e.CloseReason == CloseReason.UserClosing)
        {
            e.Cancel = true;
            Hide();
            _tray.ShowBalloonTip(2000, "POS Printer Agent", "Still running in the tray.", ToolTipIcon.Info);
            return;
        }

        StopAgent(silent: true);
        try
        {
            _tray.Visible = false;
            _tray.Dispose();
        }
        catch
        {
            // Already disposed during ExitApplication.
        }
    }

    protected override void OnResize(EventArgs e)
    {
        base.OnResize(e);
        if (WindowState == FormWindowState.Minimized)
        {
            Hide();
        }
    }
}
