/* global ExcelJS, jspdf, jspdfAutoTable */
(() => {
  const COLORS = ["#205f3d", "#4b936a", "#86bd98", "#d09a28", "#5377b8", "#8b69c7", "#d26b6b", "#64748b"];
  const $ = (selector, root = document) => root.querySelector(selector);
  const formatNumber = (value, decimals = 0) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
  const dateLabel = (value) => new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
  const generatedLabel = (value) => new Date(value.replace(" ", "T")).toLocaleString();
  const fileStem = (report) => `CLINiQ-System-Analytics-${report.date_from}-to-${report.date_to}`;
  const sectionsFor = (report) => Array.isArray(report?.sections)
    ? report.sections.map((section, index) => ({ ...section, key: section.key || String(index) }))
    : Object.entries(report?.sections || {}).map(([key, section]) => ({ ...section, key }));

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

  function chartImage(chart) {
    const rows = Array.isArray(chart.rows) ? chart.rows : [];
    const width = 960;
    const height = 360;
    const canvas = document.createElement("canvas");
    canvas.width = width; canvas.height = height;
    const ctx = canvas.getContext("2d");
    ctx.fillStyle = "#ffffff"; ctx.fillRect(0, 0, width, height);
    const visible = rows.slice(0, 8);
    const max = Math.max(1, ...visible.map((row) => Number(row.value) || 0));
    const chartLeft = 74; const chartRight = 900; const chartTop = 32; const chartBottom = 274;
    for (let tick = 0; tick <= 4; tick++) { const value = max * tick / 4; const y = chartBottom - (chartBottom - chartTop) * tick / 4; ctx.strokeStyle = "#e7efe9"; ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(chartLeft, y); ctx.lineTo(chartRight, y); ctx.stroke(); ctx.fillStyle = "#718096"; ctx.font = "13px Arial"; ctx.fillText(formatNumber(value, chart.decimals || 0), 20, y + 4); }
    ctx.strokeStyle = "#8ba696"; ctx.lineWidth = 2; ctx.beginPath(); ctx.moveTo(chartLeft, chartTop); ctx.lineTo(chartLeft, chartBottom); ctx.lineTo(chartRight, chartBottom); ctx.stroke();
    const slot = (chartRight - chartLeft) / visible.length; const barWidth = Math.min(64, slot * .56);
    visible.forEach((row, index) => {
      const value = Number(row.value) || 0; const barHeight = value / max * (chartBottom - chartTop); const x = chartLeft + index * slot + (slot - barWidth) / 2; const y = chartBottom - barHeight; const label = String(row.label || "Not specified");
      ctx.fillStyle = COLORS[index % COLORS.length]; ctx.fillRect(x, y, barWidth, Math.max(2, barHeight)); ctx.strokeStyle = "#ffffff"; ctx.lineWidth = 1; ctx.strokeRect(x, y, barWidth, Math.max(2, barHeight));
      ctx.fillStyle = "#17261d"; ctx.font = "700 13px Arial"; ctx.textAlign = "center"; ctx.fillText(formatNumber(value, chart.decimals || 0), x + barWidth / 2, y - 7);
      ctx.fillStyle = "#475569"; ctx.font = "600 12px Arial"; const shortLabel = label.length > 14 ? `${label.slice(0, 12)}...` : label; ctx.fillText(shortLabel, x + barWidth / 2, chartBottom + 20); ctx.textAlign = "left";
    });
    if (rows.length > visible.length) { ctx.fillStyle = "#64748b"; ctx.font = "italic 13px Arial"; ctx.fillText(`Diagram shows the top ${visible.length} categories. The source table lists all ${rows.length}.`, 34, 330); }
    return canvas.toDataURL("image/png");
  }

  function chartRows(chart) { return (chart.rows || []).map((row) => [String(row.label || "Not specified"), Number(row.value) || 0]); }
  function chartHasData(chart) { return chartRows(chart).some(([, value]) => value !== 0); }
  function chartGroups(section) { const charts = section.charts || []; return { charts: charts.filter(chartHasData), empty: charts.filter((chart) => !chartHasData(chart)) }; }
  function emptyChartMessage(chart) { return chart.empty || "No data is available for this reporting period."; }
  function emptyChartsMessage(charts) { return charts.length === 1 ? emptyChartMessage(charts[0]) : "No chart data was available for this section during the selected reporting period."; }
  function omittedChartsMessage(charts) { return `No data: ${charts.map((chart) => chart.title || "Untitled measure").join("; ")}.`; }
  function chartInsight(chart) {
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
      doc.setFontSize(15); doc.text("System Analytics Report", width / 2, 49, { align: "center" });
      doc.setTextColor(71, 85, 105); doc.setFont("helvetica", "normal"); doc.setFontSize(8);
      doc.text(`Reporting period: ${dateLabel(report.date_from)} - ${dateLabel(report.date_to)}`, 14, 60);
      doc.text(`Modules included: ${sections.length}`, width - 14, 60, { align: "right" });
      if (subtitle) { doc.setTextColor(...green); doc.setFont("helvetica", "bold"); doc.setFontSize(11); doc.text(subtitle, 14, 72); doc.setDrawColor(207, 222, 211); doc.line(14, 75, width - 14, 75); }
      return subtitle ? 83 : 69;
    };
    const tableOptions = (y, head, body) => ({ startY: y, head: [head], body, theme: "grid", styles: { fontSize: 7.5, cellPadding: 2, lineColor: [62, 124, 69], lineWidth: .15 }, headStyles: { fillColor: green, textColor: 255, fontStyle: "bold" }, alternateRowStyles: { fillColor: paleGreen }, margin: { left: 14, right: 14 } });
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
    const highlights = sections.flatMap((section) => (section.metrics || []).map((metric) => ({ ...metric, section: section.title }))).slice(0, 4);
    if (highlights.length) {
      doc.setTextColor(...green); doc.setFont("helvetica", "bold"); doc.setFontSize(10); doc.text("REPORT OVERVIEW", 14, y); y += 6;
      highlights.forEach((metric, index) => { const x = 14 + index * 45; doc.setFillColor(...paleGreen); doc.setDrawColor(194, 217, 198); doc.roundedRect(x, y, 42, 25, 3, 3, "FD"); doc.setFillColor(...COLORS[index % COLORS.length].match(/\w\w/g).map((hex) => parseInt(hex, 16))); doc.rect(x, y, 3, 25, "F"); doc.setTextColor(...green); doc.setFontSize(6); doc.text(String(metric.label).toUpperCase(), x + 6, y + 7, { maxWidth: 33 }); doc.setTextColor(23, 38, 29); doc.setFontSize(12); doc.text(formatNumber(metric.value, metric.decimals || 0), x + 6, y + 15); doc.setTextColor(100, 116, 139); doc.setFont("helvetica", "normal"); doc.setFontSize(5.5); doc.text(metric.section, x + 6, y + 21, { maxWidth: 33 }); doc.setFont("helvetica", "bold"); });
      y += 35; doc.setFillColor(...paleGreen); doc.roundedRect(14, y, 180, 23, 3, 3, "F"); doc.setFillColor(...green); doc.rect(14, y, 3, 23, "F"); doc.setTextColor(...green); doc.setFontSize(8); doc.text("Report scope", 20, y + 7); doc.setTextColor(71, 85, 105); doc.setFont("helvetica", "normal"); doc.setFontSize(7.5); doc.text(doc.splitTextToSize(`This portrait report contains ${sections.length} selected module(s). Measures with data include a diagram and category table.`, 165), 20, y + 13); doc.setFont("helvetica", "bold"); y += 30;
    }
    sections.forEach((section, sectionIndex) => {
      if (sectionIndex) y = nextPage(section.title);
      else { doc.setTextColor(...green); doc.setFont("helvetica", "bold"); doc.setFontSize(11); doc.text(section.title, 14, y); doc.setDrawColor(207, 222, 211); doc.line(14, y + 3, 196, y + 3); y += 10; }
      doc.setFont("helvetica", "normal"); doc.setTextColor(71, 85, 105); doc.setFontSize(8); const description = doc.splitTextToSize(section.description || "", 180); doc.text(description, 14, y); y += description.length * 4 + gap.block;
      const metricRows = (section.metrics || []).map((metric) => [metric.label, formatNumber(metric.value, metric.decimals || 0)]);
      if (metricRows.length) { ensureSpace(12 + metricRows.length * 5, section.title); autoTable(doc, tableOptions(y, ["Metric", "Value"], metricRows)); y = (doc.lastAutoTable?.finalY || y) + gap.block; }
      const { charts, empty } = chartGroups(section);
      if (!charts.length && empty.length) {
        ensureSpace(10, section.title); doc.setTextColor(100, 116, 139); doc.setFont("helvetica", "italic"); doc.setFontSize(7.5); doc.text(emptyChartsMessage(empty), 14, y); doc.setFont("helvetica", "normal"); y += gap.note;
      }
      charts.forEach((chart) => {
        const chartRowsData = chartRows(chart);
        const rows = chartRowsData.map(([label, value]) => [label, formatNumber(value, chart.decimals || 0)]);
        ensureSpace(96 + Math.min(rows.length, 8) * 5, section.title); doc.setTextColor(...green); doc.setFont("helvetica", "bold"); doc.setFontSize(9); doc.text(chart.title || "Report diagram", 14, y); y += 4;
        const chartData = chartImage(chart); const chartHeight = 67; doc.addImage(chartData, "PNG", 14, y, 180, chartHeight); y += chartHeight + gap.caption;
        doc.setTextColor(100, 116, 139); doc.setFont("helvetica", "italic"); doc.setFontSize(7); doc.text(`Figure: ${chart.title || "Report diagram"}`, 104, y, { align: "center" }); y += gap.note - gap.caption;
        doc.setTextColor(71, 85, 105); doc.setFont("helvetica", "normal"); doc.setFontSize(7); const insight = doc.splitTextToSize(chartInsight(chart), 180); doc.text(insight, 14, y); y += insight.length * 3.2 + gap.caption + 1;
        autoTable(doc, tableOptions(y, ["Category", "Value"], rows)); y = (doc.lastAutoTable?.finalY || y) + gap.caption + 3; doc.setDrawColor(207, 222, 211); doc.line(14, y, 196, y); y += gap.block;
      });
      if (charts.length && empty.length) { ensureSpace(10, section.title); doc.setTextColor(100, 116, 139); doc.setFont("helvetica", "italic"); doc.setFontSize(7.5); const omitted = doc.splitTextToSize(omittedChartsMessage(empty), 180); doc.text(omitted, 14, y); doc.setFont("helvetica", "normal"); y += omitted.length * 3.5 + gap.note; }
      const remark = data.remarks?.[section.key];
      if (remark) { const remarkLineHeight = 4.5; const paragraphGap = 2.5; doc.setFont("helvetica", "normal"); doc.setFontSize(8); const remarkParagraphs = String(remark).split(/\r?\n\s*\r?\n/).map(wrapRemarkParagraph).filter((lines) => lines.length); const remarkHeight = 15 + remarkParagraphs.reduce((height, lines) => height + lines.length * remarkLineHeight, 0) + Math.max(0, remarkParagraphs.length - 1) * paragraphGap; ensureSpace(remarkHeight + gap.block, section.title); doc.setFillColor(...paleGreen); doc.rect(14, y, 180, remarkHeight, "F"); doc.setDrawColor(...green); doc.setLineWidth(.35); doc.rect(14, y, 180, remarkHeight, "S"); doc.setFont("helvetica", "bold"); doc.setFontSize(8); doc.setTextColor(...green); doc.text("Remarks", 20, y + 5); doc.setFont("helvetica", "normal"); doc.setTextColor(71, 85, 105); let textY = y + 11; remarkParagraphs.forEach((lines, paragraphIndex) => { lines.forEach((line, lineIndex) => { doc.text(line, lineIndex ? 20 : 25, textY); textY += remarkLineHeight; }); if (paragraphIndex < remarkParagraphs.length - 1) textY += paragraphGap; }); y += remarkHeight + gap.block; }
    });
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
    const overview = workbook.addWorksheet("Overview", { views: [{ showGridLines: false, state: "frozen", ySplit: 8 }] });
    addHeader(overview, branding, logoIds, "SYSTEM ANALYTICS REPORT - OVERVIEW", `Reporting period: ${dateLabel(report.date_from)} - ${dateLabel(report.date_to)} | Generated: ${generatedLabel(report.generated_at)}`, 3);
    let row = 8;
    const overviewHeader = overview.getRow(row); overviewHeader.values = ["Module", "Metric", "Value"]; [1, 2, 3].forEach((column) => { overviewHeader.getCell(column).font = { bold: true, color: { argb: "FFFFFFFF" } }; overviewHeader.getCell(column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FF3E7C45" } }; }); row++;
    sections.forEach((section) => (section.metrics || []).forEach((metric, index) => { const record = overview.getRow(row++); record.values = [section.title, metric.label, Number(metric.value) || 0]; record.getCell(3).numFmt = metric.decimals ? "0.00" : "#,##0"; if (index % 2 === 0) [1, 2, 3].forEach((column) => { record.getCell(column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFEDF6ED" } }; }); }));
    overview.columns = [{ width: 28 }, { width: 38 }, { width: 18 }];
    overview.autoFilter = { from: { row: 8, column: 1 }, to: { row: row - 1, column: 3 } };
    sections.forEach((section, sectionIndex) => {
      const sheet = workbook.addWorksheet((section.title || `Module ${sectionIndex + 1}`).slice(0, 31), { views: [{ state: "frozen", ySplit: 8 }] });
      addHeader(sheet, branding, logoIds, `SYSTEM ANALYTICS REPORT - ${section.title}`, section.description || "", 3);
      let row = 8;
      sheet.addRow(["Metric", "Value"]); [1, 2].forEach((column) => { sheet.getRow(row).getCell(column).font = { bold: true, color: { argb: "FFFFFFFF" } }; sheet.getRow(row).getCell(column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FF174D32" } }; }); row++;
      (section.metrics || []).forEach((metric, index) => { const r = sheet.addRow([metric.label, Number(metric.value) || 0]); r.getCell(2).numFmt = metric.decimals ? "0.00" : "#,##0"; if (index % 2 === 0) [1, 2].forEach((column) => { r.getCell(column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFEDF6ED" } }; }); row++; });
      const { charts, empty } = chartGroups(section); if (!charts.length && empty.length) { row += 2; sheet.mergeCells(row, 1, row, 2); sheet.getCell(row, 1).value = emptyChartsMessage(empty); sheet.getCell(row, 1).font = { italic: true, color: { argb: "FF64748B" } }; row += 2; } charts.forEach((chart) => { row += 2; sheet.mergeCells(row, 1, row, 3); sheet.getCell(row, 1).value = chart.title; sheet.getCell(row, 1).font = { bold: true, color: { argb: "FF205F3D" } }; row++; const rows = chartRows(chart); sheet.addImage(workbook.addImage({ base64: chartImage(chart).split(",")[1], extension: "png" }), { tl: { col: 0, row: row - 1 }, ext: { width: 500, height: 160 } }); row += 10; sheet.mergeCells(row, 1, row, 3); sheet.getCell(row, 1).value = `Diagram summary: ${chartInsight(chart)}`; sheet.getCell(row, 1).alignment = { wrapText: true }; row += 2; const header = sheet.addRow(["Category", "Value"]); [1, 2].forEach((column) => { header.getCell(column).font = { bold: true, color: { argb: "FFFFFFFF" } }; header.getCell(column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FF3E7C45" } }; }); rows.forEach(([label, value], index) => { const r = sheet.addRow([label, value]); r.getCell(2).numFmt = chart.decimals ? "0.00" : "#,##0"; if (index % 2 === 0) [1, 2].forEach((column) => { r.getCell(column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFEDF6ED" } }; }); }); row += rows.length + 1; }); if (charts.length && empty.length) { sheet.mergeCells(row, 1, row, 2); sheet.getCell(row, 1).value = omittedChartsMessage(empty); sheet.getCell(row, 1).font = { italic: true, color: { argb: "FF64748B" } }; row += 2; } const remark = data.remarks?.[section.key]; if (remark) { const outline = { style: "thin", color: { argb: "FF3E7C45" } }; row++; sheet.mergeCells(row, 1, row, 3); [1, 2, 3].forEach((column) => { sheet.getCell(row, column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFEDF6ED" } }; }); sheet.getCell(row, 1).value = "Remarks"; sheet.getCell(row, 1).font = { bold: true, color: { argb: "FF205F3D" } }; sheet.getCell(row, 1).border = { top: outline, left: outline, right: outline }; sheet.getRow(row).height = 18; row++; sheet.mergeCells(row, 1, row, 3); [1, 2, 3].forEach((column) => { sheet.getCell(row, column).fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFEDF6ED" } }; }); sheet.getCell(row, 1).value = remark; sheet.getCell(row, 1).alignment = { wrapText: true, vertical: "top" }; sheet.getCell(row, 1).border = { bottom: outline, left: outline, right: outline }; sheet.getRow(row).height = Math.max(30, Math.ceil(String(remark).length / 72) * 15); row += 2; }
      sheet.columns = [{ width: 38 }, { width: 18 }, { width: 18 }];
      sheet.autoFilter = { from: { row: 8, column: 1 }, to: { row: 8 + (section.metrics || []).length, column: 2 } };
      sheet.pageSetup = { orientation: "portrait", fitToWidth: 1, fitToHeight: 0 };
    });
    const buffer = await workbook.xlsx.writeBuffer(); const url = URL.createObjectURL(new Blob([buffer], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" })); const anchor = document.createElement("a"); anchor.href = url; anchor.download = `${fileStem(report)}.xlsx`; anchor.click(); setTimeout(() => URL.revokeObjectURL(url), 1500);
  }

  document.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-report-export-format]"); if (!button) return;
    const form = button.closest("form"); if (!form) return;
    event.preventDefault();
    const selected = [...form.querySelectorAll('input[name="modules[]"]')].filter((input) => input.type === "hidden" || input.checked);
    if (!selected.length) { $("#reportModuleError", form)?.classList.remove("hidden"); return; }
    const original = button.textContent; button.disabled = true; button.textContent = "Preparing…";
    try { const data = await payload(form); if (button.dataset.reportExportFormat === "xlsx") await xlsxExport(data); else await pdfExport(data, form.__systemReportPdf); } catch (error) { window.alert(error instanceof Error ? error.message : "Unable to export the report."); } finally { button.disabled = false; button.textContent = original; }
  });

  window.cliniqSystemReportExport = { previewPdf };
})();
