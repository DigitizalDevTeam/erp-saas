using System.Drawing;
using System.Drawing.Printing;
using System.Globalization;
using System.Text.Json;

namespace PosPrinterAgent;

public static class PrinterDiscovery
{
    public static List<(string SystemName, string DisplayName)> GetInstalledPrinters()
    {
        var list = new List<(string, string)>();
        foreach (string name in PrinterSettings.InstalledPrinters)
        {
            if (!string.IsNullOrWhiteSpace(name))
            {
                list.Add((name, name));
            }
        }

        return list;
    }
}

public static class SlipPrinter
{
    private const float SidePaddingLeft = 14f;
    private const float SidePaddingRight = 28f;
    private const float KeyValueGap = 10f;
    private const float TopPadding = 2f;

    public static void PrintJob(PrintJobDto job)
    {
        var requestedName = (job.SystemPrinterName ?? "").Trim();
        if (string.IsNullOrEmpty(requestedName))
        {
            throw new InvalidOperationException("Job has no system_printer_name.");
        }

        var printerName = ResolveInstalledPrinterName(requestedName);
        if (string.IsNullOrEmpty(printerName))
        {
            throw new InvalidOperationException($"Printer not found on this PC: {requestedName}");
        }

        // Prefer full-page receipt image only when explicitly rendered as image (legacy).
        // Native Cash Sale invoices use structured BuildReceiptLines (no image_base64).
        if (string.Equals(job.Zone, "receipt", StringComparison.OrdinalIgnoreCase)
            && !IsNativeReceiptRender(job)
            && TryGetReceiptImageBytes(job, out var imageBytes)
            && imageBytes is { Length: > 0 })
        {
            PrintReceiptImage(printerName, imageBytes, job.Title ?? "Receipt");
            return;
        }

        var lines = string.Equals(job.Zone, "receipt", StringComparison.OrdinalIgnoreCase)
            ? BuildReceiptLines(job, ResolveInvoiceTemplate(job))
            : BuildSlipLines(job);
        var doc = new PrintDocument();
        doc.PrinterSettings.PrinterName = printerName;
        doc.DocumentName = job.Title ?? (string.Equals(job.Zone, "receipt", StringComparison.OrdinalIgnoreCase) ? "Receipt" : "Production Slip");
        doc.DefaultPageSettings.Margins = new Margins(0, 0, 0, 0);

        var titleFont = new Font("Consolas", 12f, FontStyle.Bold);
        var bodyFont = new Font("Consolas", 9f, FontStyle.Regular);
        var bodyBoldFont = new Font("Consolas", 9f, FontStyle.Bold);
        var brush = Brushes.Black;

        doc.PrintPage += (_, e) =>
        {
            var g = e.Graphics!;
            g.TextRenderingHint = System.Drawing.Text.TextRenderingHint.ClearTypeGridFit;

            float pageLeft = e.PageBounds.Left;
            float pageWidth = e.PageBounds.Width;
            float contentLeft = pageLeft + SidePaddingLeft;
            float contentWidth = Math.Max(40f, pageWidth - SidePaddingLeft - SidePaddingRight);
            float y = e.PageBounds.Top + TopPadding;

            foreach (var line in lines)
            {
                Font font;
                if (line.Kind == SlipLineKind.Title)
                {
                    font = titleFont;
                }
                else if (line.Bold)
                {
                    font = bodyBoldFont;
                }
                else
                {
                    font = bodyFont;
                }

                y += DrawLine(g, brush, font, line, contentLeft, contentWidth, y);
            }

            e.HasMorePages = false;
        };

        try
        {
            doc.Print();
        }
        finally
        {
            titleFont.Dispose();
            bodyFont.Dispose();
            bodyBoldFont.Dispose();
            doc.Dispose();
        }
    }

    /// <summary>
    /// Resolve a job printer name to an installed Windows queue.
    /// Supports exact match and UNC/share aliases (e.g. "pos" vs "\\server\pos").
    /// </summary>
    private static string? ResolveInstalledPrinterName(string requestedName)
    {
        var requested = (requestedName ?? "").Trim();
        if (requested.Length == 0)
        {
            return null;
        }

        var installed = PrinterSettings.InstalledPrinters.Cast<string>()
            .Where(p => !string.IsNullOrWhiteSpace(p))
            .Select(p => p.Trim())
            .ToList();

        // 1) Exact match
        var exact = installed.FirstOrDefault(p =>
            string.Equals(p, requested, StringComparison.OrdinalIgnoreCase));
        if (exact != null)
        {
            return exact;
        }

        var requestedLeaf = PrinterNameLeaf(requested);

        // 2) Installed UNC/share ends with \requested or /requested
        var uncSuffix = installed.FirstOrDefault(p =>
        {
            var leaf = PrinterNameLeaf(p);
            return string.Equals(leaf, requestedLeaf, StringComparison.OrdinalIgnoreCase)
                   || p.EndsWith("\\" + requested, StringComparison.OrdinalIgnoreCase)
                   || p.EndsWith("/" + requested, StringComparison.OrdinalIgnoreCase);
        });
        if (uncSuffix != null)
        {
            return uncSuffix;
        }

        // 3) Requested is UNC and an installed short name matches the share leaf
        if (requested.Contains('\\') || requested.Contains('/'))
        {
            var byLeaf = installed.FirstOrDefault(p =>
                string.Equals(PrinterNameLeaf(p), requestedLeaf, StringComparison.OrdinalIgnoreCase));
            if (byLeaf != null)
            {
                return byLeaf;
            }
        }

        return null;
    }

    private static string PrinterNameLeaf(string name)
    {
        var n = (name ?? "").Trim().TrimEnd('\\', '/');
        if (n.Length == 0)
        {
            return "";
        }

        var i = Math.Max(n.LastIndexOf('\\'), n.LastIndexOf('/'));
        return i >= 0 && i < n.Length - 1 ? n[(i + 1)..] : n;
    }

    private static bool IsNativeReceiptRender(PrintJobDto job)
    {
        if (job.Payload.ValueKind != JsonValueKind.Object)
        {
            return false;
        }

        if (job.Payload.TryGetProperty("render", out var renderEl)
            && renderEl.ValueKind == JsonValueKind.String
            && string.Equals(renderEl.GetString(), "native", StringComparison.OrdinalIgnoreCase))
        {
            return true;
        }

        // New invoice payloads include document_title / vat_breakdown without a full-page image.
        if (job.Payload.TryGetProperty("vat_breakdown", out _))
        {
            return true;
        }

        return false;
    }

    private static bool TryGetReceiptImageBytes(PrintJobDto job, out byte[]? bytes)
    {
        bytes = null;
        if (job.Payload.ValueKind != JsonValueKind.Object)
        {
            return false;
        }

        if (!job.Payload.TryGetProperty("image_base64", out var imgEl))
        {
            return false;
        }

        var b64 = imgEl.ValueKind == JsonValueKind.String ? (imgEl.GetString() ?? "") : "";
        if (string.IsNullOrWhiteSpace(b64))
        {
            return false;
        }

        try
        {
            bytes = Convert.FromBase64String(b64.Trim());
            return bytes.Length > 0;
        }
        catch
        {
            bytes = null;
            return false;
        }
    }

