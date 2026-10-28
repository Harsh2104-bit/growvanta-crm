/**
 * Hand-rolled SVG charts.
 *
 * No charting library: the CRM ships four chart types and each is ~40 lines of
 * SVG, which is smaller and faster than any dependency that would do the same.
 * Everything scales with `viewBox` so charts stay crisp and responsive, and
 * colours come from CSS variables so they follow the theme.
 */
import { svg, h } from './dom.js';
import { compactNumber, number } from './format.js';

/* Brand green leads, then the logo's deep green, then hues picked to stay
   distinguishable beside it rather than competing with it. */
export const PALETTE = [
  '#35e06f', '#0f833b', '#0284c7', '#d97706', '#dc2626',
  '#7c3aed', '#0d9488', '#db2777', '#65a30d', '#c2410c',
  '#0891b2', '#9333ea',
];

const uid = () => `gv${Math.random().toString(36).slice(2, 9)}`;

function tooltipLayer(container) {
  const tip = h('div', {
    class: 'chart-tip',
    style: {
      position: 'absolute', pointerEvents: 'none', opacity: '0',
      background: 'var(--sidebar-bg)', color: '#fff', padding: '6px 9px',
      borderRadius: '7px', fontSize: '11.5px', whiteSpace: 'nowrap',
      transform: 'translate(-50%, -130%)', transition: 'opacity .1s ease', zIndex: '5',
      boxShadow: 'var(--shadow-md)',
    },
  });
  container.style.position = 'relative';
  container.appendChild(tip);
  return {
    show(text, x, y) { tip.textContent = text; tip.style.left = `${x}px`; tip.style.top = `${y}px`; tip.style.opacity = '1'; },
    hide() { tip.style.opacity = '0'; },
  };
}

/**
 * Multi-series line/area chart.
 * @param series [{ key, label, color, values: number[] }]
 * @param labels x-axis labels, same length as each series' values
 */
export function lineChart(series, labels, { height = 240, area = true, formatValue = number } = {}) {
  const width = 760;
  const pad = { top: 16, right: 12, bottom: 26, left: 44 };
  const plotW = width - pad.left - pad.right;
  const plotH = height - pad.top - pad.bottom;

  const allValues = series.flatMap((s) => s.values);
  const max = Math.max(1, ...allValues);
  // Round the axis up to a friendly number so gridlines land on whole values.
  const step = Math.pow(10, Math.floor(Math.log10(max)));
  const niceMax = Math.ceil(max / step) * step || 1;

  const n = labels.length || 1;
  const x = (i) => pad.left + (n === 1 ? plotW / 2 : (i / (n - 1)) * plotW);
  const y = (v) => pad.top + plotH - (v / niceMax) * plotH;

  const root = svg('svg', {
    viewBox: `0 0 ${width} ${height}`, width: '100%', height,
    // `none` would stretch the axis labels out of shape on a narrow screen;
    // letting it scale uniformly keeps the type legible at every width.
    preserveAspectRatio: 'xMidYMid meet',
    style: 'width:100%;height:auto;display:block',
    role: 'img', 'aria-label': 'Trend chart',
  });

  // Gridlines + y labels
  for (let i = 0; i <= 4; i += 1) {
    const value = (niceMax / 4) * i;
    const yy = y(value);
    root.appendChild(svg('line', {
      x1: pad.left, x2: width - pad.right, y1: yy, y2: yy,
      stroke: 'var(--line-2)', 'stroke-width': 1,
    }));
    root.appendChild(svg('text', {
      x: pad.left - 8, y: yy + 4, 'text-anchor': 'end',
      'font-size': 10.5, fill: 'var(--ink-4)',
    }, compactNumber(value)));
  }

  // X labels: thinned so they never collide.
  const every = Math.max(1, Math.ceil(n / 8));
  labels.forEach((label, i) => {
    if (i % every !== 0 && i !== n - 1) return;
    root.appendChild(svg('text', {
      x: x(i), y: height - 7, 'text-anchor': 'middle',
      'font-size': 10.5, fill: 'var(--ink-4)',
    }, label));
  });

  series.forEach((s, si) => {
    const color = s.color || PALETTE[si % PALETTE.length];
    const points = s.values.map((v, i) => `${x(i)},${y(v)}`);
    if (!points.length) return;

    if (area) {
      const gradId = uid();
      const defs = svg('defs', {},
        svg('linearGradient', { id: gradId, x1: '0', y1: '0', x2: '0', y2: '1' },
          svg('stop', { offset: '0%', 'stop-color': color, 'stop-opacity': '0.28' }),
          svg('stop', { offset: '100%', 'stop-color': color, 'stop-opacity': '0' })));
      root.appendChild(defs);
      root.appendChild(svg('path', {
        d: `M ${pad.left},${pad.top + plotH} L ${points.join(' L ')} L ${x(s.values.length - 1)},${pad.top + plotH} Z`,
        fill: `url(#${gradId})`, stroke: 'none',
      }));
    }

    root.appendChild(svg('polyline', {
      points: points.join(' '), fill: 'none', stroke: color,
      'stroke-width': 2.25, 'stroke-linejoin': 'round', 'stroke-linecap': 'round',
    }));
  });

  const wrap = h('div', { class: 'chart-wrap' }, root);
  const tip = tooltipLayer(wrap);

  // One transparent hit-strip per x position keeps hovering cheap and precise.
  const hover = svg('g');
  labels.forEach((label, i) => {
    const bandW = plotW / Math.max(1, n - 1);
    hover.appendChild(svg('rect', {
      x: x(i) - bandW / 2, y: pad.top, width: bandW, height: plotH,
      fill: 'transparent',
      onMouseenter: (e) => {
        const rect = wrap.getBoundingClientRect();
        const text = `${label} · ${series.map((s) => `${s.label} ${formatValue(s.values[i] ?? 0)}`).join(' · ')}`;
        tip.show(text, e.clientX - rect.left, e.clientY - rect.top);
      },
      onMouseleave: () => tip.hide(),
    }));
  });
  root.appendChild(hover);

  const legend = h('div', { class: 'legend' }, ...series.map((s, si) => h('div', { class: 'legend-item' },
    h('span', { class: 'legend-swatch', style: { background: s.color || PALETTE[si % PALETTE.length] } }),
    s.label)));

  return h('div', null, wrap, legend);
}

