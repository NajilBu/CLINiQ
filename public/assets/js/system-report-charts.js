/* global ApexCharts */
(() => {
  const palette = ["#2f8553", "#58a978", "#89c79f", "#d4a72c", "#5377b8", "#8b69c7", "#d26b6b", "#64748b"];

  const themeFor = (dark) => ({
    text: dark ? "#b3c9ba" : "#475569",
    value: dark ? "#9be3ae" : "#205f3d",
    grid: dark ? "#35523f" : "#edf3ef",
    axis: dark ? "#4b6d55" : "#dfe9e2",
    background: dark ? "#14271b" : "#ffffff",
  });

  const rowsFor = (chart) => Array.isArray(chart?.rows) ? chart.rows : [];
  const valuesFor = (rows) => rows.map((row) => Number(row.value) || 0);
  const labelsFor = (rows) => rows.map((row) => String(row.label ?? ""));

  const groupedPdfXAxis = (chart, labels) => {
    const hourLabels = labels.map((label) => label.match(/^(\d{1,2})\s+(AM|PM)$/));
    if (chart?.title === "Visits by Hour" && hourLabels.every(Boolean)) {
      const groups = [];
      hourLabels.forEach((match) => {
        const title = match[2];
        const previous = groups.at(-1);
        if (previous?.title === title) previous.cols += 1;
        else groups.push({ title, cols: 1 });
      });
      return { categories: hourLabels.map((match) => match[1]), groups };
    }

    const dateLabels = labels.map((label) => label.match(/^([A-Z][a-z]{2})\s+(\d{1,2})$/));
    if (!dateLabels.every(Boolean)) return null;
    const groups = [];
    dateLabels.forEach((match) => {
      const title = match[1];
      const previous = groups.at(-1);
      if (previous?.title === title) previous.cols += 1;
      else groups.push({ title, cols: 1 });
    });
    return { categories: dateLabels.map((match) => match[2]), groups };
  };

  const hideOverlappingPdfXAxisLabels = (host) => {
    let right = -Infinity;
    host.querySelectorAll(".apexcharts-xaxis-texts-g text").forEach((label) => {
      label.removeAttribute("visibility");
      const box = label.getBoundingClientRect();
      if (!box.width || !box.height) return;
      if (box.left < right + 2) label.setAttribute("visibility", "hidden");
      else right = box.right;
    });
  };

  const placeHorizontalBarLabels = (host, horizontal) => {
    if (!horizontal) return;
    const bars = Array.from(host.querySelectorAll(".apexcharts-bar-area"));
    const labels = Array.from(host.querySelectorAll("text.apexcharts-datalabel"));
    if (!bars.length || !labels.length) return;

    // ApexCharts ignores dataLabels.offsetX for bars, so use each rendered bar edge.
    bars.forEach((bar, index) => {
      const label = labels[index];
      if (!label) return;
      const box = bar.getBBox();
      if (!Number.isFinite(box.x) || !Number.isFinite(box.width)) return;
      label.setAttribute("text-anchor", "start");
      label.setAttribute("x", String(box.x + box.width + 10));
    });
  };

  const baseOptions = (dark) => ({
    chart: {
      fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif",
      animations: { enabled: false },
      background: "transparent",
      toolbar: { show: false },
      redrawOnParentResize: true,
      redrawOnWindowResize: true,
    },
    colors: palette,
    theme: { mode: dark ? "dark" : "light" },
    tooltip: { theme: dark ? "dark" : "light" },
  });

  const horizontalBarOptions = (rows, dark, { stacked = false, axisMax = null, decimals = 0, colors = [], pdf = false } = {}) => {
    const theme = themeFor(dark);
    const configuredMax = Number(axisMax);
    const horizontalMax = Number.isFinite(configuredMax) && configuredMax > 0 ? configuredMax : Math.max(1, ...valuesFor(rows));
    const valueDecimals = Math.max(0, Number(decimals) || 0);
    const horizontalAxisMax = horizontalMax + Math.max(1, horizontalMax * 0.16);
    return {
      ...baseOptions(dark),
      ...(colors.length ? { colors } : {}),
      chart: { ...baseOptions(dark).chart, type: "bar", stacked, height: "100%" },
      series: [{ name: "Recorded", data: valuesFor(rows) }],
      xaxis: {
        categories: labelsFor(rows),
        min: 0,
        max: horizontalAxisMax,
        tickAmount: Math.min(5, horizontalMax),
        decimalsInFloat: valueDecimals,
        labels: { style: { colors: theme.text, fontSize: "11px", fontWeight: 600 } },
        axisBorder: { color: theme.axis },
      },
      yaxis: { labels: { style: { colors: theme.text, fontSize: "11px", fontWeight: 600 }, maxWidth: 116 } },
      grid: { borderColor: theme.grid, padding: { left: 4, right: 56, top: -4, bottom: -8 } },
      plotOptions: { bar: { horizontal: true, distributed: colors.length > 1, borderRadius: pdf ? 0 : 6, barHeight: "58%", dataLabels: { position: "top" } } },
      dataLabels: {
        enabled: true,
        formatter: (value) => Number(value).toFixed(valueDecimals),
        textAnchor: "start",
        style: { colors: [theme.value], fontSize: "11px", fontWeight: 800 },
      },
      legend: { show: false },
    };
  };

  const optionFor = (chart, { dark = false, pdf = false } = {}) => {
    const rows = rowsFor(chart);
    const type = String(chart?.type || chart?.render_type || "bar");
    const values = valuesFor(rows);
    const labels = labelsFor(rows);
    const theme = themeFor(dark);
    const axisMax = chart?.axis_max;
    const decimals = chart?.decimals ?? 0;
    const colors = Array.isArray(chart?.colors) ? chart.colors : [];
    const pdfXAxis = pdf ? groupedPdfXAxis(chart, labels) : null;

    if (type === "donut") {
      if (rows.length > 4) return horizontalBarOptions(rows, dark, { axisMax, decimals, colors, pdf });
      const total = values.reduce((sum, value) => sum + value, 0);
      return {
        ...baseOptions(dark),
        chart: { ...baseOptions(dark).chart, type: "donut", height: "100%" },
        series: values,
        labels,
        stroke: { colors: [theme.background], width: 3 },
        legend: { show: false },
        dataLabels: { enabled: false },
        plotOptions: {
          pie: {
            expandOnClick: false,
            customScale: 0.88,
            donut: {
              size: "62%",
              labels: {
                show: true,
                name: { show: false },
                value: { show: false },
                total: {
                  show: true,
                  showAlways: true,
                  label: "TOTAL",
                  color: "#64748b",
                  fontSize: "9px",
                  fontWeight: 700,
                  formatter: () => String(total),
                },
              },
            },
            dataLabels: {
              external: {
                show: true,
                formatter: (name, options) => [name, String(values[options.seriesIndex] ?? 0)],
                connector: { show: true, length: 12, width: 1 },
                style: { colors: [theme.text], fontSize: "11px", fontWeight: 700 },
              },
            },
          },
        },
        tooltip: { theme: dark ? "dark" : "light", y: { formatter: (value) => String(value) } },
      };
    }

    if (type === "line") return {
      ...baseOptions(dark),
      chart: { ...baseOptions(dark).chart, type: "area", height: "100%" },
      series: [{ name: "Recorded", data: values }],
      xaxis: { categories: pdfXAxis?.categories || labels, groups: pdfXAxis?.groups, labels: { style: { colors: theme.text, fontSize: "11px", fontWeight: 600 } }, axisBorder: { color: theme.axis } },
      yaxis: { min: 0, labels: { style: { colors: theme.text, fontSize: "11px", fontWeight: 600 } } },
      stroke: { curve: "smooth", width: 3 },
      fill: { type: "solid", opacity: 0.12 },
      markers: { size: 4 },
      dataLabels: { enabled: true, offsetY: -8, style: { colors: [theme.value], fontSize: "11px", fontWeight: 800 } },
      grid: { borderColor: theme.grid, padding: { left: 2, right: 8, top: 12, bottom: pdfXAxis ? 14 : 0 } },
      legend: { show: false },
    };

    if (type === "progress") return horizontalBarOptions(rows, dark, { stacked: true, axisMax, decimals, colors, pdf });

    if (type === "column") return {
      ...baseOptions(dark),
      chart: { ...baseOptions(dark).chart, type: "bar", height: "100%" },
      series: [{ name: "Recorded", data: values }],
      xaxis: { categories: pdfXAxis?.categories || labels, groups: pdfXAxis?.groups, labels: { rotate: pdfXAxis ? 0 : (labels.length > 5 ? -24 : 0), style: { colors: theme.text, fontSize: "10px", fontWeight: 600 } }, axisBorder: { color: theme.axis } },
      yaxis: { min: 0, labels: { style: { colors: theme.text, fontSize: "11px", fontWeight: 600 } } },
      grid: { borderColor: theme.grid, padding: { left: 2, right: 8, top: 42, bottom: pdfXAxis ? 16 : 4 } },
      plotOptions: { bar: { borderRadius: pdf ? 0 : 6, columnWidth: "48%", dataLabels: { position: "top" } } },
      dataLabels: { enabled: true, offsetY: -18, style: { colors: [theme.value], fontSize: "11px", fontWeight: 800 } },
      legend: { show: false },
    };

    return horizontalBarOptions(rows, dark, { axisMax, decimals, colors, pdf });
  };

  const imageFor = async (chart, { width = 960, height = 360, pdf = false } = {}) => {
    if (!window.ApexCharts) throw new Error("The report chart renderer is unavailable.");
    const host = document.createElement("div");
    host.style.cssText = `position:fixed;left:-10000px;top:-10000px;width:${width}px;height:${height}px;pointer-events:none;`;
    document.body.append(host);
    let instance;
    try {
      instance = new window.ApexCharts(host, optionFor(chart, { pdf }));
      await instance.render();
      placeHorizontalBarLabels(host, instance.w?.config?.plotOptions?.bar?.horizontal);
      const image = await instance.dataURI({ scale: 2 });
      return image.imgURI;
    } finally {
      instance?.destroy();
      host.remove();
    }
  };

  const mount = (host, chart, { dark = false, pdf = false } = {}) => {
    if (!window.ApexCharts || !host) return null;
    const fallback = Array.from(host.childNodes);
    const canvas = document.createElement("div");
    canvas.className = "report-apexcharts-canvas";
    let instance;
    try {
      host.replaceChildren(canvas);
      instance = new window.ApexCharts(canvas, optionFor(chart, { dark, pdf }));
      instance.render().then(() => {
        placeHorizontalBarLabels(canvas, instance.w?.config?.plotOptions?.bar?.horizontal);
        if (pdf) hideOverlappingPdfXAxisLabels(canvas);
      }).catch(() => {
        instance?.destroy();
        host.replaceChildren(...fallback);
      });
      return {
        canvas,
        resize: () => Promise.resolve(instance?.updateOptions({}, false, false, false)).then(() => {
          placeHorizontalBarLabels(canvas, instance?.w?.config?.plotOptions?.bar?.horizontal);
          if (pdf) hideOverlappingPdfXAxisLabels(canvas);
        }),
        destroy: () => instance?.destroy(),
      };
    } catch (_) {
      instance?.destroy();
      host.replaceChildren(...fallback);
      return null;
    }
  };

  window.cliniqSystemReportCharts = { optionFor, imageFor, mount };
})();