    private static void PrintReceiptImage(string printerName, byte[] imageBytes, string documentName)
    {
        using var ms = new MemoryStream(imageBytes);
        using var image = Image.FromStream(ms);
        using var doc = new PrintDocument();
        doc.PrinterSettings.PrinterName = printerName;
        doc.DocumentName = documentName;
        doc.DefaultPageSettings.Margins = new Margins(0, 0, 0, 0);

        doc.PrintPage += (_, e) =>
        {
            var g = e.Graphics!;
            g.InterpolationMode = System.Drawing.Drawing2D.InterpolationMode.HighQualityBicubic;
            g.PixelOffsetMode = System.Drawing.Drawing2D.PixelOffsetMode.HighQuality;

            var page = e.PageBounds;
            float maxW = Math.Max(1f, page.Width - 4f);
            float scale = maxW / image.Width;
            float drawW = maxW;
            float drawH = image.Height * scale;
            // Keep aspect; if taller than page, scale down to fit height once (single page).
            if (drawH > page.Height - 4f)
            {
                scale = (page.Height - 4f) / image.Height;
                drawW = image.Width * scale;
                drawH = page.Height - 4f;
            }

            float x = page.Left + (page.Width - drawW) / 2f;
            float y = page.Top + 2f;
            g.DrawImage(image, x, y, drawW, drawH);
            e.HasMorePages = false;
        };

        doc.Print();
    }

    private static float DrawLine(
        Graphics g,
        Brush brush,
        Font font,
        SlipLine line,
        float left,
        float width,
        float y)
    {
        float lineHeight = font.GetHeight(g);

        switch (line.Kind)
        {
            case SlipLineKind.Title:
            {
                using var sf = new StringFormat
                {
                    Alignment = StringAlignment.Center,
                    LineAlignment = StringAlignment.Near,
                    FormatFlags = StringFormatFlags.LineLimit,
                };
                var size = g.MeasureString(line.Text, font, new SizeF(width, 2000f), sf);
                var h = Math.Max(lineHeight + 2f, size.Height);
                g.DrawString(line.Text, font, brush, new RectangleF(left, y, width, h), sf);
                return h + 2f;
            }
            case SlipLineKind.Separator:
            case SlipLineKind.Full:
            {
                using var sf = new StringFormat
                {
                    Alignment = line.Kind == SlipLineKind.Separator || line.Centered
                        ? StringAlignment.Center
                        : StringAlignment.Near,
                    LineAlignment = StringAlignment.Near,
                    FormatFlags = StringFormatFlags.LineLimit,
                    Trimming = StringTrimming.None,
                };
                var size = g.MeasureString(line.Text, font, new SizeF(width, 2000f), sf);
                var h = Math.Max(lineHeight, size.Height);
                g.DrawString(line.Text, font, brush, new RectangleF(left, y, width, h), sf);
                return h;
            }
            case SlipLineKind.KeyValue:
            {
                var key = (line.Key ?? "").TrimEnd();
                if (!key.EndsWith(":", StringComparison.Ordinal))
                {
                    key += ":";
                }

                var value = line.Value ?? "";

                // Key column ~40% of width; value gets the rest (with gap).
                float keyWidth = Math.Min(width * 0.42f, width - 40f);
                float valueWidth = Math.Max(20f, width - keyWidth - KeyValueGap);
                float valueLeft = left + keyWidth + KeyValueGap;

                using var keySf = new StringFormat
                {
                    Alignment = StringAlignment.Near,
                    LineAlignment = StringAlignment.Near,
                    FormatFlags = StringFormatFlags.LineLimit,
                    Trimming = StringTrimming.None,
                };
                using var valueSf = new StringFormat
                {
                    Alignment = StringAlignment.Far,
                    LineAlignment = StringAlignment.Near,
                    FormatFlags = StringFormatFlags.LineLimit,
                    Trimming = StringTrimming.None,
                };

                var keySize = g.MeasureString(key, font, new SizeF(keyWidth, 2000f), keySf);
                var valueSize = g.MeasureString(value, font, new SizeF(valueWidth, 2000f), valueSf);
                var h = Math.Max(lineHeight, Math.Max(keySize.Height, valueSize.Height));

                g.DrawString(key, font, brush, new RectangleF(left, y, keyWidth, h), keySf);
                g.DrawString(value, font, brush, new RectangleF(valueLeft, y, valueWidth, h), valueSf);
                return h;
            }
            case SlipLineKind.Image:
            {
                if (line.ImageBytes is not { Length: > 0 })
                {
                    return 0f;
                }

                try
                {
                    using var ms = new MemoryStream(line.ImageBytes);
                    using var img = Image.FromStream(ms);
                    float maxW = Math.Min(width * (line.ImageMaxWidthFraction > 0 ? line.ImageMaxWidthFraction : 0.45f), width);
                    float scale = maxW / Math.Max(1, img.Width);
                    float drawW = img.Width * scale;
                    float drawH = img.Height * scale;
                    float x = left + (width - drawW) / 2f;
                    // Logos are pre-thresholded to 1-bit; nearest-neighbor avoids reintroducing gray.
                    g.InterpolationMode = line.UseNearestNeighbor
                        ? System.Drawing.Drawing2D.InterpolationMode.NearestNeighbor
                        : System.Drawing.Drawing2D.InterpolationMode.HighQualityBicubic;
                    g.PixelOffsetMode = line.UseNearestNeighbor
                        ? System.Drawing.Drawing2D.PixelOffsetMode.Half
                        : System.Drawing.Drawing2D.PixelOffsetMode.HighQuality;
                    g.DrawImage(img, x, y, drawW, drawH);
                    return drawH + 4f;
                }
                catch
                {
                    return 0f;
                }
            }
            case SlipLineKind.Box:
            {
                using var pen = new Pen(Color.Black, 1f);
                g.DrawRectangle(pen, left, y + 4f, Math.Max(20f, width - 2f), 36f);
                return 44f;
            }
            case SlipLineKind.Columns:
            {
                var cols = line.Columns ?? Array.Empty<string>();
                if (cols.Length == 0)
                {
                    return lineHeight;
                }

                const float gap = 4f;
                var widths = new float[cols.Length];
                float used = gap * (cols.Length - 1);
                for (var i = 1; i < cols.Length; i++)
                {
                    var t = string.IsNullOrEmpty(cols[i]) ? "0" : cols[i];
                    widths[i] = Math.Max(i == 1 ? 22f : 44f, g.MeasureString(t, font).Width + 2f);
                    used += widths[i];
                }

                widths[0] = Math.Max(36f, width - used);

                using var near = new StringFormat
                {
                    Alignment = StringAlignment.Near,
                    LineAlignment = StringAlignment.Near,
                    FormatFlags = StringFormatFlags.NoWrap,
                    Trimming = StringTrimming.EllipsisCharacter,
                };
                using var far = new StringFormat
                {
                    Alignment = StringAlignment.Far,
                    LineAlignment = StringAlignment.Near,
                    FormatFlags = StringFormatFlags.NoWrap,
                    Trimming = StringTrimming.None,
                };
                var h = lineHeight + 1f;
                float x = left;
                for (var i = 0; i < cols.Length; i++)
                {
                    g.DrawString(cols[i], font, brush, new RectangleF(x, y, widths[i], h), i == 0 ? near : far);
                    x += widths[i] + gap;
                }

                return h;
            }
            default:
                return lineHeight;
        }
    }

