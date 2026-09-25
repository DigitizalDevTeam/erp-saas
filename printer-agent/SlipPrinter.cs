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
        var printerName = (job.SystemPrinterName ?? "").Trim();
        if (string.IsNullOrEmpty(printerName))
        {
            throw new InvalidOperationException("Job has no system_printer_name.");
        }

        var installed = PrinterSettings.InstalledPrinters.Cast<string>()
            .Any(p => string.Equals(p, printerName, StringComparison.OrdinalIgnoreCase));
        if (!installed)
        {
            throw new InvalidOperationException($"Printer not found on this PC: {printerName}");
        }

        var lines = BuildSlipLines(job);
        var doc = new PrintDocument();
        doc.PrinterSettings.PrinterName = printerName;
        doc.DocumentName = job.Title ?? "Production Slip";
        doc.DefaultPageSettings.Margins = new Margins(0, 0, 0, 0);

        var titleFont = new Font("Consolas", 12f, FontStyle.Bold);
        var bodyFont = new Font("Consolas", 9f, FontStyle.Regular);
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
                var font = line.Kind == SlipLineKind.Title ? titleFont : bodyFont;
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
            doc.Dispose();
        }
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
                    Alignment = line.Kind == SlipLineKind.Separator
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
            default:
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
        }
    }

    private enum SlipLineKind
    {
        Title,
        Separator,
        Full,
        KeyValue,
    }

    private sealed class SlipLine
    {
        public SlipLineKind Kind { get; init; }
        public string Text { get; init; } = "";
        public string? Key { get; init; }
        public string? Value { get; init; }
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

                var optionLines = new List<string>();
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
                        if (!string.IsNullOrWhiteSpace(optGroup))
                        {
                            groupSet.Add(optGroup.Trim());
                        }

                        if (!string.IsNullOrWhiteSpace(optName))
                        {
                            optionLines.Add(string.IsNullOrWhiteSpace(optGroup)
                                ? optName
                                : $"{optName} ({optGroup})");
                        }
                    }
                }

                if (optionLines.Count > 0)
                {
                    AddKv(lines, "Product Options", string.Join(", ", optionLines));
                }

                if (groupSet.Count > 0)
                {
                    AddKv(lines, "Group", string.Join(", ", groupSet));
                }

                var ingredientParts = new List<string>();
                if (row.TryGetProperty("ingredients", out var ingsEl) && ingsEl.ValueKind == JsonValueKind.Array)
                {
                    foreach (var ing in ingsEl.EnumerateArray())
                    {
                        var ingName = GetString(ing, "name");
                        var ingQty = GetDecimal(ing, "qty");
                        if (string.IsNullOrWhiteSpace(ingName))
                        {
                            continue;
                        }

                        ingredientParts.Add(ingQty > 0
                            ? $"{ingName} x {ingQty.ToString("0.####", CultureInfo.InvariantCulture)}"
                            : ingName);
                    }
                }

                if (ingredientParts.Count > 0)
                {
                    AddKv(lines, "Ingredients", string.Join(", ", ingredientParts));
                }

                if (HasPrintableValue(note))
                {
                    AddKv(lines, "Notes", note.Trim());
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
        AddFull(lines, $"{date}  {time}");

        return lines;
    }

    private static void AddTitle(List<SlipLine> lines, string text)
        => lines.Add(new SlipLine { Kind = SlipLineKind.Title, Text = text });

    private static void AddSeparator(List<SlipLine> lines, char c)
        => lines.Add(new SlipLine { Kind = SlipLineKind.Separator, Text = new string(c, 28) });

    private static void AddFull(List<SlipLine> lines, string text)
        => lines.Add(new SlipLine { Kind = SlipLineKind.Full, Text = text ?? "" });

    private static void AddKv(List<SlipLine> lines, string key, string value)
    {
        if (!HasPrintableValue(value))
        {
            return;
        }

        lines.Add(new SlipLine { Kind = SlipLineKind.KeyValue, Key = key, Value = value.Trim() });
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

    private static string FormatMoney(decimal amount)
        => amount.ToString("0.00", CultureInfo.InvariantCulture);

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