/** Horizontal bar list - better than a vertical bar chart for long category names. */
export function barList(rows, { formatValue = number, colorFn = null, max: maxOverride = null } = {}) {
  const max = maxOverride ?? Math.max(1, ...rows.map((r) => r.value));
  return h('div', { class: 'bar-list' },
    ...rows.map((row, i) => h('div', { class: 'bar-row' },
      h('div', { class: 'bar-top' },
        h('span', { class: 'truncate' }, row.label),
        h('span', { class: 'strong' }, formatValue(row.value))),
      h('div', { class: 'bar-track' },
        h('div', {
          class: 'bar-fill',
          style: {
            width: `${Math.max(2, (row.value / max) * 100)}%`,
            background: row.color || colorFn?.(row, i) || PALETTE[i % PALETTE.length],
          },
        })))));
}

/** Donut with a centre label. Segments are drawn as stroked arcs. */
export function donutChart(slices, { size = 190, thickness = 26, centerLabel, centerValue } = {}) {
  const total = slices.reduce((sum, s) => sum + (s.value || 0), 0);
  const radius = (size - thickness) / 2;
  const circumference = 2 * Math.PI * radius;
  const root = svg('svg', { viewBox: `0 0 ${size} ${size}`, width: size, height: size, role: 'img', 'aria-label': 'Distribution chart' });
  const group = svg('g', { transform: `rotate(-90 ${size / 2} ${size / 2})` });

  root.appendChild(svg('circle', {
    cx: size / 2, cy: size / 2, r: radius, fill: 'none',
    stroke: 'var(--surface-3)', 'stroke-width': thickness,
  }));

  let offset = 0;
  slices.forEach((slice, i) => {
    if (!slice.value) return;
    const fraction = total > 0 ? slice.value / total : 0;
    const dash = fraction * circumference;
    group.appendChild(svg('circle', {
      cx: size / 2, cy: size / 2, r: radius, fill: 'none',
      stroke: slice.color || PALETTE[i % PALETTE.length],
      'stroke-width': thickness,
      'stroke-dasharray': `${dash} ${circumference - dash}`,
      'stroke-dashoffset': -offset,
    }, svg('title', {}, `${slice.label}: ${number(slice.value)}`)));
    offset += dash;
  });
  root.appendChild(group);

  return h('div', { class: 'row', style: { gap: '20px', flexWrap: 'wrap', justifyContent: 'center' } },
    h('div', { style: { position: 'relative', width: `${size}px`, height: `${size}px`, flex: `0 0 ${size}px` } },
      root,
      h('div', {
        style: {
          position: 'absolute', inset: '0', display: 'grid', placeItems: 'center',
          textAlign: 'center', pointerEvents: 'none',
        },
      },
        h('div', null,
          h('div', { style: { fontSize: '22px', fontWeight: '700', letterSpacing: '-.02em' } },
            centerValue ?? number(total)),
          h('div', { class: 'muted tiny' }, centerLabel || 'Total')))),
    h('div', { class: 'col', style: { gap: '7px', minWidth: '140px' } },
      ...slices.filter((s) => s.value).map((slice, i) => h('div', { class: 'legend-item' },
        h('span', { class: 'legend-swatch', style: { background: slice.color || PALETTE[i % PALETTE.length] } }),
        h('span', { class: 'truncate grow' }, slice.label),
        h('span', { class: 'strong small' }, number(slice.value))))));
}

/** Conversion funnel. Each step is measured against the first for width. */
export function funnelChart(steps) {
  const top = Math.max(1, steps[0]?.value || 1);
  return h('div', { class: 'funnel' },
    ...steps.map((step, i) => {
      const pctOfTop = (step.value / top) * 100;
      const previous = i > 0 ? steps[i - 1].value : null;
      const dropoff = previous ? Math.round(((previous - step.value) / previous) * 100) : null;
      return h('div', { class: 'funnel-step' },
        h('div', { class: 'f-label truncate' }, step.label),
        h('div', { class: 'f-bar' },
          h('div', { class: 'f-fill', style: { width: `${Math.max(6, pctOfTop)}%` } }, number(step.value))),
        h('div', { class: 'f-pct', title: dropoff !== null ? `${dropoff}% drop from previous stage` : '' },
          `${Math.round(pctOfTop)}%`));
    }));
}