    private enum SlipLineKind
    {
        Title,
        Separator,
        Full,
        KeyValue,
        Image,
        Box,
        Columns,
    }

    private sealed class SlipLine
    {
        public SlipLineKind Kind { get; init; }
        public string Text { get; init; } = "";
        public string? Key { get; init; }
        public string? Value { get; init; }
        public bool Bold { get; init; }
        public bool Centered { get; init; }
        public byte[]? ImageBytes { get; init; }
        public float ImageMaxWidthFraction { get; init; }
        public bool UseNearestNeighbor { get; init; }
        public string[]? Columns { get; init; }
    }

    private static Dictionary<string, string> ReadLabels(JsonElement payload)
    {
        var labels = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        if (payload.ValueKind != JsonValueKind.Object
            || !payload.TryGetProperty("labels", out var labelsEl)
            || labelsEl.ValueKind != JsonValueKind.Object)
        {
            return labels;
        }

        foreach (var prop in labelsEl.EnumerateObject())
        {
            if (prop.Value.ValueKind != JsonValueKind.String)
            {
                continue;
            }

            var value = prop.Value.GetString();
            if (!string.IsNullOrWhiteSpace(value))
            {
                labels[prop.Name] = value.Trim();
            }
        }

        return labels;
    }

    private static string Label(Dictionary<string, string> labels, string key, string fallback)
    {
        return labels.TryGetValue(key, out var value) && !string.IsNullOrWhiteSpace(value)
            ? value
            : fallback;
    }

    /// <summary>
    /// Draws a sample cash-sale slip for the agent template picker.
    /// </summary>
    public static Bitmap RenderInvoicePreview(string template)
    {
        using var doc = JsonDocument.Parse(PreviewReceiptJson);
        var job = new PrintJobDto
        {
            Zone = "receipt",
            Title = "CASH SALE",
            Payload = doc.RootElement,
        };
        var lines = BuildReceiptLines(job, NormalizeInvoiceTemplate(template));
        const int width = 380;
        const float left = 14f;
        float contentWidth = width - 14f - 18f;

        using var titleFont = new Font("Consolas", 12f, FontStyle.Bold);
        using var bodyFont = new Font("Consolas", 9f, FontStyle.Regular);
        using var bodyBoldFont = new Font("Consolas", 9f, FontStyle.Bold);
        using var measureBmp = new Bitmap(8, 8);
        using var measure = Graphics.FromImage(measureBmp);
        measure.TextRenderingHint = System.Drawing.Text.TextRenderingHint.ClearTypeGridFit;

        float y = 8f;
        foreach (var line in lines)
        {
            var font = line.Kind == SlipLineKind.Title ? titleFont : (line.Bold ? bodyBoldFont : bodyFont);
            y += DrawLine(measure, Brushes.Black, font, line, left, contentWidth, y);
        }

        var bmp = new Bitmap(width, Math.Max(40, (int)Math.Ceiling(y) + 12));
        using var g = Graphics.FromImage(bmp);
        g.Clear(Color.White);
        g.TextRenderingHint = System.Drawing.Text.TextRenderingHint.ClearTypeGridFit;
        y = 8f;
        foreach (var line in lines)
        {
            var font = line.Kind == SlipLineKind.Title ? titleFont : (line.Bold ? bodyBoldFont : bodyFont);
            y += DrawLine(g, Brushes.Black, font, line, left, contentWidth, y);
        }

        return bmp;
    }

    /// <summary>
    /// Debug mode ("Print as image"): render this job to a PNG file instead
    /// of sending it to a printer. Returns the saved file path.
    /// </summary>
    public static string SaveJobAsImage(PrintJobDto job, string folder)
    {
        Directory.CreateDirectory(folder);

        if (string.Equals(job.Zone, "receipt", StringComparison.OrdinalIgnoreCase)
            && !IsNativeReceiptRender(job)
            && TryGetReceiptImageBytes(job, out var imageBytes)
            && imageBytes is { Length: > 0 })
        {
            var imagePath = Path.Combine(folder, $"job-{job.Id}-{DateTime.Now:yyyyMMdd-HHmmss}.png");
            File.WriteAllBytes(imagePath, imageBytes);
            return imagePath;
        }

        var lines = string.Equals(job.Zone, "receipt", StringComparison.OrdinalIgnoreCase)
            ? BuildReceiptLines(job, ResolveInvoiceTemplate(job))
            : BuildSlipLines(job);

        using var bmp = RenderLinesToBitmap(lines);
        var path = Path.Combine(folder, $"job-{job.Id}-{DateTime.Now:yyyyMMdd-HHmmss}.png");
        bmp.Save(path, System.Drawing.Imaging.ImageFormat.Png);
        return path;
    }

    private static Bitmap RenderLinesToBitmap(List<SlipLine> lines)
    {
        const int width = 380;
        const float left = 14f;
        float contentWidth = width - 14f - 18f;

        using var titleFont = new Font("Consolas", 12f, FontStyle.Bold);
        using var bodyFont = new Font("Consolas", 9f, FontStyle.Regular);
        using var bodyBoldFont = new Font("Consolas", 9f, FontStyle.Bold);
        using var measureBmp = new Bitmap(8, 8);
        using var measure = Graphics.FromImage(measureBmp);
        measure.TextRenderingHint = System.Drawing.Text.TextRenderingHint.ClearTypeGridFit;

        float y = 8f;
        foreach (var line in lines)
        {
            var font = line.Kind == SlipLineKind.Title ? titleFont : (line.Bold ? bodyBoldFont : bodyFont);
            y += DrawLine(measure, Brushes.Black, font, line, left, contentWidth, y);
        }

        var bmp = new Bitmap(width, Math.Max(40, (int)Math.Ceiling(y) + 12));
        using var g = Graphics.FromImage(bmp);
        g.Clear(Color.White);
        g.TextRenderingHint = System.Drawing.Text.TextRenderingHint.ClearTypeGridFit;
        y = 8f;
        foreach (var line in lines)
        {
            var font = line.Kind == SlipLineKind.Title ? titleFont : (line.Bold ? bodyBoldFont : bodyFont);
            y += DrawLine(g, Brushes.Black, font, line, left, contentWidth, y);
        }

        return bmp;
    }

