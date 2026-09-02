let data = null;
const FLAG_ORDER = ['contacted', 'unreachable', 'won', 'lost'];

(async () => {
  await requireSession({ page: 'dashboard', adminOnly: true });
  await load();
})();

async function load() {
  const status = $('status');
  status.innerHTML = '<div class="loading"><span class="pulse"></span>Loading dashboard…</div>';

  try {
    data = await apiGet('api/dashboard');
    render();
  } catch (err) {
    showError(status, 'Could not load the dashboard', esc(err.message));
  }
}

function cssVar(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

function statusColor(status) {
  return cssVar({ contacted: '--flag-contacted', unreachable: '--flag-unreachable', won: '--mobile', lost: '--stat-lost' }[status]);
}

function render() {
  const { employees, totals, daily } = data;

  $('status').innerHTML =
    kpiRow(totals) +

    '<div class="dashboard-grid">' +

      '<div class="chart-card">' +
        '<h2>Leads worked, by employee</h2>' +
        '<p class="sub">Busiest first.</p>' +
        (employees.length
          ? chartLegend() + '<div class="barrows scrollbox" id="flagBars"></div>'
          : '<p class="empty-note">No employee accounts yet.</p>') +
      '</div>' +

      '<div class="chart-card">' +
        '<h2>AI searches, by employee</h2>' +
        '<p class="sub">Last 30 days.</p>' +
        (employees.length
          ? '<div class="barrows scrollbox" id="searchBars"></div>'
          : '<p class="empty-note">No employee accounts yet.</p>') +
      '</div>' +

      '<div class="chart-card">' +
        '<h2>Lead activity</h2>' +
        '<p class="sub">Status changes per day, last 30 days, team-wide.</p>' +
        '<svg class="trendchart" id="trendSvg" viewBox="0 0 340 150"></svg>' +
      '</div>' +

    '</div>' +

    '<div class="sechead" style="margin-top:4px"><h2>By employee</h2><span class="cbadge">' + employees.length + '</span></div>' +
    tableHtml(employees);

  if (employees.length) {
    renderFlagBars(employees);
    renderSearchBars(employees);
  }
  renderTrend(daily);
}

/* ── KPI tiles ──────────────────────────────────────────────────────────── */

function kpiRow(t) {
  const tiles = [
    ['Leads worked', fmt(t.total_flagged)],
    ['Won', fmt(t.won)],
    ['Lost', fmt(t.lost)],
    ['Win rate', t.win_rate === null ? '—' : t.win_rate + '%'],
    ['Active employees', fmt(t.active_employees)],
    ['Searches (30d)', fmt(t.searches_30d)],
  ];

  return '<div class="kpi-row">' + tiles.map(([label, value]) =>
    '<div class="stat-tile"><div class="label">' + esc(label) + '</div><div class="value">' + esc(String(value)) + '</div></div>'
  ).join('') + '</div>';
}

function chartLegend() {
  return '<div class="chartlegend">' + FLAG_ORDER.map(s =>
    '<span><i style="background:' + statusColor(s) + '"></i>' + esc(FLAG_LABELS[s]) + '</span>'
  ).join('') + '</div>';
}

/* ── leads-worked bar chart (stacked, one row per employee) ──────────────── */

function renderFlagBars(employees) {
  const host = $('flagBars');
  const W = 300, H = 16, gap = 2;
  const max = Math.max(1, ...employees.map(e => e.total_flagged));

  host.innerHTML = employees.map(e => {
    const total = e.total_flagged;
    let segs = '<rect x="0" y="0" width="' + W + '" height="' + H + '" rx="4" fill="' + cssVar('--sunken') + '"></rect>';

    if (total > 0) {
      let x = 0;
      segs = FLAG_ORDER.map(s => {
        const n = e[s];
        if (!n) return '';
        const full = (n / max) * W;
        const w = Math.max(0, full - (x + full >= (total / max) * W - 0.5 ? 0 : gap));
        const rect = '<rect data-emp="' + e.id + '" data-seg="' + s + '" x="' + x.toFixed(1) + '" y="0" width="' +
          w.toFixed(1) + '" height="' + H + '" fill="' + statusColor(s) + '"></rect>';
        x += full;
        return rect;
      }).join('');
    }

    return '<div class="barrow">' +
      '<span class="who" title="' + esc(e.full_name) + '">' + esc(e.full_name) + '</span>' +
      '<svg viewBox="0 0 ' + W + ' ' + H + '" data-emp="' + e.id + '">' + segs + '</svg>' +
      '<span class="total">' + fmt(total) + '</span>' +
    '</div>';
  }).join('');

  wireSegmentTooltips(host, (id, seg) => {
    const e = employees.find(x => x.id === id);
    return e ? [fmt(e[seg]) + ' ' + FLAG_LABELS[seg].toLowerCase(), e.full_name] : null;
  });
}

/* ── AI-search bar chart (single bar per employee) ────────────────────────── */

function renderSearchBars(employees) {
  const host = $('searchBars');
  const sorted = [...employees].sort((a, b) => b.searches_30d - a.searches_30d);
  const W = 300, H = 16;
  const max = Math.max(1, ...sorted.map(e => e.searches_30d));
  const primary = cssVar('--primary');

  host.innerHTML = sorted.map(e => {
    const w = (e.searches_30d / max) * W;
    return '<div class="barrow">' +
      '<span class="who" title="' + esc(e.full_name) + '">' + esc(e.full_name) + '</span>' +
      '<svg viewBox="0 0 ' + W + ' ' + H + '" data-emp="' + e.id + '">' +
        '<rect x="0" y="0" width="' + W + '" height="' + H + '" rx="4" fill="' + cssVar('--sunken') + '"></rect>' +
        (e.searches_30d > 0
          ? '<rect data-emp="' + e.id + '" data-seg="searches" x="0" y="0" width="' + w.toFixed(1) + '" height="' + H + '" rx="4" fill="' + primary + '"></rect>'
          : '') +
      '</svg>' +
      '<span class="total">' + fmt(e.searches_30d) + '</span>' +
    '</div>';
  }).join('');

  wireSegmentTooltips(host, id => {
    const e = sorted.find(x => x.id === id);
    return e ? [fmt(e.searches_30d) + ' search' + (e.searches_30d === 1 ? '' : 'es'), e.full_name] : null;
  });
}

/** Shared hover wiring for the two bar charts: each colored <rect> gets a
 *  tooltip built from (employee id, segment key) -> [value text, name]. */
function wireSegmentTooltips(host, describe) {
  host.querySelectorAll('rect[data-emp]').forEach(rect => {
    rect.addEventListener('pointermove', event => {
      const info = describe(Number(rect.dataset.emp), rect.dataset.seg);
      if (info) showTip(event.clientX, event.clientY, info[0], info[1]);
    });
    rect.addEventListener('pointerleave', hideTip);
  });
}

/* ── 30-day activity line chart ───────────────────────────────────────────── */

function renderTrend(daily) {
  const svg = $('trendSvg');
  if (!svg || !daily.length) return;

  const W = 340, H = 150, padL = 28, padR = 8, padT = 10, padB = 18;
  const plotW = W - padL - padR, plotH = H - padT - padB;
  const max = niceCeil(Math.max(1, ...daily.map(d => d.count)));

  const x = i => padL + (daily.length === 1 ? 0 : (i / (daily.length - 1)) * plotW);
  const y = v => padT + plotH - (v / max) * plotH;

  const steps = [0, max / 2, max];
  const grid = steps.map(v => {
    const yy = y(v);
    return '<line class="gridline" x1="' + padL + '" x2="' + (W - padR) + '" y1="' + yy.toFixed(1) + '" y2="' + yy.toFixed(1) + '"></line>' +
      '<text class="axislabel" x="' + (padL - 8) + '" y="' + (yy + 3).toFixed(1) + '" text-anchor="end">' + Math.round(v) + '</text>';
  }).join('');

  const dateLabel = i => {
    const d = new Date(daily[i].date + 'T00:00:00');
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  };
  const xTickIdx = [0, Math.floor((daily.length - 1) / 2), daily.length - 1];
  const xLabels = xTickIdx.map(i =>
    '<text class="axislabel" x="' + x(i).toFixed(1) + '" y="' + (H - 6) + '" text-anchor="middle">' + esc(dateLabel(i)) + '</text>'
  ).join('');

  const linePath = daily.map((d, i) => (i === 0 ? 'M' : 'L') + x(i).toFixed(1) + ',' + y(d.count).toFixed(1)).join(' ');
  const areaPath = linePath +
    ' L' + x(daily.length - 1).toFixed(1) + ',' + y(0).toFixed(1) +
    ' L' + x(0).toFixed(1) + ',' + y(0).toFixed(1) + ' Z';

  const lastIdx = daily.length - 1;
  const lastX = x(lastIdx), lastY = y(daily[lastIdx].count);
  const endLabel = '<text class="axislabel" x="' + lastX.toFixed(1) + '" y="' + (lastY - 10).toFixed(1) +
    '" text-anchor="end" font-weight="700" fill="' + cssVar('--primary-ink') + '">' + fmt(daily[lastIdx].count) + '</text>';

  svg.innerHTML =
    grid + xLabels +
    '<path class="trendarea" d="' + areaPath + '"></path>' +
    '<path class="trendline" d="' + linePath + '"></path>' +
    '<circle class="trenddot" cx="' + lastX.toFixed(1) + '" cy="' + lastY.toFixed(1) + '" r="4"></circle>' +
    endLabel +
    '<line class="crosshair" id="trendCrosshair" x1="0" x2="0" y1="' + padT + '" y2="' + (H - padB) + '"></line>' +
    '<rect id="trendHit" x="' + padL + '" y="' + padT + '" width="' + plotW + '" height="' + plotH + '" fill="transparent"></rect>';

  const hit = $('trendHit');
  const crosshair = $('trendCrosshair');

  hit.addEventListener('pointermove', event => {
    const rect = svg.getBoundingClientRect();
    const localX = (event.clientX - rect.left) * (W / rect.width);
    let idx = Math.round(((localX - padL) / plotW) * (daily.length - 1));
    idx = Math.max(0, Math.min(daily.length - 1, idx));

    const cx = x(idx).toFixed(1);
    crosshair.setAttribute('x1', cx);
    crosshair.setAttribute('x2', cx);
    crosshair.style.opacity = '1';

    const d = daily[idx];
    const label = new Date(d.date + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    showTip(event.clientX, event.clientY, fmt(d.count) + ' flag' + (d.count === 1 ? '' : 's'), label);
  });
  hit.addEventListener('pointerleave', () => {
    crosshair.style.opacity = '0';
    hideTip();
  });
}

/** Rounds up to a friendly axis ceiling: 5, 10, 20, 50, 100, 200, ... */
function niceCeil(n) {
  if (n <= 5) return 5;
  const mag = Math.pow(10, Math.floor(Math.log10(n)));
  const norm = n / mag;
  const nice = norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10;
  return nice * mag;
}

/* ── shared tooltip ────────────────────────────────────────────────────────── */

function showTip(clientX, clientY, valueText, labelText) {
  const tip = $('chartTip');
  tip.innerHTML = '';
  const v = document.createElement('span');
  v.className = 'tt-value';
  v.textContent = valueText;
  const l = document.createElement('span');
  l.className = 'tt-label';
  l.textContent = labelText;
  tip.appendChild(v);
  tip.appendChild(l);

  const pad = 14;
  let left = clientX + pad, top = clientY + pad;
  if (left + 230 > window.innerWidth) left = clientX - 230;
  if (top + 60 > window.innerHeight) top = clientY - 60;
  tip.style.left = left + 'px';
  tip.style.top = top + 'px';
  tip.classList.add('show');
}

function hideTip() {
  $('chartTip').classList.remove('show');
}

/* ── detail table (the table-view twin of both bar charts) ───────────────── */

function tableHtml(employees) {
  if (!employees.length) {
    return '<div class="empty"><h3>No employees yet</h3><p>Create accounts on <a href="users.html">Users</a>.</p></div>';
  }

  return '<div class="tablecard"><div class="scroll"><table><thead><tr>' +
      '<th>Employee</th><th>Contacted</th><th>Unable to contact</th><th>Won</th><th>Lost</th>' +
      '<th>Total</th><th>Win rate</th><th>Searches (30d)</th><th>Last search</th>' +
    '</tr></thead><tbody>' +
    employees.map(e => '<tr>' +
      '<td class="name">' + esc(e.full_name) + '</td>' +
      '<td class="mono">' + fmt(e.contacted) + '</td>' +
      '<td class="mono">' + fmt(e.unreachable) + '</td>' +
      '<td class="mono">' + fmt(e.won) + '</td>' +
      '<td class="mono">' + fmt(e.lost) + '</td>' +
      '<td class="mono" style="font-weight:700">' + fmt(e.total_flagged) + '</td>' +
      '<td class="mono">' + (e.win_rate === null ? '<span class="blank">—</span>' : e.win_rate + '%') + '</td>' +
      '<td class="mono">' + fmt(e.searches_30d) + '</td>' +
      '<td class="st mono" style="font-size:12px">' +
        (e.last_search_at ? esc(e.last_search_at.slice(0, 16)) : '<span class="blank">never</span>') + '</td>' +
    '</tr>').join('') +
    '</tbody></table></div></div>';
}
