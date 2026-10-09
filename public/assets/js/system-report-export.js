/* global ExcelJS, jspdf, jspdfAutoTable */
(() => {
  const $ = (selector, root = document) => root.querySelector(selector);
  const formatNumber = (value, decimals = 0) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
  const dateLabel = (value) => new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
  const generatedLabel = (value) => new Date(value.replace(" ", "T")).toLocaleString();
  const fileStem = (report) => `CLINiQ-Clinic-Transaction-Summary-${report.date_from}-to-${report.date_to}`;
  const sectionsFor = (report) => Array.isArray(report?.sections)
    ? report.sections.map((section, index) => ({ ...section, key: section.key || String(index) }))
    : Object.entries(report?.sections || {}).map(([key, section]) => ({ ...section, key }));
  const summaryRows = (report, key) => (report?.[key] || []).map((metric) => [metric.label, formatNumber(metric.value, metric.decimals || 0)]);

  async function imageData(url) {
    if (!url) return "";
    try {
      const response = await fetch(url, { credentials: "same-origin" });
      if (!response.ok) return "";
      const blob = await response.blob();
      return await new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result || ""));
        reader.onerror = () => resolve("");
        reader.readAsDataURL(blob);
      });
    } catch (_) { return ""; }
  }

  const chartImage = (chart, compact = false) => window.cliniqSystemReportCharts.imageFor(chart, { width: compact ? 720 : 960, height: 360 });

  function chartRows(chart) { return (chart.rows || []).map((row) => [String(row.label || "Not specified"), Number(row.value) || 0]); }
  function chartHasData(chart) { return chartRows(chart).some(([, value]) => value !== 0); }
  function chartPresentation(chart) {
    if (chart?.presentation) return chart.presentation;
    return chartHasData(chart) ? "diagram" : "empty";
  }
  function chartGroups(section) {
    const source = section.charts || [];
    return {
      charts: source.filter((chart) => chartPresentation(chart) === "diagram"),
      summaries: source.filter((chart) => chartPresentation(chart) === "summary"),
      empty: source.filter((chart) => chartPresentation(chart) === "empty"),
      source,
    };
  }
  function emptyChartMessage(chart) { return chart.empty || "No data is available for this reporting period."; }
  function emptyChartsMessage(charts) { return charts.length === 1 ? emptyChartMessage(charts[0]) : "No chart data was available for this section during the selected reporting period."; }
  function omittedChartsMessage(charts) { return `No data: ${charts.map((chart) => chart.title || "Untitled measure").join("; ")}.`; }
  function chartInsight(chart) {
    if (chart?.insight) return chart.insight;
    const rows = chartRows(chart); if (!rows.length) return chart.empty || "No data is available for this reporting period.";
    const total = rows.reduce((sum, [, value]) => sum + value, 0); const [label, value] = [...rows].sort((a, b) => b[1] - a[1])[0];
    return `${rows.length} categor${rows.length === 1 ? "y" : "ies"} are represented, totaling ${formatNumber(total, chart.decimals || 0)}. The highest recorded category is ${label} with ${formatNumber(value, chart.decimals || 0)}.`;
  }

  async function payload(form) {
    if (form.__systemReportPayload) return form.__systemReportPayload;
    const response = await fetch("export-data.php", { method: "POST", credentials: "same-origin", body: new FormData(form) });
    if (!response.ok) {
      const message = await response.text();
      if (/form has expired|could not be verified/i.test(message)) throw new Error("Your report session expired. Refresh the page and try again.");
      throw new Error("Unable to prepare the report export.");
    }
    return response.json();
  }

  async function buildPdf(data) {
    const { jsPDF } = jspdf;
    const autoTable = window.jspdfAutoTable || ((doc, options) => doc.autoTable(options));
    const report = data.report; const branding = data.branding; const sections = sectionsFor(report);
    const [plpLogo, customLogo] = await Promise.all([imageData(branding.plp_logo_url), imageData(branding.custom_logo_url)]);
    const doc = new jsPDF({ unit: "mm", format: "a4" });
    const green = [62, 124, 69]; const paleGreen = [237, 246, 237];
    const addHeader = (subtitle = "") => {
      const width = doc.internal.pageSize.getWidth();
      if (plpLogo) doc.addImage(plpLogo, "PNG", 14, 10, 20, 20);
      if (customLogo) doc.addImage(customLogo, "PNG", width - 34, 10, 20, 20);
      doc.setTextColor(...green); doc.setFont("helvetica", "bold"); doc.setFontSize(12);
      doc.text(doc.splitTextToSize(branding.institution_name, 120), width / 2, 17, { align: "center" });
      doc.setFontSize(9); doc.text(branding.department, width / 2, 28, { align: "center" });
      doc.setDrawColor(...green); doc.setLineWidth(.7); doc.line(14, 37, width - 14, 37);
      doc.setFontSize(15); doc.text("Clinic Transaction Summary", width / 2, 49, { align: "center" });
      doc.setTextColor(71, 85, 105); doc.setFont("helvetica", "normal"); doc.setFontSize(8);
      doc.text(`Reporting period: ${dateLabel(report.date_from)} - ${dateLabel(report.date_to)}`, 14, 60);
      doc.text(`${sections.length} transaction groups`, width - 14, 60, { align: "right" });
      if (subtitle) { doc.setTextColor(...green); doc.setFont("helvetica", "bold"); doc.setFontSize(11); doc.text(subtitle, 14, 72); doc.setDrawColor(207, 222, 211); doc.line(14, 75, width - 14, 75); }
      return subtitle ? 83 : 69;
    };
    const tableOptions = (y, head, body) => ({ startY: y, head: [head], body, theme: "grid", styles: { fontSize: 7.5, cellPadding: 2, lineColor: [183, 209, 194], lineWidth: .15 }, headStyles: { fillColor: [237, 246, 237], textColor: green, fontStyle: "bold" }, alternateRowStyles: { fillColor: [250, 252, 250] }, margin: { left: 14, right: 14 } });
    const gap = { block: 8, caption: 2, note: 7 };
    let y = addHeader();
    const nextPage = (title) => { doc.addPage(); doc.setTextColor(...green); doc.setFont("helvetica", "bold"); doc.setFontSize(10); doc.text(title, 14, 19); doc.setDrawColor(207, 222, 211); doc.line(14, 23, 196, 23); return 30; };
    const ensureSpace = (height, title) => { if (y + height > 278) y = nextPage(title); };
    const wrapRemarkParagraph = (paragraph) => {
      const lines = []; let line = "";
      paragraph.trim().split(/\s+/).forEach((word) => {
        const candidate = line ? `${line} ${word}` : word;
        const width = lines.length ? 160 : 155;
        if (line && doc.getTextWidth(candidate) > width) { lines.push(line); line = word; }
        else line = candidate;
      });
      if (line) lines.push(line);
      return lines;
    };
    const transactionRows = summaryRows(report, "transaction_summary");
    const attentionRows = summaryRows(report, "attention_summary");
    if (transactionRows.length) {
      doc.setTextColor(...green); doc.setFont("helvetica", "bold"); doc.setFontSize(10); doc.text("TRANSACTION SUMMARY", 14, y); y += 6;
      autoTable(doc, { ...tableOptions(y, ["Transaction", "Count"], transactionRows), columnStyles: { 1: { halign: "right" } } });
      y = (doc.lastAutoTable?.finalY || y) + 7;
    }
    if (attentionRows.length) {
      doc.setTextColor(...green); doc.setFont("helvetica", "bold"); doc.setFontSize(10); doc.text("ATTENTION SUMMARY", 14, y); y += 6;
      autoTable(doc, { ...tableOptions(y, ["Needs follow-up", "Count"], attentionRows), columnStyles: { 1: { halign: "right" } } });
      y = (doc.lastAutoTable?.finalY || y) + 8;
    }
    for (const section of sections) {
      if (y > 235) y = nextPage(section.title);
      else { doc.setTextColor(...green); doc.setFont("helvetica", "bold"); doc.setFontSize(10); doc.text(section.title, 14, y); doc.setDrawColor(207, 222, 211); doc.line(14, y + 4, 196, y + 4); y += 11; }
      doc.setFont("helvetica", "normal"); doc.setTextColor(71, 85, 105); doc.setFontSize(8); const description = doc.splitTextToSize(section.description || "", 180); doc.text(description, 14, y); y += description.length * 4 + gap.block;
      const metricRows = (section.metrics || []).map((metric) => [metric.label, formatNumber(metric.value, metric.decimals || 0)]);
      if (metricRows.length) { ensureSpace(12 + metricRows.length * 5, section.title); autoTable(doc, tableOptions(y, ["Metric", "Value"], metricRows)); y = (doc.lastAutoTable?.finalY || y) + gap.block; }
      const { charts, summaries, empty } = chartGroups(section);
      if (!charts.length && !summaries.length && empty.length) {
        ensureSpace(10, section.title); doc.setTextColor(100, 116, 139); doc.setFont("helvetica", "italic"); doc.setFontSize(7.5); doc.text(emptyChartsMessage(empty), 14, y); doc.setFont("helvetica", "normal"); y += gap.note;
      }
      summaries.forEach((chart) => {
        ensureSpace(11, section.title); doc.setTextColor(...green); doc.setFont("helvetica", "bold"); doc.setFontSize(8); doc.text(chart.title || "Transaction summary", 14, y); y += 4;
        doc.setTextColor(71, 85, 105); doc.setFont("helvetica", "normal"); doc.setFontSize(7); const summary = doc.splitTextToSize(chartInsight(chart), 180); doc.text(summary, 14, y); y += summary.length * 3.2 + gap.note;
      });
      const chartPanel = charts.length === 1 ? { width: 96, height: 59, gap: 7 } : { width: 56, height: 39, gap: 7 };
      const chartDetails = await Promise.all(charts.map(async (chart) => ({ chart, rows: chartRows(chart).map(([label, value]) => [label, formatNumber(value, chart.decimals || 0)]), image: await chartImage(chart, true) })));
      for (let start = 0; start < chartDetails.length; start += 3) {
        const row = chartDetails.slice(start, start + 3);
        const rowWidth = row.length * chartPanel.width + (row.length - 1) * chartPanel.gap;
        const startX = 14 + (180 - rowWidth) / 2;
        ensureSpace(chartPanel.height + gap.block, section.title);
        row.forEach(({ chart, image }, index) => {
          const x = startX + index * (chartPanel.width + chartPanel.gap);
          const title = doc.splitTextToSize(chart.title || "Report diagram", chartPanel.width - 6).slice(0, 2);
          doc.setDrawColor(183, 209, 194); doc.setLineWidth(.25); doc.roundedRect(x, y, chartPanel.width, chartPanel.height, 1.5, 1.5, "S");
          doc.setTextColor(...green); doc.setFont("helvetica", "bold"); doc.setFontSize(6.5); doc.text(title, x + 3, y + 4);
          const imageHeight = chartPanel.height - 18;
          doc.addImage(image, "PNG", x + 3, y + 10, chartPanel.width - 6, imageHeight);
          doc.setTextColor(100, 116, 139); doc.setFont("helvetica", "italic"); doc.setFontSize(5.5); doc.text("Figure", x + chartPanel.width / 2, y + chartPanel.height - 5, { align: "center" });
        });
        y += chartPanel.height + gap.block;
      }
      chartDetails.filter(({ chart }) => chart.detail_in_pdf !== false).forEach(({ chart, rows }) => {
        ensureSpace(18 + Math.min(rows.length, 8) * 5, section.title); doc.setTextColor(...green); doc.setFont("helvetica", "bold"); doc.setFontSize(8); doc.text(chart.title || "Report diagram", 14, y); y += 4;
        doc.setTextColor(71, 85, 105); doc.setFont("helvetica", "normal"); doc.setFontSize(7); const insight = doc.splitTextToSize(chartInsight(chart), 180); doc.text(insight, 14, y); y += insight.length * 3.2 + gap.caption + 1;
        autoTable(doc, tableOptions(y, ["Category", "Value"], rows)); y = (doc.lastAutoTable?.finalY || y) + gap.caption + 3; doc.setDrawColor(207, 222, 211); doc.line(14, y, 196, y); y += gap.block;
      });
      if ((charts.length || summaries.length) && empty.length) { ensureSpace(10, section.title); doc.setTextColor(100, 116, 139); doc.setFont("helvetica", "italic"); doc.setFontSize(7.5); const omitted = doc.splitTextToSize(omittedChartsMessage(empty), 180); doc.text(omitted, 14, y); doc.setFont("helvetica", "normal"); y += omitted.length * 3.5 + gap.note; }
      (section.tables || []).forEach((table) => { const rows = chartRows(table); ensureSpace(rows.length ? 18 + Math.min(rows.length, 12) * 5 : 12, section.title); doc.setTextColor(...green); doc.setFont("helvetica", "bold"); doc.setFontSize(9); doc.text(table.title || "Transaction detail", 14, y); y += 5; if (rows.length) { autoTable(doc, tableOptions(y, ["Category", "Value"], rows.map(([label, value]) => [label, formatNumber(value, table.decimals || 0)]))); y = (doc.lastAutoTable?.finalY || y) + gap.block; } else { doc.setTextColor(100, 116, 139); doc.setFont("helvetica", "italic"); doc.setFontSize(7.5); doc.text(emptyChartMessage(table), 14, y); y += gap.note; } });
      const remark = data.remarks?.[section.key];
      if (remark) { const remarkLineHeight = 4.5; const paragraphGap = 2.5; doc.setFont("helvetica", "normal"); doc.setFontSize(8); const remarkParagraphs = String(remark).split(/\r?\n\s*\r?\n/).map(wrapRemarkParagraph).filter((lines) => lines.length); const remarkHeight = 15 + remarkParagraphs.reduce((height, lines) => height + lines.length * remarkLineHeight, 0) + Math.max(0, remarkParagraphs.length - 1) * paragraphGap; ensureSpace(remarkHeight + gap.block, section.title); doc.setFillColor(...paleGreen); doc.rect(14, y, 180, remarkHeight, "F"); doc.setDrawColor(...green); doc.setLineWidth(.35); doc.rect(14, y, 180, remarkHeight, "S"); doc.setFont("helvetica", "bold"); doc.setFontSize(8); doc.setTextColor(...green); doc.text("Remarks", 20, y + 5); doc.setFont("helvetica", "normal"); doc.setTextColor(71, 85, 105); let textY = y + 11; remarkParagraphs.forEach((lines, paragraphIndex) => { lines.forEach((line, lineIndex) => { doc.text(line, lineIndex ? 20 : 25, textY); textY += remarkLineHeight; }); if (paragraphIndex < remarkParagraphs.length - 1) textY += paragraphGap; }); y += remarkHeight + gap.block; }
    }
    const pages = doc.getNumberOfPages();
    for (let page = 1; page <= pages; page++) { doc.setPage(page); doc.setDrawColor(...green); doc.setLineWidth(.4); doc.line(14, 286, 196, 286); doc.setTextColor(100, 116, 139); doc.setFontSize(7); doc.text(`${branding.system_name} - Generated ${report.generated_at}`, 14, 291); doc.text(`Page ${page} of ${pages}`, 196, 291, { align: "right" }); }
    return doc;
  }

  async function pdfExport(data, doc = null) {
    doc = doc || await buildPdf(data);
    doc.save(`${fileStem(data.report)}.pdf`);
  }

  async function renderPdfPages(doc, host) {
    const pdfjs = window.pdfjsLib;
    if (!pdfjs) throw new Error("The PDF preview renderer is unavailable.");
    pdfjs.GlobalWorkerOptions.workerSrc = window.cliniqReportPdfWorkerUrl || "";
    const pdf = await pdfjs.getDocument({ data: new Uint8Array(doc.output("arraybuffer")) }).promise;
    host.replaceChildren();
    for (let number = 1; number <= pdf.numPages; number++) {
      const page = await pdf.getPage(number);
      const viewport = page.getViewport({ scale: 1.25 });
      const canvas = document.createElement("canvas");
      canvas.width = Math.ceil(viewport.width); canvas.height = Math.ceil(viewport.height);
      canvas.setAttribute("aria-label", `Report page ${number}`);
      const paper = document.createElement("article");
      paper.className = "report-preview-page";
      paper.append(canvas); host.append(paper);
      await page.render({ canvasContext: canvas.getContext("2d"), viewport }).promise;
    }
    return pdf.numPages;
  }

  async function previewPdf(form, host) {
    const data = await payload(form);
    form.__systemReportPayload = data;
    const doc = await buildPdf(data);
    form.__systemReportPdf = doc;
    return { data, pages: await renderPdfPages(doc, host) };
  }

  function merge(sheet, row, from, to) { if (to > from) sheet.mergeCells(row, from, row, to); }
  function addHeader(sheet, branding, logoIds, title, subtitle, columns = 6) {
    for (let row = 1; row <= 6; row++) merge(sheet, row, 1, columns);
    sheet.getCell(1, 1).value = branding.institution_name; sheet.getCell(1, 1).font = { bold: true, size: 15, color: { argb: "FF3E7C45" } }; sheet.getCell(1, 1).alignment = { horizontal: "center" };
    sheet.getCell(2, 1).value = branding.department; sheet.getCell(2, 1).font = { bold: true, size: 10, color: { argb: "FF3E7C45" } }; sheet.getCell(2, 1).alignment = { horizontal: "center" };
    sheet.getCell(3, 1).border = { bottom: { style: "medium", color: { argb: "FF3E7C45" } } };
    sheet.getCell(4, 1).value = title; sheet.getCell(4, 1).font = { bold: true, size: 14, color: { argb: "FF3E7C45" } };
    sheet.getCell(5, 1).value = subtitle; sheet.getCell(5, 1).font = { size: 9, color: { argb: "FF526155" } };
    sheet.getCell(6, 1).border = { bottom: { style: "thin", color: { argb: "FF3E7C45" } } };
    sheet.getRow(1).height = 24; sheet.getRow(2).height = 18; sheet.getRow(4).height = 24;
    if (logoIds.plp) sheet.addImage(logoIds.plp, { tl: { col: 0.1, row: 0.1 }, ext: { width: 42, height: 42 } });
    if (logoIds.custom) sheet.addImage(logoIds.custom, { tl: { col: Math.max(columns - 1, 1) + 0.1, row: 0.1 }, ext: { width: 42, height: 42 } });
  }

  async function xlsxExport(data) {
    const report = data.report; const branding = data.branding; const sections = sectionsFor(report); const workbook = new ExcelJS.Workbook();
    workbook.creator = branding.system_name; workbook.created = new Date();
    const [plpLogo, customLogo] = await Promise.all([imageData(branding.plp_logo_url), imageData(branding.custom_logo_url)]);
    const logoIds = { plp: plpLogo ? workbook.addImage({ base64: plpLogo.split(",")[1], extension: "png" }) : 0, custom: customLogo ? workbook.addImage({ base64: customLogo.split(",")[1], extension: "png" }) : 0 };
    const overview = workbook.addWorksheet("Transaction Summary", { views: [{ showGridLines: false, state: "frozen", ySplit: 8 }] });
    addHeader(overview, branding, logoIds, "CLINIC TRANSACTION SUMMARY", `Reporting period: ${dateLabel(report.date_from)} - ${dateLabel(report.date_to)} | Generated: ${generatedLabel(report.generated_at)}`, 3);
    let row = 8;
    const addSummaryTable = (title, rows) => { overview.mergeCells(row, 1, row, 2); overview.getCell(row, 1).value = title; overview.getCell(row, 1).font = { bold: true, color: { argb: "FF205F3D" } }; row++; const header = overview.getRow(row); header.values = [title === "ATTENTION SUMMARY" ? "Needs follow-up" : "Transaction", "Count"]; [1, 2].forEach((column) => { header.getCell(column).font = { bold: true, color: { argb: "FFFFFFFF" } }; header.getCell(column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FF3E7C45" } }; }); row++; rows.forEach(([label, value], index) => { const record = overview.getRow(row++); record.values = [label, Number(value.replaceAll(",", "")) || 0]; record.getCell(2).numFmt = "#,##0.##"; if (index % 2 === 0) [1, 2].forEach((column) => { record.getCell(column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFEDF6ED" } }; }); }); row += 2; };
    addSummaryTable("TRANSACTION SUMMARY", summaryRows(report, "transaction_summary"));
    addSummaryTable("ATTENTION SUMMARY", summaryRows(report, "attention_summary"));
    overview.columns = [{ width: 42 }, { width: 18 }, { width: 18 }];
    overview.autoFilter = { from: { row: 9, column: 1 }, to: { row: Math.max(9, row - 3), column: 2 } };
    overview.pageSetup = { orientation: "landscape", fitToWidth: 1, fitToHeight: 1 };
    overview.pageSetup.printArea = `A1:D${row - 1}`;
    for (const [sectionIndex, section] of sections.entries()) {
      const sheet = workbook.addWorksheet((section.title || `Module ${sectionIndex + 1}`).slice(0, 31), { views: [{ state: "frozen", ySplit: 8 }] });
      addHeader(sheet, branding, logoIds, `CLINIC TRANSACTION SUMMARY - ${section.title}`, section.description || "", 3);
      let row = 8;
      const addDataTable = (title, chart) => {
        const rows = chartRows(chart); row += 1; sheet.mergeCells(row, 1, row, 2); sheet.getCell(row, 1).value = title; sheet.getCell(row, 1).font = { bold: true, color: { argb: "FF205F3D" } }; row++;
        if (!rows.length) { sheet.mergeCells(row, 1, row, 2); sheet.getCell(row, 1).value = emptyChartMessage(chart); sheet.getCell(row, 1).font = { italic: true, color: { argb: "FF64748B" } }; row += 2; return; }
        const header = sheet.addRow(["Category", "Value"]); [1, 2].forEach((column) => { header.getCell(column).font = { bold: true, color: { argb: "FFFFFFFF" } }; header.getCell(column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FF3E7C45" } }; });
        rows.forEach(([label, value], index) => { const record = sheet.addRow([label, value]); record.getCell(2).numFmt = chart.decimals ? "0.00" : "#,##0"; if (index % 2 === 0) [1, 2].forEach((column) => { record.getCell(column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFEDF6ED" } }; }); }); row += rows.length + 1;
      };
      sheet.addRow(["Metric", "Value"]); [1, 2].forEach((column) => { sheet.getRow(row).getCell(column).font = { bold: true, color: { argb: "FFFFFFFF" } }; sheet.getRow(row).getCell(column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FF174D32" } }; }); row++;
      (section.metrics || []).forEach((metric, index) => { const record = sheet.addRow([metric.label, Number(metric.value) || 0]); record.getCell(2).numFmt = metric.decimals ? "0.00" : "#,##0"; if (index % 2 === 0) [1, 2].forEach((column) => { record.getCell(column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFEDF6ED" } }; }); row++; });
      const { charts, summaries, empty } = chartGroups(section);
      for (const chart of charts) { row += 2; sheet.mergeCells(row, 1, row, 3); sheet.getCell(row, 1).value = chart.title; sheet.getCell(row, 1).font = { bold: true, color: { argb: "FF205F3D" } }; row++; sheet.addImage(workbook.addImage({ base64: (await chartImage(chart)).split(",")[1], extension: "png" }), { tl: { col: 0, row: row - 1 }, ext: { width: 500, height: 160 } }); row += 10; sheet.mergeCells(row, 1, row, 3); sheet.getCell(row, 1).value = `Diagram summary: ${chartInsight(chart)}`; sheet.getCell(row, 1).alignment = { wrapText: true }; row += 1; addDataTable(`${chart.title} data`, chart); }
      [...summaries, ...empty].forEach((chart) => { row += 1; sheet.mergeCells(row, 1, row, 3); sheet.getCell(row, 1).value = chart.presentation === "empty" ? emptyChartMessage(chart) : chartInsight(chart); sheet.getCell(row, 1).font = { italic: true, color: { argb: "FF64748B" } }; sheet.getCell(row, 1).alignment = { wrapText: true }; row++; addDataTable(`${chart.title} data`, chart); });
      (section.tables || []).forEach((table) => addDataTable(table.title || "Transaction detail", table));
      const remark = data.remarks?.[section.key]; if (remark) { const outline = { style: "thin", color: { argb: "FF3E7C45" } }; row++; sheet.mergeCells(row, 1, row, 3); [1, 2, 3].forEach((column) => { sheet.getCell(row, column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFEDF6ED" } }; }); sheet.getCell(row, 1).value = "Remarks"; sheet.getCell(row, 1).font = { bold: true, color: { argb: "FF205F3D" } }; sheet.getCell(row, 1).border = { top: outline, left: outline, right: outline }; sheet.getRow(row).height = 18; row++; sheet.mergeCells(row, 1, row, 3); [1, 2, 3].forEach((column) => { sheet.getCell(row, column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFEDF6ED" } }; }); sheet.getCell(row, 1).value = remark; sheet.getCell(row, 1).alignment = { wrapText: true, vertical: "top" }; sheet.getCell(row, 1).border = { bottom: outline, left: outline, right: outline }; sheet.getRow(row).height = Math.max(30, Math.ceil(String(remark).length / 72) * 15); row += 2; }
      sheet.columns = [{ width: 38 }, { width: 18 }, { width: 18 }];
      sheet.autoFilter = { from: { row: 8, column: 1 }, to: { row: 8 + (section.metrics || []).length, column: 2 } };
      sheet.pageSetup = { orientation: "portrait", fitToWidth: 1, fitToHeight: 0, printArea: `A1:C${Math.max(8, row - 1)}` };
    }
    const buffer = await workbook.xlsx.writeBuffer(); const url = URL.createObjectURL(new Blob([buffer], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" })); const anchor = document.createElement("a"); anchor.href = url; anchor.download = `${fileStem(report)}.xlsx`; anchor.click(); setTimeout(() => URL.revokeObjectURL(url), 1500);
  }

  document.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-report-export-format]"); if (!button) return;
    if (button.dataset.reportExportFormat !== "xlsx") return;
    const form = button.closest("form"); if (!form) return;
    event.preventDefault();
    const original = button.textContent; button.disabled = true; button.textContent = "Preparing…";
    try { await xlsxExport(await payload(form)); } catch (error) { window.alert(error instanceof Error ? error.message : "Unable to export the report."); } finally { button.disabled = false; button.textContent = original; }
  });

  window.cliniqSystemReportExport = { previewPdf };
})();