    private const string PreviewReceiptJson = """
        {
          "header": {
            "company": "HOYA HOYA INHAMBANE - 1, SU, LDA",
            "nuit": "402078588",
            "company_address": "Praia do Tofo, Bairro Josina Machel, Inhambane",
            "company_phone": "840868888",
            "company_email": "hoyahoya.inhambane1@hoyahoya.co.mz",
            "document_title": "CASH SALE",
            "pos_number": "#PDV00020",
            "copy_label": "Copy",
            "issued_at": "2026-10-01 10:12:59",
            "customer": "Walk-in Customer",
            "currency": "MT",
            "table": "01",
            "operator": "Hoya Hoya Inhambane-1, SU, LDA",
            "branch": "Hoya Hoya Inhambane-1, SU, LDA"
          },
          "rows": [
            {
              "name": "Testing product",
              "qty": 2,
              "price": 172.41,
              "unit_incl_vat": 199.99,
              "vat_rate": 16,
              "line_total": 399.99,
              "tax_label": "VAT 16%"
            }
          ],
          "totals": {
            "subtotal": 399.99,
            "discount": 0,
            "vat": 55.17,
            "total": 399.99,
            "paid": 399.99,
            "change": 0
          },
          "vat_breakdown": [
            { "rate": 16, "net": 344.82, "tax": 55.17, "total": 399.99 }
          ],
          "payment": { "method": "Cash" },
          "footer": ["Thank You For Shopping With Us. Please visit again."]
        }
        """;

    private static string ResolveInvoiceTemplate(PrintJobDto job)
    {
        var fromJob = "";
        if (job.Payload.ValueKind == JsonValueKind.Object)
        {
            fromJob = GetString(job.Payload, "template");
        }

        return NormalizeInvoiceTemplate(fromJob);
    }

    private static string NormalizeInvoiceTemplate(string? template)
    {
        var raw = (template ?? "").Trim().ToLowerInvariant();
        return raw is "classic" or "compact" ? raw : "elegant";
    }

