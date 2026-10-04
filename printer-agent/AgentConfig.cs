using System.Text.Json;
using System.Text.Json.Serialization;

namespace PosPrinterAgent;

public sealed class AgentConfig
{
    public string BaseUrl { get; set; } = "http://erp-saas.test";
    public int BranchId { get; set; }
    public int PollIntervalMs { get; set; } = 2000;

    /// <summary>Stable per-PC pairing code shown in the agent; entered once in ERP Printer Settings.</summary>
    public string DeviceCode { get; set; } = "";

    /// <summary>Launch automatically when Windows starts (HKCU Run key).</summary>
    public bool AutoStartWithWindows { get; set; }

    /// <summary>Debug mode: save each print job as a PNG image instead of printing.</summary>
    public bool PrintAsImage { get; set; }

    public string EnsuredDeviceCode()
    {
        var code = (DeviceCode ?? "").Trim().ToUpperInvariant();
        if (code.Length < 6)
        {
            code = Guid.NewGuid().ToString("N")[..8].ToUpperInvariant();
            DeviceCode = code;
            Save();
        }

        return code;
    }

    /// <summary>Folder where debug images are saved when PrintAsImage is on.</summary>
    public static string DebugImagesFolder =>
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "PosPrinterAgent", "debug-images");

    /// <summary>elegant, classic, or compact. Used only when a job does not send a template name.</summary>
    public string InvoiceTemplate { get; set; } = "elegant";

    public string NormalizedInvoiceTemplate()
    {
        var raw = (InvoiceTemplate ?? "").Trim().ToLowerInvariant();
        return raw is "classic" or "compact" ? raw : "elegant";
    }

    private static string ConfigPath =>
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "PosPrinterAgent", "config.json");

    public static AgentConfig Load()
    {
        try
        {
            var path = ConfigPath;
            if (File.Exists(path))
            {
                var json = File.ReadAllText(path);
                var cfg = JsonSerializer.Deserialize<AgentConfig>(json);
                if (cfg != null)
                {
                    if (cfg.PollIntervalMs < 1000)
                    {
                        cfg.PollIntervalMs = 2000;
                    }

                    cfg.InvoiceTemplate = cfg.NormalizedInvoiceTemplate();
                    cfg.EnsuredDeviceCode();
                    return cfg;
                }
            }
        }
        catch
        {
            // ignore and return defaults
        }

        return new AgentConfig();
    }

    public void Save()
    {
        var dir = Path.GetDirectoryName(ConfigPath);
        if (!string.IsNullOrEmpty(dir))
        {
            Directory.CreateDirectory(dir);
        }

        var json = JsonSerializer.Serialize(this, new JsonSerializerOptions
        {
            WriteIndented = true,
            DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
        });
        File.WriteAllText(ConfigPath, json);
    }

    public string ApiRoot()
    {
        var baseUrl = (BaseUrl ?? "").Trim().TrimEnd('/');
        if (baseUrl.EndsWith("/api", StringComparison.OrdinalIgnoreCase))
        {
            return baseUrl;
        }

        return baseUrl + "/api";
    }
}
