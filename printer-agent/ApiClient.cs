using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace PosPrinterAgent;

public sealed class ApiClient : IDisposable
{
    private readonly HttpClient _http;
    private readonly JsonSerializerOptions _json;

    public ApiClient(AgentConfig config)
    {
        _http = new HttpClient
        {
            Timeout = TimeSpan.FromSeconds(30),
        };
        _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));

        _json = new JsonSerializerOptions
        {
            PropertyNameCaseInsensitive = true,
        };
        BaseApi = config.ApiRoot();
        BranchId = config.BranchId;
    }

    public string BaseApi { get; }
    public int BranchId { get; set; }

    public static async Task<List<BranchDto>> FetchBranchesAsync(string baseUrl, CancellationToken ct)
    {
        var cfg = new AgentConfig { BaseUrl = baseUrl };
        using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
        http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        var url = cfg.ApiRoot() + "/printer-agent/branches";
        using var res = await http.GetAsync(url, ct);
        await EnsureSuccess(res);
        var options = new JsonSerializerOptions { PropertyNameCaseInsensitive = true };
        var body = await res.Content.ReadFromJsonAsync<ApiEnvelope<BranchesData>>(options, ct);
        return body?.Data?.Branches ?? new List<BranchDto>();
    }

    /// <summary>
    /// Ask the ERP whether this PC's device code has been paired (from a
    /// logged-in browser session). Returns the session branch when paired.
    /// </summary>
    public static async Task<PairStatusDto> FetchPairStatusAsync(string baseUrl, string deviceCode, CancellationToken ct)
    {
        var cfg = new AgentConfig { BaseUrl = baseUrl };
        using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
        http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        var url = cfg.ApiRoot() + "/printer-agent/pair-status";
        var payload = new { device_code = deviceCode, machine_name = Environment.MachineName };
        using var res = await http.PostAsJsonAsync(url, payload, ct);
        await EnsureSuccess(res);
        var options = new JsonSerializerOptions { PropertyNameCaseInsensitive = true };
        var body = await res.Content.ReadFromJsonAsync<ApiEnvelope<PairStatusDto>>(options, ct);
        return body?.Data ?? new PairStatusDto();
    }

    public async Task<HeartbeatInfo> HeartbeatAsync(CancellationToken ct)
    {
        var payload = new
        {
            branch_id = BranchId,
            machine_name = Environment.MachineName,
        };
        using var res = await _http.PostAsJsonAsync($"{BaseApi}/printer-agent/heartbeat", payload, ct);
        await EnsureSuccess(res);
        var body = await res.Content.ReadFromJsonAsync<ApiEnvelope<HeartbeatInfo>>(_json, ct);
        return body?.Data ?? new HeartbeatInfo();
    }

    /// <summary>
    /// Download the POS logo from Laravel and store it under LocalAppData when the hash changes.
    /// </summary>
    public async Task<bool> DownloadLogoIfChangedAsync(string? logoHash, CancellationToken ct)
    {
        var hash = (logoHash ?? "").Trim();
        if (hash.Length == 0)
        {
            return false;
        }

        if (string.Equals(hash, SlipLogoStore.ReadSavedHash(), StringComparison.OrdinalIgnoreCase)
            && SlipLogoStore.HasSlipLogo())
        {
            return false;
        }

        var url = $"{BaseApi}/printer-agent/logo?branch_id={BranchId}";
        using var res = await _http.GetAsync(url, ct);
        await EnsureSuccess(res);
        var bytes = await res.Content.ReadAsByteArrayAsync(ct);
        SlipLogoStore.SaveSlipLogoFromBytes(bytes);
        SlipLogoStore.WriteSavedHash(hash);
        return true;
    }

    public async Task SyncPrintersAsync(IEnumerable<(string SystemName, string DisplayName)> printers, CancellationToken ct)
    {
        var list = printers.Select(p => new
        {
            system_name = p.SystemName,
            display_name = p.DisplayName,
        }).ToList();

        var payload = new
        {
            branch_id = BranchId,
            machine_name = Environment.MachineName,
            printers = list,
        };
        using var res = await _http.PostAsJsonAsync($"{BaseApi}/printer-agent/printers", payload, ct);
        await EnsureSuccess(res);
    }

    public async Task<List<PrintJobDto>> ClaimJobsAsync(CancellationToken ct)
    {
        var url = $"{BaseApi}/printer-agent/jobs?branch_id={BranchId}&machine_name={Uri.EscapeDataString(Environment.MachineName)}";
        using var res = await _http.GetAsync(url, ct);
        await EnsureSuccess(res);
        var body = await res.Content.ReadFromJsonAsync<ApiEnvelope<JobsData>>(_json, ct);
        return body?.Data?.Jobs ?? new List<PrintJobDto>();
    }

    public async Task AckAsync(long jobId, bool success, string? error, CancellationToken ct)
    {
        var payload = new
        {
            status = success ? "done" : "failed",
            error_message = error,
            branch_id = BranchId,
        };
        using var res = await _http.PostAsJsonAsync($"{BaseApi}/printer-agent/jobs/{jobId}/ack", payload, ct);
        await EnsureSuccess(res);
    }

    private static async Task EnsureSuccess(HttpResponseMessage res)
    {
        if (res.IsSuccessStatusCode)
        {
            return;
        }

        var text = await res.Content.ReadAsStringAsync();
        throw new HttpRequestException($"HTTP {(int)res.StatusCode}: {text}");
    }

    public void Dispose() => _http.Dispose();
}

public sealed class ApiEnvelope<T>
{
    [JsonPropertyName("is_success")]
    public bool IsSuccess { get; set; }

    [JsonPropertyName("message")]
    public string? Message { get; set; }

    [JsonPropertyName("data")]
    public T? Data { get; set; }
}

public sealed class BranchesData
{
    [JsonPropertyName("branches")]
    public List<BranchDto> Branches { get; set; } = new();
}

public sealed class BranchDto
{
    [JsonPropertyName("id")]
    public int Id { get; set; }

    [JsonPropertyName("name")]
    public string? Name { get; set; }

    public override string ToString() => string.IsNullOrWhiteSpace(Name) ? $"#{Id}" : Name!;
}

public sealed class HeartbeatInfo
{
    [JsonPropertyName("invoice_template")]
    public string? InvoiceTemplate { get; set; }

    [JsonPropertyName("logo_hash")]
    public string? LogoHash { get; set; }
}

public sealed class JobsData
{
    [JsonPropertyName("jobs")]
    public List<PrintJobDto> Jobs { get; set; } = new();
}

public sealed class PairStatusDto
{
    [JsonPropertyName("paired")]
    public bool Paired { get; set; }

    [JsonPropertyName("branch_id")]
    public int BranchId { get; set; }

    [JsonPropertyName("branch_name")]
    public string? BranchName { get; set; }
}

public sealed class PrintJobDto
{
    [JsonPropertyName("id")]
    public long Id { get; set; }

    [JsonPropertyName("zone")]
    public string? Zone { get; set; }

    [JsonPropertyName("system_printer_name")]
    public string? SystemPrinterName { get; set; }

    [JsonPropertyName("title")]
    public string? Title { get; set; }

    [JsonPropertyName("payload")]
    public JsonElement Payload { get; set; }

    [JsonPropertyName("pos_id")]
    public long? PosId { get; set; }

    [JsonPropertyName("status")]
    public string? Status { get; set; }
}
