import React, { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';

// ------------------------------------------------------------------ toast notifications
const ToastCtx = createContext(() => {});
export function ToastProvider({ children }) {
  const [items, setItems] = useState([]);
  const push = useCallback((msg, type = 'success') => {
    const id = Math.random().toString(36).slice(2);
    setItems((x) => [...x, { id, msg, type }]);
    setTimeout(() => setItems((x) => x.filter((t) => t.id !== id)), type === 'error' ? 6000 : 3000);
  }, []);
  return (
    <ToastCtx.Provider value={push}>
      {children}
      <div className="toasts">
        {items.map((t) => <div key={t.id} className={`toast toast-${t.type}`}>{t.msg}</div>)}
      </div>
    </ToastCtx.Provider>
  );
}
/** const toast = useToast(); toast('Saved'); toast(err.message, 'error') */
export const useToast = () => useContext(ToastCtx);

// ------------------------------------------------------------------ data loading hook
/** const { data, loading, error, reload } = useApi(() => api.get('/x', params), [deps]) */
export function useApi(fn, deps = []) {
  const [state, setState] = useState({ data: null, loading: true, error: null });
  const seq = useRef(0);
  const load = useCallback(() => {
    const n = ++seq.current;
    setState((s) => ({ ...s, loading: true, error: null }));
    return Promise.resolve()
      .then(fn)
      .then((data) => { if (n === seq.current) setState({ data, loading: false, error: null }); return data; })
      .catch((error) => { if (n === seq.current) setState((s) => ({ ...s, loading: false, error })); });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
  useEffect(() => { load(); }, [load]);
  return { ...state, reload: load, setData: (d) => setState((s) => ({ ...s, data: typeof d === 'function' ? d(s.data) : d })) };
}

// ------------------------------------------------------------------ primitives
export function Button({ variant = 'default', size, className = '', loading, children, ...props }) {
  return (
    <button className={`btn btn-${variant} ${size ? 'btn-' + size : ''} ${className}`} disabled={loading || props.disabled} {...props}>
      {loading ? <span className="spinner" /> : null}
      {children}
    </button>
  );
}

export function Modal({ title, onClose, children, footer, width = 560, className = '' }) {
  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Escape') onClose && onClose(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);
  return (
    <div className="modal-backdrop" onMouseDown={(e) => { if (e.target === e.currentTarget && onClose) onClose(); }}>
      <div className={`modal ${className}`} style={{ maxWidth: width }}>
        <div className="modal-head">
          <h3>{title}</h3>
          {onClose && <button className="icon-btn" onClick={onClose} aria-label="Close">✕</button>}
        </div>
        <div className="modal-body">{children}</div>
        {footer && <div className="modal-foot">{footer}</div>}
      </div>
    </div>
  );
}

export function Field({ label, hint, children, className = '', span }) {
  return (
    <label className={`field ${className}`} style={span ? { gridColumn: `span ${span}` } : undefined}>
      {label && <span className="field-label">{label}</span>}
      {children}
      {hint && <span className="field-hint">{hint}</span>}
    </label>
  );
}

export const Input = React.forwardRef(function Input(props, ref) {
  return <input ref={ref} className="input" {...props} value={props.value ?? ''} />;
});

export function NumberInput({ value, onChange, ...props }) {
  return (
    <input className="input num" type="number" step="any" inputMode="decimal" value={value ?? ''}
      onChange={(e) => onChange(e.target.value)} onFocus={(e) => e.target.select()} {...props} />
  );
}

export function Select({ options = [], value, onChange, placeholder, ...props }) {
  return (
    <select className="input" value={value ?? ''} onChange={(e) => onChange(e.target.value)} {...props}>
      {placeholder !== undefined && <option value="">{placeholder}</option>}
      {options.map((o) => (
        <option key={o.value} value={o.value}>{o.label}</option>
      ))}
    </select>
  );
}

export function Textarea(props) {
  return <textarea className="input" rows={3} {...props} value={props.value ?? ''} />;
}

export function Checkbox({ checked, onChange, label }) {
  return (
    <label className="checkbox">
      <input type="checkbox" checked={!!checked} onChange={(e) => onChange(e.target.checked)} />
      <span>{label}</span>
    </label>
  );
}

const BADGE = {
  posted: 'green', paid: 'green', approved: 'blue', settled: 'green', open: 'amber', partial: 'amber', draft: 'gray',
  pending: 'amber', void: 'red', cancelled: 'red', rejected: 'red', closed: 'gray', OK: 'green', REORDER: 'amber', NEGATIVE: 'red',
};
export function Badge({ children, color }) {
  return <span className={`badge badge-${color || BADGE[children] || 'gray'}`}>{children}</span>;
}

export function Tabs({ tabs, value, onChange }) {
  return (
    <div className="tabs">
      {tabs.map((t) => (
        <button key={t.value} className={`tab ${value === t.value ? 'active' : ''}`} onClick={() => onChange(t.value)}>{t.label}</button>
      ))}
    </div>
  );
}

export function PageHeader({ title, subtitle, actions, children }) {
  return (
    <div className="page-header">
      <div>
        <h1>{title}</h1>
        {subtitle && <p className="muted">{subtitle}</p>}
      </div>
      <div className="page-actions">{actions}{children}</div>
    </div>
  );
}

export function Card({ title, actions, children, className = '', pad = true }) {
  return (
    <div className={`card ${className}`}>
      {(title || actions) && (
        <div className="card-head">
          <h3>{title}</h3>
          <div className="row gap-sm">{actions}</div>
        </div>
      )}
      <div className={pad ? 'card-body' : ''}>{children}</div>
    </div>
  );
}

export function Stat({ label, value, sub, tone }) {
  return (
    <div className={`stat ${tone ? 'stat-' + tone : ''}`}>
      <div className="stat-label">{label}</div>
      <div className="stat-value">{value}</div>
      {sub && <div className="stat-sub">{sub}</div>}
    </div>
  );
}

export function Empty({ children = 'No records found.' }) {
  return <div className="empty">{children}</div>;
}

export function Loading() {
  return <div className="empty"><span className="spinner dark" /> Loading…</div>;
}

export function ErrorBox({ error }) {
  if (!error) return null;
  return <div className="alert alert-error">{error.message || String(error)}</div>;
}

// ------------------------------------------------------------------ confirm / prompt dialogs
const DialogCtx = createContext(null);
export function DialogProvider({ children }) {
  const [dlg, setDlg] = useState(null);
  const [val, setVal] = useState('');
  const [pin, setPin] = useState('');
  const ask = useCallback((opts) => new Promise((resolve) => {
    setVal(opts.defaultValue || '');
    setPin('');
    setDlg({ ...opts, resolve });
  }), []);
  const close = (result) => { if (dlg) dlg.resolve(result); setDlg(null); };
  return (
    <DialogCtx.Provider value={ask}>
      {children}
      {dlg && (
        <Modal title={dlg.title || 'Confirm'} onClose={() => close(null)} width={440}
          footer={<>
            <Button onClick={() => close(null)}>Cancel</Button>
            <Button variant={dlg.danger ? 'danger' : 'primary'} onClick={() => {
              if (dlg.input && dlg.required && !val.trim()) return;
              close(dlg.input || dlg.pin ? { value: val, pin } : true);
            }}>{dlg.okText || 'OK'}</Button>
          </>}>
          {dlg.message && <p style={{ marginTop: 0 }}>{dlg.message}</p>}
          {dlg.input && (
            <Field label={dlg.input}>
              <Input autoFocus value={val} onChange={(e) => setVal(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && !dlg.pin) close({ value: val, pin }); }} />
            </Field>
          )}
          {dlg.pin && (
            <Field label="Manager PIN (if required)" hint="Leave blank if you are authorized for this action.">
              <Input type="password" inputMode="numeric" value={pin} onChange={(e) => setPin(e.target.value)} />
            </Field>
          )}
        </Modal>
      )}
    </DialogCtx.Provider>
  );
}
/**
 * const dialog = useDialog();
 * if (await dialog({ title, message, danger: true })) ...
 * const r = await dialog({ title, input: 'Reason', required: true, pin: true }); // -> { value, pin } | null
 */
export const useDialog = () => useContext(DialogCtx);
