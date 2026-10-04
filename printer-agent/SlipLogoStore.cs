using System.Drawing;
using System.Drawing.Imaging;

namespace PosPrinterAgent;

/// <summary>
/// Persists the Pay &amp; Print slip logo under %LocalAppData%\PosPrinterAgent\slip_logo.png.
/// </summary>
public static class SlipLogoStore
{
    public static string AppDataDirectory =>
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "PosPrinterAgent");

    public static string SlipLogoPath => Path.Combine(AppDataDirectory, "slip_logo.png");

    public static string SlipLogoHashPath => Path.Combine(AppDataDirectory, "slip_logo.hash");

    public static bool HasSlipLogo()
    {
        try
        {
            var path = SlipLogoPath;
            return File.Exists(path) && new FileInfo(path).Length > 0;
        }
        catch
        {
            return false;
        }
    }

    /// <summary>
    /// Copy/convert the selected image to slip_logo.png (overwrites any previous logo).
    /// </summary>
    public static void SaveSlipLogoFromFile(string sourcePath)
    {
        if (string.IsNullOrWhiteSpace(sourcePath) || !File.Exists(sourcePath))
        {
            throw new FileNotFoundException("Logo file not found.", sourcePath);
        }

        Directory.CreateDirectory(AppDataDirectory);
        var dest = SlipLogoPath;

        // Load then re-save as PNG so JPG/BMP/GIF uploads are normalized.
        using var img = Image.FromFile(sourcePath);
        using var clone = new Bitmap(img);
        // Write via temp file then replace so a locked dest is less likely mid-print.
        var temp = dest + ".tmp";
        clone.Save(temp, ImageFormat.Png);
        if (File.Exists(dest))
        {
            File.Delete(dest);
        }

        File.Move(temp, dest);
    }

    public static void SaveSlipLogoFromBytes(byte[] bytes)
    {
        if (bytes == null || bytes.Length == 0)
        {
            throw new InvalidOperationException("Logo is empty.");
        }

        Directory.CreateDirectory(AppDataDirectory);
        using var ms = new MemoryStream(bytes);
        using var img = Image.FromStream(ms);
        using var clone = new Bitmap(img);
        var dest = SlipLogoPath;
        var temp = dest + ".tmp";
        clone.Save(temp, ImageFormat.Png);
        if (File.Exists(dest))
        {
            File.Delete(dest);
        }

        File.Move(temp, dest);
    }

    public static string ReadSavedHash()
    {
        try
        {
            var path = SlipLogoHashPath;
            return File.Exists(path) ? (File.ReadAllText(path) ?? "").Trim() : "";
        }
        catch
        {
            return "";
        }
    }

    /// <summary>Remove the locally cached ERP logo when the ERP no longer has one.</summary>
    public static void DeleteSlipLogo()
    {
        try
        {
            if (File.Exists(SlipLogoPath))
            {
                File.Delete(SlipLogoPath);
            }
        }
        catch
        {
            // A locked or missing file must not stop the agent.
        }

        try
        {
            if (File.Exists(SlipLogoHashPath))
            {
                File.Delete(SlipLogoHashPath);
            }
        }
        catch
        {
            // Ignore hash cleanup failures.
        }
    }

    public static void WriteSavedHash(string hash)
    {
        Directory.CreateDirectory(AppDataDirectory);
        File.WriteAllText(SlipLogoHashPath, (hash ?? "").Trim());
    }

    public static byte[]? TryReadSlipLogoBytes()
    {
        try
        {
            if (!HasSlipLogo())
            {
                return null;
            }

            return File.ReadAllBytes(SlipLogoPath);
        }
        catch
        {
            return null;
        }
    }
}