/** Compact inline sparkline for KPI cards and table cells. */
export function sparkline(values, { width = 96, height = 26, color = 'var(--brand-500)' } = {}) {
  if (!values?.length) return h('span');
  const max = Math.max(1, ...values);
  const min = Math.min(...values);
  const range = max - min || 1;
  const points = values.map((v, i) => {
    const x = (i / Math.max(1, values.length - 1)) * width;
    const y = height - ((v - min) / range) * (height - 4) - 2;
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  });
  return svg('svg', { width, height, viewBox: `0 0 ${width} ${height}`, 'aria-hidden': 'true' },
    svg('polyline', {
      points: points.join(' '), fill: 'none', stroke: color,
      'stroke-width': 1.8, 'stroke-linejoin': 'round', 'stroke-linecap': 'round',
    }));
}

/** Circular score gauge used on the lead 360 page. */
export function scoreRing(score, { size = 68, color = 'var(--brand-600)' } = {}) {
  const radius = (size - 8) / 2;
  const circumference = 2 * Math.PI * radius;
  const dash = (Math.max(0, Math.min(100, score)) / 100) * circumference;
  return h('div', { class: 'score-ring', style: { width: `${size}px`, height: `${size}px`, flexBasis: `${size}px` } },
    svg('svg', { width: size, height: size, viewBox: `0 0 ${size} ${size}` },
      svg('circle', { cx: size / 2, cy: size / 2, r: radius, fill: 'none', stroke: 'var(--surface-3)', 'stroke-width': 6 }),
      svg('circle', {
        cx: size / 2, cy: size / 2, r: radius, fill: 'none', stroke: color, 'stroke-width': 6,
        'stroke-linecap': 'round', 'stroke-dasharray': `${dash} ${circumference}`,
        transform: `rotate(-90 ${size / 2} ${size / 2})`,
      })),
    h('div', { class: 's-val', style: { color } }, String(Math.round(score))));
}

/** Grouped column chart, used for target vs actual. */
export function columnChart(groups, seriesDefs, { height = 250, formatValue = compactNumber } = {}) {
  const width = 740;
  const pad = { top: 16, right: 12, bottom: 42, left: 48 };
  const plotW = width - pad.left - pad.right;
  const plotH = height - pad.top - pad.bottom;
  const max = Math.max(1, ...groups.flatMap((g) => seriesDefs.map((s) => g[s.key] || 0)));
  const step = Math.pow(10, Math.floor(Math.log10(max)));
  const niceMax = Math.ceil(max / step) * step || 1;

  const groupW = plotW / Math.max(1, groups.length);
  const barW = Math.min(26, (groupW - 12) / seriesDefs.length);
  const root = svg('svg', {
    viewBox: `0 0 ${width} ${height}`, width: '100%', height,
    preserveAspectRatio: 'xMidYMid meet',
    style: 'width:100%;height:auto;display:block',
    role: 'img', 'aria-label': 'Comparison chart',
  });

  for (let i = 0; i <= 4; i += 1) {
    const value = (niceMax / 4) * i;
    const yy = pad.top + plotH - (value / niceMax) * plotH;
    root.appendChild(svg('line', { x1: pad.left, x2: width - pad.right, y1: yy, y2: yy, stroke: 'var(--line-2)' }));
    root.appendChild(svg('text', { x: pad.left - 8, y: yy + 4, 'text-anchor': 'end', 'font-size': 10.5, fill: 'var(--ink-4)' }, compactNumber(value)));
  }

  groups.forEach((group, gi) => {
    const groupX = pad.left + gi * groupW;
    seriesDefs.forEach((s, si) => {
      const value = group[s.key] || 0;
      const barH = (value / niceMax) * plotH;
      const x = groupX + (groupW - barW * seriesDefs.length) / 2 + si * barW;
      root.appendChild(svg('rect', {
        x, y: pad.top + plotH - barH, width: Math.max(4, barW - 3), height: Math.max(1, barH),
        rx: 3, fill: s.color || PALETTE[si % PALETTE.length],
      }, svg('title', {}, `${group.label} · ${s.label}: ${formatValue(value)}`)));
    });
    root.appendChild(svg('text', {
      x: groupX + groupW / 2, y: height - 22, 'text-anchor': 'middle',
      'font-size': 10.5, fill: 'var(--ink-4)',
    }, group.label.length > 14 ? `${group.label.slice(0, 13)}…` : group.label));
  });

  const legend = h('div', { class: 'legend' }, ...seriesDefs.map((s, i) => h('div', { class: 'legend-item' },
    h('span', { class: 'legend-swatch', style: { background: s.color || PALETTE[i % PALETTE.length] } }), s.label)));

  return h('div', null, root, legend);
}
