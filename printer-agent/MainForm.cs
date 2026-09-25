namespace PosPrinterAgent;

public partial class MainForm : Form
{
    private AgentConfig _config = AgentConfig.Load();
    private System.Windows.Forms.Timer? _pollTimer;
    private CancellationTokenSource? _loopCts;
    private bool _running;
    private bool _busy;
    private bool _autoStarting;
    private bool _forceExit;
    private DateTime _lastPrinterSync = DateTime.MinValue;

    private readonly TextBox _txtBaseUrl = new();
    private readonly ComboBox _cboBranch = new();
    private readonly Button _btnConnect = new();
    private readonly Button _btnStop = new();
    private readonly Button _btnExit = new();
    private readonly Label _lblStatus = new();
    private readonly Label _lblLastPoll = new();
    private readonly Label _lblPrinters = new();
    private readonly TextBox _txtLog = new();
    private readonly NotifyIcon _tray = new();

    public MainForm()
    {
        Text = "POS Printer Agent";
        Width = 640;
        Height = 500;
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
        _btnConnect.Click += async (_, _) => await ConnectAndStartAsync(forceReloadBranches: true);
        Controls.Add(_btnConnect);
        y += 40;

        Controls.Add(MakeLabel("Branch", 16, y));
        _cboBranch.Left = 140;
        _cboBranch.Top = y - 2;
        _cboBranch.Width = 360;
        _cboBranch.DropDownStyle = ComboBoxStyle.DropDownList;
        _cboBranch.DisplayMember = "Name";
        _cboBranch.ValueMember = "Id";
        _cboBranch.SelectedIndexChanged += async (_, _) =>
        {
            if (_autoStarting || !_cboBranch.Enabled)
            {
                return;
            }

            if (_cboBranch.SelectedItem is BranchDto b && b.Id > 0)
            {
                _config.BranchId = b.Id;
                _config.Save();
                if (!string.IsNullOrWhiteSpace(_txtBaseUrl.Text))
                {
                    await ConnectAndStartAsync(forceReloadBranches: false);
                }
            }
        };
        Controls.Add(_cboBranch);
        y += 44;

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
        _txtLog.Height = 250;
        _txtLog.Multiline = true;
        _txtLog.ScrollBars = ScrollBars.Vertical;
        _txtLog.ReadOnly = true;
        _txtLog.Font = new Font("Consolas", 9f);
        Controls.Add(_txtLog);
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
    }

    private async Task OnShownAsync()
    {
        if (string.IsNullOrWhiteSpace(_config.BaseUrl))
        {
            return;
        }

        try
        {
            await ConnectAndStartAsync(forceReloadBranches: true);
        }
        catch (Exception ex)
        {
            Log("Auto-start skipped: " + ex.Message);
        }
    }

    private async Task ConnectAndStartAsync(bool forceReloadBranches)
    {
        var baseUrl = _txtBaseUrl.Text.Trim();
        if (string.IsNullOrWhiteSpace(baseUrl))
        {
            MessageBox.Show(this, "Please enter the ERP Base URL.", "POS Printer Agent",
                MessageBoxButtons.OK, MessageBoxIcon.Warning);
            return;
        }

        _config.BaseUrl = baseUrl;
        _config.PollIntervalMs = 2000;
        _btnConnect.Enabled = false;
        SetStatus("Connecting…");

        try
        {
            if (forceReloadBranches || _cboBranch.Items.Count == 0)
            {
                Log("Loading branches from " + baseUrl);
                var branches = await ApiClient.FetchBranchesAsync(baseUrl, CancellationToken.None);
                _autoStarting = true;
                _cboBranch.DataSource = null;
                _cboBranch.DisplayMember = nameof(BranchDto.Name);
                _cboBranch.ValueMember = nameof(BranchDto.Id);
                _cboBranch.DataSource = branches;

                if (branches.Count == 0)
                {
                    _autoStarting = false;
                    SetStatus("Offline — no branches found");
                    Log("No restaurant branches returned by ERP.");
                    MessageBox.Show(this, "No branches found. Check the ERP Base URL.", "POS Printer Agent",
                        MessageBoxButtons.OK, MessageBoxIcon.Warning);
                    return;
                }

                var preferId = _config.BranchId;
                if (preferId > 0 && branches.Any(b => b.Id == preferId))
                {
                    _cboBranch.SelectedValue = preferId;
                }
                else
                {
                    _cboBranch.SelectedIndex = 0;
                }

                if (_cboBranch.SelectedValue != null)
                {
                    _config.BranchId = Convert.ToInt32(_cboBranch.SelectedValue);
                }
                else if (_cboBranch.SelectedItem is BranchDto first)
                {
                    _config.BranchId = first.Id;
                }

                _autoStarting = false;
            }

            if (_cboBranch.SelectedItem is BranchDto selectedBranch)
            {
                _config.BranchId = selectedBranch.Id;
            }

            if (_config.BranchId < 1)
            {
                SetStatus("Offline — select a branch");
                return;
            }

            _config.Save();
            await StartAgentAsync();
        }
        catch (Exception ex)
        {
            SetStatus("Offline — connection failed");
            Log("Connect failed: " + ex.Message);
            MessageBox.Show(this, "Could not connect to ERP:\n" + ex.Message, "POS Printer Agent",
                MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
        finally
        {
            _btnConnect.Enabled = true;
            _autoStarting = false;
        }
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
            await client.HeartbeatAsync(_loopCts.Token);
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

            await client.HeartbeatAsync(ct);

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