    private static List<SlipLine> BuildReceiptLines(PrintJobDto job, string? template)
    {
        var lines = new List<SlipLine>();
        var title = FirstNonEmpty(job.Title ?? "", "CASH SALE");
        var labels = job.Payload.ValueKind == JsonValueKind.Object
            ? ReadLabels(job.Payload)
            : new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        var classic = string.Equals(template, "classic", StringComparison.OrdinalIgnoreCase);
        var compact = string.Equals(template, "compact", StringComparison.OrdinalIgnoreCase);
        var isCredit = false;
        if (job.Payload.ValueKind == JsonValueKind.Object)
        {
            if (job.Payload.TryGetProperty("is_credit", out var creditEl)
                && (creditEl.ValueKind == JsonValueKind.True
                    || (creditEl.ValueKind == JsonValueKind.Number && creditEl.TryGetInt32(out var creditFlag) && creditFlag != 0)))
            {
                isCredit = true;
            }
        }

        var majorRule = classic || compact ? '-' : '=';

        string company = "";
        string companyAddress = "";
        string companyPhone = "";
        string companyEmail = "";
        string companyWebsite = "";
        string nuit = "";
        string documentTitle = "";
        string posNumber = "";
        string copyLabel = "Original";
        string issuedAt = "";
        string table = "";
        string customer = "";
        string customerNuit = "";
        string customerAddress = "";
        string currency = "MT";
        string date = DateTime.Now.ToString("yyyy-MM-dd");
        string time = DateTime.Now.ToString("HH:mm:ss");
        string operatorName = "";
        string branch = "";
        string logoB64 = "";

        if (job.Payload.ValueKind == JsonValueKind.Object
            && job.Payload.TryGetProperty("header", out var header)
            && header.ValueKind == JsonValueKind.Object)
        {
            company = GetString(header, "company");
            companyAddress = GetString(header, "company_address");
            companyPhone = GetString(header, "company_phone");
            companyEmail = GetString(header, "company_email");
            companyWebsite = GetString(header, "company_website");
            nuit = GetString(header, "nuit");
            documentTitle = GetString(header, "document_title");
            posNumber = GetString(header, "pos_number");
            copyLabel = FirstNonEmpty(GetString(header, "copy_label"), "Original");
            issuedAt = GetString(header, "issued_at");
            table = GetString(header, "table");
            customer = GetString(header, "customer");
            customerNuit = GetString(header, "customer_nuit");
            customerAddress = GetString(header, "customer_address");
            currency = FirstNonEmpty(GetString(header, "currency"), "MT");
            date = FirstNonEmpty(GetString(header, "date"), date);
            time = FirstNonEmpty(GetString(header, "time"), time);
            operatorName = GetString(header, "operator");
            branch = GetString(header, "branch");
            logoB64 = GetString(header, "logo_base64");
        }

        if (string.IsNullOrWhiteSpace(issuedAt))
        {
            issuedAt = $"{date} {time}";
        }

        if (string.IsNullOrWhiteSpace(documentTitle))
        {
            documentTitle = title;
        }

        // --- Header / company ---
        if (!SlipLogoStore.HasSlipLogo() && HasPrintableValue(logoB64))
        {
            try
            {
                SlipLogoStore.SaveSlipLogoFromBytes(Convert.FromBase64String(logoB64));
            }
            catch
            {
                // The heartbeat download is the normal path. A bad payload logo must not stop the slip.
            }
        }

        // Prefer the logo saved on this PC; fall back to the logo sent with the job.
        var localLogoBytes = SlipLogoStore.TryReadSlipLogoBytes();
        if (localLogoBytes is { Length: > 0 })
        {
            AddImageFromBytes(lines, localLogoBytes, 0.55f, forThermalLogo: true);
        }
        else
        {
            AddImageFromBase64(lines, logoB64, 0.55f, forThermalLogo: true);
        }
        if (HasPrintableValue(company))
        {
            AddTitle(lines, company.Trim());
        }

        if (HasPrintableValue(nuit))
        {
            AddCentered(lines, Label(labels, "nuit", "NUIT") + ": " + nuit.Trim());
        }

        if (HasPrintableValue(companyAddress))
        {
            AddCentered(lines, companyAddress);
        }

        var contact = "";
        if (HasPrintableValue(companyPhone))
        {
            contact = Label(labels, "phone", "Phone") + ": " + companyPhone.Trim();
        }

        if (HasPrintableValue(companyEmail))
        {
            contact = string.IsNullOrEmpty(contact)
                ? companyEmail.Trim()
                : contact + " · " + companyEmail.Trim();
        }

        if (HasPrintableValue(contact))
        {
            AddCentered(lines, contact);
        }

        AddSeparator(lines, majorRule);
        if (compact)
        {
            AddKv(lines, Label(labels, "date", "Date"), date);
            AddKv(lines, Label(labels, "invoice_no", "Invoice No"), posNumber);
            AddKvOrDash(lines, Label(labels, "operator", "Operator"), operatorName);
            if (HasPrintableValue(branch))
            {
                AddKv(lines, copyLabel, branch);
            }

            if (HasPrintableValue(table))
            {
                AddKv(lines, Label(labels, "table", "Table"), table);
            }
        }
        else
        {
            AddTitle(lines, documentTitle.ToUpperInvariant());
            if (HasPrintableValue(posNumber))
            {
                AddCentered(lines, Label(labels, "no", "No.") + " " + posNumber.Trim(), bold: true);
            }

            AddCentered(lines, copyLabel + " · " + Label(labels, "issued_at", "Issued at") + " " + issuedAt);
        }

        AddSeparator(lines, '-');

        // --- Customer / table meta ---
        AddKvOrDash(lines, Label(labels, "customer", "Customer"), customer);
        AddKvOrDash(lines, Label(labels, "nuit", "NUIT"), customerNuit);
        AddKvOrDash(lines, Label(labels, "address", "Address"), customerAddress);
        AddKv(lines, Label(labels, "currency", "Currency"), currency);
        if (!compact && HasPrintableValue(table))
        {
            AddKv(lines, Label(labels, "table", "Table"), table);
        }

        AddSeparator(lines, '-');

        // --- Items ---
        var descLabel = compact
            ? Label(labels, "product", "PRODUCT")
            : Label(labels, "description", "DESCRIPTION");
        var qtyLabel = Label(labels, "qty", "QTY");
        var vatLabel = Label(labels, "vat", "VAT");
        var priceLabel = Label(labels, "unit_incl_vat", "UNIT (INCL. VAT)");
        var totalLabel = Label(labels, "total", "TOTAL");
        if (classic)
        {
            AddColumns(lines, descLabel, qtyLabel, vatLabel, priceLabel, totalLabel, bold: true);
        }
        else if (compact)
        {
            AddColumns(lines, descLabel, qtyLabel, vatLabel, priceLabel, totalLabel, bold: true);
        }
        else
        {
            var descHeader = descLabel.Length > 9 ? "DESC" : descLabel;
            var vatHeader = vatLabel.Length > 4 ? "VAT" : vatLabel;
            var unitHeader = priceLabel.Length > 8 ? "UNIT" : priceLabel;
            AddFull(lines, PadColumns(descHeader, qtyLabel, vatHeader, unitHeader, totalLabel), bold: true);
        }

        AddSeparator(lines, '-');

        if (job.Payload.ValueKind == JsonValueKind.Object
            && job.Payload.TryGetProperty("rows", out var rows)
            && rows.ValueKind == JsonValueKind.Array)
        {
            foreach (var row in rows.EnumerateArray())
            {
                var qty = GetInt(row, "qty");
                if (qty <= 0)
                {
                    qty = GetInt(row, "quantity");
                }

                var name = GetString(row, "name");
                var price = GetDecimal(row, "unit_incl_vat");
                if (price <= 0)
                {
                    price = GetDecimal(row, "price");
                }
                var lineTotal = GetDecimal(row, "line_total");
                if (lineTotal <= 0 && qty > 0 && price > 0)
                {
                    lineTotal = qty * price;
                }

                var note = GetString(row, "note");

                var qtyText = qty > 0 ? qty.ToString(CultureInfo.InvariantCulture) : "";
                var vatText = FormatRate(GetDecimal(row, "vat_rate"));
                if (vatText.Length == 0)
                {
                    // Older payloads only carry the "VAT 16%" label text.
                    var taxLabel = GetString(row, "tax_label");
                    var digitAt = -1;
                    for (var i = 0; i < taxLabel.Length; i++)
                    {
                        if (char.IsDigit(taxLabel[i]))
                        {
                            digitAt = i;
                            break;
                        }
                    }

                    if (digitAt >= 0)
                    {
                        var pctAt = taxLabel.IndexOf('%', digitAt);
                        if (pctAt > digitAt)
                        {
                            vatText = taxLabel.Substring(digitAt, pctAt - digitAt + 1).Trim();
                        }
                    }
                }

                var priceText = FormatMoney(price, currency);
                var totalText = FormatMoney(lineTotal, currency);
                if (classic)
                {
                    AddColumns(lines, name, qtyText, vatText, priceText, totalText, bold: true);
                }
                else if (compact)
                {
                    AddColumns(lines, name, qtyText, vatText, priceText, totalText, bold: true);
                }
                else
                {
                    AddFull(lines, name, bold: true);
                    AddFull(lines, PadColumns("", qtyText, vatText, priceText, totalText));
                }

                if (HasPrintableValue(note))
                {
                    AddFull(lines, Label(labels, "note", "Note") + ": " + note.Trim());
                }
            }
        }

        decimal subtotal = 0m;
        decimal discount = 0m;
        decimal vat = 0m;
        decimal tip = 0m;
        decimal total = 0m;
        decimal paid = 0m;
        decimal change = 0m;

        if (job.Payload.ValueKind == JsonValueKind.Object
            && job.Payload.TryGetProperty("totals", out var totals)
            && totals.ValueKind == JsonValueKind.Object)
        {
            subtotal = GetDecimal(totals, "subtotal");
            discount = GetDecimal(totals, "discount");
            vat = GetDecimal(totals, "vat");
            tip = GetDecimal(totals, "tip");
            total = GetDecimal(totals, "total");
            paid = GetDecimal(totals, "paid");
            change = GetDecimal(totals, "change");
            if (total <= 0)
            {
                total = Math.Max(0m, subtotal - discount);
            }
        }

        AddSeparator(lines, '-');
        AddKv(lines, Label(labels, "sub_total", "Sub Total"), FormatMoney(subtotal, currency));
        AddKv(lines, Label(labels, "discount", "Discount"), FormatMoney(discount, currency));
        AddKv(lines, Label(labels, "vat", "VAT"), FormatMoney(vat, currency));
        if (tip > 0)
        {
            AddKv(lines, Label(labels, "tip", "Tip"), FormatMoney(tip, currency));
        }

        AddSeparator(lines, majorRule);
        AddKv(lines, compact ? Label(labels, "grand_total", "Grand Total") : Label(labels, "total", "TOTAL"), FormatMoney(total, currency), bold: true);
        if (isCredit)
        {
            AddKv(lines, Label(labels, "due_amount", "Due Amount"), FormatMoney(total, currency));
        }
        else
        {
            AddKv(lines, Label(labels, "paid", "Paid"), FormatMoney(paid, currency));
            AddKv(lines, Label(labels, "change", "Change"), FormatMoney(change, currency));
        }
        AddSeparator(lines, '-');

        // --- VAT breakdown ---
        if (job.Payload.ValueKind == JsonValueKind.Object
            && job.Payload.TryGetProperty("vat_breakdown", out var vatBreakdown)
            && vatBreakdown.ValueKind == JsonValueKind.Array
            && vatBreakdown.GetArrayLength() > 0)
        {
            AddFull(lines, PadBreakdown(
                Label(labels, "vat", "VAT"),
                Label(labels, "net_amount", "Net Amount"),
                Label(labels, "tax", "Tax"),
                Label(labels, "total", "Total")), bold: true);
            foreach (var vb in vatBreakdown.EnumerateArray())
            {
                var rate = GetDecimal(vb, "rate");
                var net = GetDecimal(vb, "net");
                var tax = GetDecimal(vb, "tax");
                var rowTotal = GetDecimal(vb, "total");
                if (rowTotal == 0)
                {
                    rowTotal = net + tax; // older payloads without a total field
                }

                AddFull(lines, PadBreakdown(
                    rate.ToString("0", CultureInfo.InvariantCulture) + "%",
                    FormatMoney(net, currency),
                    FormatMoney(tax, currency),
                    FormatMoney(rowTotal, currency)));
            }

            AddSeparator(lines, '-');
        }

        string payMethod = "";
        string payRef = "";
        string payNotes = "";
        if (job.Payload.ValueKind == JsonValueKind.Object
            && job.Payload.TryGetProperty("payment", out var payment)
            && payment.ValueKind == JsonValueKind.Object)
        {
            payMethod = GetString(payment, "method");
            payRef = GetString(payment, "reference");
            payNotes = GetString(payment, "notes");
        }

        AddKvOrDash(lines, Label(labels, "payment", "Payment"), payMethod);
        if (HasPrintableValue(payNotes))
        {
            AddKv(lines, Label(labels, "notes", "Notes"), payNotes);
        }

        AddKvOrDash(lines, Label(labels, "reference", "Reference"), payRef);
        if (!compact)
        {
            AddKvOrDash(lines, Label(labels, "operator", "Operator"), operatorName);
            AddKvOrDash(lines, Label(labels, "branch", "Branch"), branch);
        }

        if (isCredit)
        {
            AddSeparator(lines, '-');
            AddFull(lines, Label(labels, "client_signature", "Client signature for credit"), bold: true);
            lines.Add(new SlipLine { Kind = SlipLineKind.Box });
            AddKv(lines, Label(labels, "due_amount", "Due Amount"), FormatMoney(total, currency));
        }

        // --- QR ---
        string qrB64 = "";
        if (job.Payload.ValueKind == JsonValueKind.Object)
        {
            qrB64 = GetString(job.Payload, "qr_base64");
        }

        if (HasPrintableValue(qrB64))
        {
            AddSeparator(lines, '-');
            AddImageFromBase64(lines, qrB64, 0.62f);
        }

        // --- Footer ---
        string thankYou = "Thank You For Shopping With Us. Please visit again.";
        string generatedBy = "Invoice generated by ERPSaaS";
        string website = FirstNonEmpty(companyWebsite, "");
        string processed = "Document Processed by Computer";
        if (job.Payload.ValueKind == JsonValueKind.Object
            && job.Payload.TryGetProperty("footer", out var footer)
            && footer.ValueKind == JsonValueKind.Object)
        {
            thankYou = FirstNonEmpty(GetString(footer, "thank_you"), thankYou);
            generatedBy = FirstNonEmpty(GetString(footer, "generated_by"), generatedBy);
            website = FirstNonEmpty(GetString(footer, "website"), website);
            processed = FirstNonEmpty(GetString(footer, "processed"), processed);
        }

        AddSeparator(lines, '-');
        AddCentered(lines, thankYou, bold: true);
        if (HasPrintableValue(generatedBy))
        {
            AddCentered(lines, generatedBy);
        }

        if (HasPrintableValue(website))
        {
            AddCentered(lines, website);
        }

        if (HasPrintableValue(processed))
        {
            AddCentered(lines, processed);
        }

        return lines;
    }

