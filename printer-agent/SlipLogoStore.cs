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
