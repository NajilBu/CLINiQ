/* global echarts */
(() => {
  const palette = ["#2f8553", "#58a978", "#89c79f", "#d4a72c", "#5377b8", "#8b69c7", "#d26b6b", "#64748b"];
  const optionFor = (chart, { dark = false } = {}) => {
    const rows = Array.isArray(chart?.rows) ? chart.rows : [];
    const type = String(chart?.type || chart?.render_type || "bar");
    const labels = rows.map((row) => String(row.label ?? ""));
    const values = rows.map((row) => Number(row.value) || 0);
    const text = dark ? "#b3c9ba" : "#475569";
    const value = dark ? "#9be3ae" : "#205f3d";
    const grid = dark ? "#35523f" : "#edf3ef";
    const axis = dark ? "#4b6d55" : "#dfe9e2";
    const shared = { animationDuration: 0, color: palette, textStyle: { fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif" }, tooltip: { trigger: type === "donut" ? "item" : "axis", confine: true } };
    if (type === "donut") return { ...shared, tooltip: { trigger: "item", formatter: "{b}: <b>{c}</b> ({d}%)" }, series: [{ type: "pie", radius: ["48%", "72%"], center: ["50%", "50%"], avoidLabelOverlap: true, itemStyle: { borderColor: dark ? "#14271b" : "#fff", borderWidth: 3, borderRadius: 5 }, label: { color: text, fontSize: 12, fontWeight: 700, formatter: "{b}" }, labelLine: { length: 8, length2: 8 }, data: rows.map((row) => ({ name: String(row.label ?? ""), value: Number(row.value) || 0 })) }], graphic: [{ type: "text", left: "center", top: "42%", style: { text: String(values.reduce((total, item) => total + item, 0)), fill: value, font: "800 18px Inter, sans-serif", textAlign: "center" } }, { type: "text", left: "center", top: "55%", style: { text: "TOTAL", fill: "#64748b", font: "700 9px Inter, sans-serif", textAlign: "center" } }] };
    if (type === "line") return { ...shared, grid: { left: 36, right: 18, top: 22, bottom: 38 }, xAxis: { type: "category", data: labels, axisLabel: { color: text, fontSize: 11, fontWeight: 600, interval: "auto" }, axisLine: { lineStyle: { color: axis } } }, yAxis: { type: "value", axisLabel: { color: text, fontSize: 11, fontWeight: 600 }, splitLine: { lineStyle: { color: grid } } }, series: [{ type: "line", data: values, smooth: true, symbolSize: 8, lineStyle: { width: 3 }, areaStyle: { color: "rgba(47,133,83,.12)" }, label: { show: true, position: "top", color: value, fontWeight: 800, fontSize: 11 } }] };
    if (type === "progress") return { ...shared, tooltip: { trigger: "item", formatter: "{b}: <b>{c}</b>" }, grid: { left: 4, right: 4, top: 48, bottom: 10 }, xAxis: { type: "value", max: values.reduce((total, item) => total + item, 0) || 1, show: false }, yAxis: { type: "category", data: [""], show: false }, legend: { top: 0, type: "scroll", textStyle: { color: text, fontSize: 11, fontWeight: 600 } }, series: rows.map((row, index) => ({ name: String(row.label ?? ""), type: "bar", stack: "total", barWidth: 26, data: [values[index]], label: { show: values[index] > 0, formatter: "{c}", color: "#fff", fontWeight: 800, fontSize: 11 } })) };
    const column = type === "column";
    return { ...shared, grid: column ? { left: 36, right: 16, top: 20, bottom: 52 } : { left: 116, right: 36, top: 16, bottom: 12 }, xAxis: column ? { type: "category", data: labels, axisLabel: { color: text, fontSize: 10, fontWeight: 600, rotate: labels.length > 5 ? 24 : 0, interval: 0 }, axisLine: { lineStyle: { color: axis } } } : { type: "value", axisLabel: { color: text, fontSize: 11, fontWeight: 600 }, splitLine: { lineStyle: { color: grid } } }, yAxis: column ? { type: "value", axisLabel: { color: text, fontSize: 11, fontWeight: 600 }, splitLine: { lineStyle: { color: grid } } } : { type: "category", data: labels, axisLabel: { color: text, fontSize: 11, fontWeight: 600, width: 102, overflow: "truncate" }, axisLine: { show: false }, axisTick: { show: false } }, series: [{ type: "bar", data: values, barMaxWidth: 34, itemStyle: { borderRadius: column ? [6, 6, 0, 0] : [0, 6, 6, 0] }, label: { show: true, position: column ? "top" : "right", color: value, fontWeight: 800, fontSize: 11 } }] };
  };
  const imageFor = async (chart, { width = 960, height = 360 } = {}) => {
    if (!window.echarts) throw new Error("The report chart renderer is unavailable.");
    const host = document.createElement("div");
    host.style.cssText = `position:fixed;left:-10000px;top:-10000px;width:${width}px;height:${height}px;pointer-events:none;`;
    document.body.append(host);
    let instance;
    try {
      instance = window.echarts.init(host, null, { renderer: "canvas", width, height });
      await new Promise((resolve) => {
        let captured = false;
        let timeout;
        const finish = () => {
          if (captured) return;
          captured = true;
          window.clearTimeout(timeout);
          instance.off("finished", finish);
          resolve();
        };
        instance.on("finished", finish);
        instance.setOption({ ...optionFor(chart), animation: false }, { lazyUpdate: false });
        timeout = window.setTimeout(finish, 1000);
      });
      return instance.getDataURL({ type: "png", pixelRatio: 2, backgroundColor: "#ffffff" });
    } finally {
      instance?.dispose();
      host.remove();
    }
  };
  const mount = (host, chart, { dark = false } = {}) => {
    if (!window.echarts || !host) return null;
    const fallback = Array.from(host.childNodes);
    const canvas = document.createElement("div");
    canvas.className = "report-echarts-canvas";
    let instance;
    try {
      host.replaceChildren(canvas);
      instance = window.echarts.init(canvas, null, { renderer: "svg" });
      instance.setOption(optionFor(chart, { dark }));
      return { chart: instance, canvas };
    } catch (_) {
      instance?.dispose();
      host.replaceChildren(...fallback);
      return null;
    }
  };
  window.cliniqSystemReportCharts = { optionFor, imageFor, mount };
})();
