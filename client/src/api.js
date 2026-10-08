// Small fetch wrapper for the Mark5 API.
const TOKEN_KEY = 'mark5_token';

export function getToken() {
  try { return localStorage.getItem(TOKEN_KEY); } catch { return null; }
}
export function setToken(t) {
  try {
    if (t) localStorage.setItem(TOKEN_KEY, t);
    else localStorage.removeItem(TOKEN_KEY);
  } catch { /* storage unavailable */ }
}

let onUnauthorized = () => {};
export function setUnauthorizedHandler(fn) { onUnauthorized = fn; }

async function request(method, url, body, opts = {}) {
  const headers = { ...(opts.headers || {}) };
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  let payload = body;
  if (body !== undefined && !(body instanceof Blob) && !(body instanceof ArrayBuffer)) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }
  const res = await fetch(`/api${url}`, { method, headers, body: payload });
  if (res.status === 401 && !url.startsWith('/auth/login')) {
    setToken(null);
    onUnauthorized();
  }
  if (opts.raw) {
    if (!res.ok) {
      let msg = res.statusText;
      try { msg = (await res.json()).error || msg; } catch { /* not json */ }
      throw new Error(msg);
    }
    return res;
  }
  const ct = res.headers.get('content-type') || '';
  const data = ct.includes('application/json') ? await res.json() : await res.text();
  if (!res.ok) throw new Error((data && data.error) || res.statusText || 'Request failed');
  return data;
}

export const api = {
  get: (url, params) => {
    const qs = params ? '?' + new URLSearchParams(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '')).toString() : '';
    return request('GET', url + qs);
  },
  post: (url, body) => request('POST', url, body ?? {}),
  put: (url, body) => request('PUT', url, body ?? {}),
  del: (url, body) => request('DELETE', url, body),
  upload: (url, file) => request('POST', url, file, { headers: { 'Content-Type': 'application/octet-stream' } }),
  raw: (method, url, body) => request(method, url, body, { raw: true }),
};

// Download a file from an authenticated endpoint.
export async function download(method, url, body, filename) {
  const res = await api.raw(method, url, body);
  const blob = await res.blob();
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}

/**
 * Export rows to Excel via the server.
 * columns: [{ key, label, type: 'money'|'number'|'qty'|'percent'|'text'|'date' }]
 */
export function exportExcel({ filename, title, subtitle, columns, rows, totals }) {
  const cols = columns.filter((c) => !c.noExport).map((c) => ({ key: c.key, label: c.label, type: c.type }));
  const data = rows.map((r) => {
    const o = {};
    for (const c of columns) {
      if (c.noExport) continue;
      o[c.key] = c.exportValue ? c.exportValue(r) : r[c.key];
    }
    if (r._bold) o._bold = true;
    if (r._indent) o._indent = r._indent;
    return o;
  });
  return download('POST', '/reports/export/xlsx', { filename, title, subtitle, columns: cols, rows: data, totals }, `${filename}.xlsx`);
}