    private static void AddImageFromBase64(List<SlipLine> lines, string base64, float maxWidthFraction, bool forThermalLogo = false)
    {
        if (string.IsNullOrWhiteSpace(base64))
        {
            return;
        }

        try
        {
            var clean = base64.Trim();
            var comma = clean.IndexOf(',');
            if (clean.StartsWith("data:", StringComparison.OrdinalIgnoreCase) && comma > 0)
            {
                clean = clean[(comma + 1)..];
            }

            clean = clean.Replace("\r", "").Replace("\n", "").Replace(" ", "");
            var bytes = Convert.FromBase64String(clean);
            AddImageFromBytes(lines, bytes, maxWidthFraction, forThermalLogo);
        }
        catch
        {
            // ignore bad images
        }
    }

    private static void AddImageFromBytes(List<SlipLine> lines, byte[] bytes, float maxWidthFraction, bool forThermalLogo = false)
    {
        if (bytes is not { Length: > 0 })
        {
            return;
        }

        try
        {
            if (forThermalLogo)
            {
                var converted = ConvertLogoToThermalBlackWhite(bytes);
                if (converted is { Length: > 0 })
                {
                    bytes = converted;
                }
            }

            lines.Add(new SlipLine
            {
                Kind = SlipLineKind.Image,
                ImageBytes = bytes,
                ImageMaxWidthFraction = maxWidthFraction,
                UseNearestNeighbor = forThermalLogo,
            });
        }
        catch
        {
            // ignore bad images
        }
    }

    /// <summary>
    /// Convert a color/grayscale logo to high-contrast 1-bit black/white for thermal printers.
    /// Light-colored logos (gold/gray) must become black ink — luma-only thresholds turn them white.
    /// </summary>
    private static byte[]? ConvertLogoToThermalBlackWhite(byte[] imageBytes)
    {
        try
        {
            using var inputMs = new MemoryStream(imageBytes);
            using var src = new Bitmap(inputMs);
            var w = src.Width;
            var h = src.Height;
            if (w <= 0 || h <= 0)
            {
                return null;
            }

            using var dest = new Bitmap(w, h, System.Drawing.Imaging.PixelFormat.Format32bppArgb);
            var blackCount = 0;
            for (var y = 0; y < h; y++)
            {
                for (var x = 0; x < w; x++)
                {
                    var c = src.GetPixel(x, y);
                    var isInk = IsLogoInkPixel(c);
                    dest.SetPixel(x, y, isInk ? Color.Black : Color.White);
                    if (isInk)
                    {
                        blackCount++;
                    }
                }
            }

            // If almost nothing inked (e.g. very pale logo), force any non-transparent pixel to black.
            var total = Math.Max(1, w * h);
            if (blackCount * 100 < total) // < 1% black — pale logo fallback
            {
                blackCount = 0;
                for (var y = 0; y < h; y++)
                {
                    for (var x = 0; x < w; x++)
                    {
                        var c = src.GetPixel(x, y);
                        var isInk = c.A >= 32;
                        dest.SetPixel(x, y, isInk ? Color.Black : Color.White);
                        if (isInk)
                        {
                            blackCount++;
                        }
                    }
                }
            }

            if (blackCount == 0)
            {
                return null;
            }

            using var outMs = new MemoryStream();
            dest.Save(outMs, System.Drawing.Imaging.ImageFormat.Png);
            return outMs.ToArray();
        }
        catch
        {
            return null;
        }
    }

    /// <summary>
    /// True for pixels that should burn on thermal paper (any visible non-background ink).
    /// </summary>
    private static bool IsLogoInkPixel(Color c)
    {
        if (c.A < 32)
        {
            return false;
        }

        // Near-white background → no ink
        if (c.R >= 245 && c.G >= 245 && c.B >= 245)
        {
            return false;
        }

        var luma = (0.299 * c.R) + (0.587 * c.G) + (0.114 * c.B);
        var max = Math.Max(c.R, Math.Max(c.G, c.B));
        var min = Math.Min(c.R, Math.Min(c.G, c.B));
        var saturation = max == 0 ? 0 : (double)(max - min) / max;

        // Dark ink OR any tinted/colored pixel (gold, brand colors) → black
        if (luma < 210)
        {
            return true;
        }

        if (saturation > 0.08)
        {
            return true;
        }

        // Light gray that is still visibly darker than paper
        return luma < 235;
    }

