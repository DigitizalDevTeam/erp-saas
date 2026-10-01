using System.Text.Json;
using System.Text.Json.Serialization;

namespace PosPrinterAgent;

public sealed class AgentConfig
{
    public string BaseUrl { get; set; } = "http://erp-saas.test";
    public int BranchId { get; set; }
    public int PollIntervalMs { get; set; } = 2000;

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