    private static string PadColumns(string c1, string c2, string c3, string c4, string c5)
    {
        // Monospace-friendly 36-char thermal layout: desc(9) qty(3) vat(4) unit(8) total(9)
        static string Fit(string s, int w, bool right = false)
        {
            s ??= "";
            if (s.Length > w)
            {
                s = s[..w];
            }

            return right ? s.PadLeft(w) : s.PadRight(w);
        }

        return Fit(c1, 9) + Fit(c2, 3, true) + " " + Fit(c3, 4, true) + " " + Fit(c4, 8, true) + " " + Fit(c5, 9, true);
    }

    private static string PadBreakdown(string c1, string c2, string c3, string c4)
    {
        // 32-char thermal VAT breakdown: rate(4) net(9) tax(9) total(10)
        static string Fit(string s, int w, bool right = false)
        {
            s ??= "";
            if (s.Length > w)
            {
                s = s[..w];
            }

            return right ? s.PadLeft(w) : s.PadRight(w);
        }

        return Fit(c1, 4) + Fit(c2, 9, true) + Fit(c3, 9, true) + Fit(c4, 10, true);
    }

    private static List<SlipLine> BuildSlipLines(PrintJobDto job)
    {
        var lines = new List<SlipLine>();
        var title = job.Title ?? (job.Zone?.Equals("bar", StringComparison.OrdinalIgnoreCase) == true
            ? "BAR SLIP"
            : "KITCHEN SLIP");

        AddTitle(lines, title.ToUpperInvariant());
        AddSeparator(lines, '=');

        string tableNo = "";
        string waiter = "";
        string orderNo = "";
        string orderType = "";
        string guest = "";
        string date = "";
        string time = "";

        if (job.Payload.ValueKind == JsonValueKind.Object
            && job.Payload.TryGetProperty("header", out var header)
            && header.ValueKind == JsonValueKind.Object)
        {
            tableNo = FirstNonEmpty(GetString(header, "table_no"), GetString(header, "table_name"));
            waiter = GetString(header, "waiter_name");
            orderNo = FirstNonEmpty(GetString(header, "order_no"), GetString(header, "pos_number"));
            orderType = GetString(header, "order_type");
            guest = GetString(header, "guest_name");
            date = GetString(header, "date");
            time = GetString(header, "time");

            if (string.IsNullOrWhiteSpace(date) || string.IsNullOrWhiteSpace(time))
            {
                var sentAt = GetString(header, "sent_at");
                if (!string.IsNullOrWhiteSpace(sentAt) && DateTime.TryParse(sentAt, out var dt))
                {
                    if (string.IsNullOrWhiteSpace(date))
                    {
                        date = dt.ToString("yyyy-MM-dd");
                    }

                    if (string.IsNullOrWhiteSpace(time))
                    {
                        time = dt.ToString("HH:mm:ss");
                    }
                }
            }
        }

        if (string.IsNullOrWhiteSpace(date))
        {
            date = DateTime.Now.ToString("yyyy-MM-dd");
        }

        if (string.IsNullOrWhiteSpace(time))
        {
            time = DateTime.Now.ToString("HH:mm:ss");
        }

        AddKv(lines, "Table No", string.IsNullOrWhiteSpace(tableNo) ? "-" : tableNo);
        AddKv(lines, "Waiter", string.IsNullOrWhiteSpace(waiter) ? "-" : waiter);
        AddKv(lines, "Order No", string.IsNullOrWhiteSpace(orderNo) ? "-" : orderNo);
        if (!string.IsNullOrWhiteSpace(orderType))
        {
            AddKv(lines, "Type", orderType);
        }

        if (!string.IsNullOrWhiteSpace(guest))
        {
            AddKv(lines, "Guest", guest);
        }

        AddKv(lines, "Date", date);
        AddKv(lines, "Time", time);
        AddSeparator(lines, '-');
        AddFull(lines, "ORDER DETAILS");
        AddSeparator(lines, '-');

        decimal subtotal = 0m;
        if (job.Payload.ValueKind == JsonValueKind.Object
            && job.Payload.TryGetProperty("rows", out var rows)
            && rows.ValueKind == JsonValueKind.Array)
        {
            var rowIndex = 0;
            foreach (var row in rows.EnumerateArray())
            {
                rowIndex++;
                var qty = GetInt(row, "qty");
                var name = GetString(row, "name");
                var note = GetString(row, "note");
                var price = GetDecimal(row, "price");
                var salePrice = GetDecimal(row, "sale_price");
                if (salePrice <= 0)
                {
                    salePrice = price;
                }

                var lineTotal = GetDecimal(row, "line_total");
                if (lineTotal <= 0 && qty > 0 && price > 0)
                {
                    lineTotal = qty * price;
                }

                subtotal += lineTotal;

                if (rowIndex > 1)
                {
                    AddSeparator(lines, '.');
                }

                AddFull(lines, $"{qty} x {name}");

                var sku = GetString(row, "sku");
                if (HasPrintableValue(sku))
                {
                    AddKv(lines, "SKU", sku.Trim());
                }

                if (salePrice > 0 || price > 0)
                {
                    AddKv(lines, "Sale Price", FormatMoney(salePrice > 0 ? salePrice : price));
                }

                // Separate Size / Color only when set (no combined Variants line, no N/A).
                var sizeLabel = FirstNonEmpty(GetString(row, "size_label"), "Size");
                var colorLabel = FirstNonEmpty(GetString(row, "color_label"), "Color");
                var size = GetString(row, "size");
                var color = GetString(row, "color");
                if (HasPrintableValue(size))
                {
                    AddKv(lines, sizeLabel, size.Trim());
                }

                if (HasPrintableValue(color))
                {
                    AddKv(lines, colorLabel, color.Trim());
                }

                var groupSet = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
                if (row.TryGetProperty("groups", out var groupsEl) && groupsEl.ValueKind == JsonValueKind.Array)
                {
                    foreach (var gEl in groupsEl.EnumerateArray())
                    {
                        var gName = gEl.ValueKind == JsonValueKind.String ? (gEl.GetString() ?? "") : gEl.ToString();
                        if (!string.IsNullOrWhiteSpace(gName))
                        {
                            groupSet.Add(gName.Trim());
                        }
                    }
                }

                if (row.TryGetProperty("options", out var optionsEl) && optionsEl.ValueKind == JsonValueKind.Array)
                {
                    foreach (var opt in optionsEl.EnumerateArray())
                    {
                        var optName = GetString(opt, "name");
                        var optGroup = GetString(opt, "group");
                        // Always resolve a quantity (default 1) so Qty: 1 prints the same as Qty: 3.
                        var optQty = GetDecimal(opt, "quantity");
                        if (optQty <= 0)
                        {
                            optQty = GetDecimal(opt, "qty");
                        }
                        if (optQty <= 0)
                        {
                            optQty = 1;
                        }

                        if (!string.IsNullOrWhiteSpace(optGroup))
                        {
                            groupSet.Add(optGroup.Trim());
                        }

                        if (string.IsNullOrWhiteSpace(optName))
                        {
                            continue;
                        }

                        var qtyLabel = optQty == Math.Truncate(optQty)
                            ? ((int)optQty).ToString(CultureInfo.InvariantCulture)
                            : optQty.ToString("0.####", CultureInfo.InvariantCulture);

                        // Name + Qty on separate lines so Qty: 1 is always visible (not lost in wrap).
                        AddKv(lines, "Product Option", optName.Trim());
                        AddKv(lines, "Qty", qtyLabel);
                    }
                }

                if (groupSet.Count > 0)
                {
                    AddKv(lines, "Group", string.Join(", ", groupSet));
                }

                if (row.TryGetProperty("ingredients", out var ingsEl) && ingsEl.ValueKind == JsonValueKind.Array)
                {
                    foreach (var ing in ingsEl.EnumerateArray())
                    {
                        var ingName = GetString(ing, "name");
                        if (string.IsNullOrWhiteSpace(ingName))
                        {
                            continue;
                        }

                        var ingQty = GetDecimal(ing, "qty");
                        if (ingQty <= 0)
                        {
                            ingQty = 1;
                        }

                        var qtyLabel = ingQty == Math.Truncate(ingQty)
                            ? ((int)ingQty).ToString(CultureInfo.InvariantCulture)
                            : ingQty.ToString("0.####", CultureInfo.InvariantCulture);
                        var ingUnit = GetString(ing, "unit").Trim();
                        if (!string.IsNullOrWhiteSpace(ingUnit))
                        {
                            qtyLabel += " " + ingUnit;
                        }

                        AddKv(lines, "Ingredient", ingName.Trim());
                        AddKv(lines, "Qty", qtyLabel);
                    }
                }

                if (HasPrintableValue(note))
                {
                    AddKv(lines, "Notes", note.Trim(), bold: true);
                }

                if (price > 0 || lineTotal > 0)
                {
                    AddKv(lines, "Line Total", $"{FormatMoney(price)} x {qty} = {FormatMoney(lineTotal)}");
                }
            }
        }

        if (job.Payload.ValueKind == JsonValueKind.Object
            && job.Payload.TryGetProperty("totals", out var totals)
            && totals.ValueKind == JsonValueKind.Object)
        {
            var t = GetDecimal(totals, "total");
            var s = GetDecimal(totals, "subtotal");
            if (t > 0)
            {
                subtotal = t;
            }
            else if (s > 0)
            {
                subtotal = s;
            }
        }

        AddSeparator(lines, '-');
        AddKv(lines, "Sub Total", FormatMoney(subtotal));
        AddKv(lines, "Total", FormatMoney(subtotal));
        AddSeparator(lines, '=');
        AddCentered(lines, $"{date}  {time}");

        return lines;
    }

    private static void AddTitle(List<SlipLine> lines, string text)
        => lines.Add(new SlipLine { Kind = SlipLineKind.Title, Text = text });

    private static void AddSeparator(List<SlipLine> lines, char c)
        => lines.Add(new SlipLine { Kind = SlipLineKind.Separator, Text = new string(c, 28) });

    private static void AddFull(List<SlipLine> lines, string text, bool bold = false)
        => lines.Add(new SlipLine { Kind = SlipLineKind.Full, Text = text ?? "", Bold = bold });

    private static void AddColumns(List<SlipLine> lines, string c1, string c2, string c3, string c4, string c5, bool bold = false)
        => lines.Add(new SlipLine
        {
            Kind = SlipLineKind.Columns,
            Columns = new[] { c1 ?? "", c2 ?? "", c3 ?? "", c4 ?? "", c5 ?? "" },
            Bold = bold,
        });

    private static void AddColumns(List<SlipLine> lines, string c1, string c2, string c3, string c4, bool bold = false)
        => lines.Add(new SlipLine
        {
            Kind = SlipLineKind.Columns,
            Columns = new[] { c1 ?? "", c2 ?? "", c3 ?? "", c4 ?? "" },
            Bold = bold,
        });

    private static void AddCentered(List<SlipLine> lines, string text, bool bold = false)
        => lines.Add(new SlipLine { Kind = SlipLineKind.Full, Text = text ?? "", Centered = true, Bold = bold });

    private static void AddKv(List<SlipLine> lines, string key, string value, bool bold = false)
    {
        if (!HasPrintableValue(value))
        {
            return;
        }

        lines.Add(new SlipLine
        {
            Kind = SlipLineKind.KeyValue,
            Key = key,
            Value = value.Trim(),
            Bold = bold,
        });
    }

    private static void AddKvOrDash(List<SlipLine> lines, string key, string? value)
    {
        var v = HasPrintableValue(value) ? value!.Trim() : "—";
        lines.Add(new SlipLine
        {
            Kind = SlipLineKind.KeyValue,
            Key = key,
            Value = v,
        });
    }

    /// <summary>True when value should appear on the slip (not empty / N/A / dash).</summary>
    private static bool HasPrintableValue(string? value)
    {
        if (string.IsNullOrWhiteSpace(value))
        {
            return false;
        }

        var v = value.Trim();
        return !v.Equals("N/A", StringComparison.OrdinalIgnoreCase)
               && v != "-"
               && v != "—";
    }

    private static string FirstNonEmpty(params string[] values)
    {
        foreach (var v in values)
        {
            if (!string.IsNullOrWhiteSpace(v))
            {
                return v.Trim();
            }
        }

        return "";
    }

    private static string FormatRate(decimal rate)
    {
        if (rate <= 0)
        {
            return "";
        }

        return rate.ToString("0.##", CultureInfo.InvariantCulture) + "%";
    }

    private static string FormatMoney(decimal amount)
        => FormatMoney(amount, "");

    private static string FormatMoney(decimal amount, string currency)
    {
        var num = amount.ToString("0.00", CultureInfo.InvariantCulture);
        if (string.IsNullOrWhiteSpace(currency))
        {
            return num;
        }

        return num + " " + currency.Trim();
    }

    private static string GetString(JsonElement el, string name)
    {
        if (el.TryGetProperty(name, out var p))
        {
            return p.ValueKind == JsonValueKind.String ? (p.GetString() ?? "") : p.ToString();
        }

        return "";
    }

    private static int GetInt(JsonElement el, string name)
    {
        if (el.TryGetProperty(name, out var p) && p.TryGetInt32(out var v))
        {
            return v;
        }

        return 0;
    }

    private static decimal GetDecimal(JsonElement el, string name)
    {
        if (!el.TryGetProperty(name, out var p))
        {
            return 0m;
        }

        if (p.ValueKind == JsonValueKind.Number && p.TryGetDecimal(out var d))
        {
            return d;
        }

        if (p.ValueKind == JsonValueKind.String
            && decimal.TryParse(p.GetString(), NumberStyles.Any, CultureInfo.InvariantCulture, out var parsed))
        {
            return parsed;
        }

        return 0m;
    }
}
