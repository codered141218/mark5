import React, { Suspense, lazy } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './auth';
import { ToastProvider, DialogProvider, Loading } from './components/ui';
import Layout, { NAV } from './components/Layout';
import Login from './pages/Login';

const Dashboard = lazy(() => import('./pages/Dashboard'));
const Pos = lazy(() => import('./pages/pos/Pos'));
const Reports = lazy(() => import('./pages/reports/Reports'));
const Items = lazy(() => import('./pages/inventory/Items'));
const ItemEdit = lazy(() => import('./pages/inventory/ItemEdit'));
const Categories = lazy(() => import('./pages/inventory/Categories'));
const Uoms = lazy(() => import('./pages/inventory/Uoms'));
const InvDocs = lazy(() => import('./pages/inventory/InvDocs'));
const InvDocEdit = lazy(() => import('./pages/inventory/InvDocEdit'));
const Counts = lazy(() => import('./pages/inventory/Counts'));
const CountSheet = lazy(() => import('./pages/inventory/CountSheet'));
const PettyCash = lazy(() => import('./pages/finance/PettyCash'));
const Accounts = lazy(() => import('./pages/finance/Accounts'));
const Journals = lazy(() => import('./pages/finance/Journals'));
const Banks = lazy(() => import('./pages/finance/Banks'));
const Payables = lazy(() => import('./pages/finance/Payables'));
const Receivables = lazy(() => import('./pages/finance/Receivables'));
const Partners = lazy(() => import('./pages/finance/Partners'));
const CashAdvances = lazy(() => import('./pages/finance/CashAdvances'));
const Employees = lazy(() => import('./pages/admin/Employees'));
const Users = lazy(() => import('./pages/admin/Users'));
const Roles = lazy(() => import('./pages/admin/Roles'));
const Settings = lazy(() => import('./pages/admin/Settings'));
const Backup = lazy(() => import('./pages/admin/Backup'));
const Audit = lazy(() => import('./pages/admin/Audit'));
const MyAccount = lazy(() => import('./pages/admin/MyAccount'));

function Guard({ perms, children }) {
  const { can } = useAuth();
  if (perms && !can(...perms)) {
    return <div className="alert alert-warn">You do not have permission to open this page. Ask your administrator to update your role.</div>;
  }
  return children;
}

// First page the user is allowed to see.
function Home() {
  const { can } = useAuth();
  if (can('dashboard.view')) return <Dashboard />;
  for (const g of NAV) for (const i of g.items) if (can(...i.perms)) return <Navigate to={i.to} replace />;
  if (can('pos.access')) return <Navigate to="/pos" replace />;
  return <Navigate to="/account" replace />;
}

const P = (perms, el) => <Guard perms={perms}>{el}</Guard>;

function AppRoutes() {
  const { user, loading } = useAuth();
  if (loading) return <Loading />;
  if (!user) return <Login />;
  return (
    <Suspense fallback={<Loading />}>
      <Routes>
        <Route path="/pos" element={P(['pos.access'], <Pos />)} />
        <Route path="*" element={
          <Layout>
            <Suspense fallback={<Loading />}>
              <Routes>
                <Route path="/" element={<Home />} />
                <Route path="/reports/:group" element={<Reports />} />
                <Route path="/inventory/items" element={P(['inventory.view', 'inventory.manage'], <Items />)} />
                <Route path="/inventory/items/:id" element={P(['inventory.view', 'inventory.manage'], <ItemEdit />)} />
                <Route path="/inventory/categories" element={P(['inventory.manage'], <Categories />)} />
                <Route path="/inventory/uom" element={P(['inventory.manage'], <Uoms />)} />
                <Route path="/inventory/receiving" element={P(['inventory.receive'], <InvDocs type="RECEIVE" />)} />
                <Route path="/inventory/receiving/:id" element={P(['inventory.receive'], <InvDocEdit type="RECEIVE" />)} />
                <Route path="/inventory/issuance" element={P(['inventory.issue'], <InvDocs type="ISSUE" />)} />
                <Route path="/inventory/issuance/:id" element={P(['inventory.issue'], <InvDocEdit type="ISSUE" />)} />
                <Route path="/inventory/wastage" element={P(['inventory.waste'], <InvDocs type="WASTE" />)} />
                <Route path="/inventory/wastage/:id" element={P(['inventory.waste'], <InvDocEdit type="WASTE" />)} />
                <Route path="/inventory/counts" element={P(['inventory.count'], <Counts />)} />
                <Route path="/inventory/counts/:id" element={P(['inventory.count'], <CountSheet />)} />
                <Route path="/petty-cash" element={P(['pettycash.view', 'pettycash.manage'], <PettyCash />)} />
                <Route path="/finance/accounts" element={P(['finance.view', 'finance.accounts'], <Accounts />)} />
                <Route path="/finance/journals" element={P(['finance.view', 'finance.journal'], <Journals />)} />
                <Route path="/finance/banks" element={P(['finance.banks'], <Banks />)} />
                <Route path="/finance/payables" element={P(['finance.ap'], <Payables />)} />
                <Route path="/finance/receivables" element={P(['finance.ar'], <Receivables />)} />
                <Route path="/finance/partners" element={P(['partners.manage', 'finance.ap', 'finance.ar'], <Partners />)} />
                <Route path="/cash-advances" element={P(['ca.request', 'ca.approve', 'ca.manage'], <CashAdvances />)} />
                <Route path="/employees" element={P(['employees.manage'], <Employees />)} />
                <Route path="/admin/users" element={P(['admin.users'], <Users />)} />
                <Route path="/admin/roles" element={P(['admin.roles'], <Roles />)} />
                <Route path="/admin/settings" element={P(['admin.settings'], <Settings />)} />
                <Route path="/admin/backup" element={P(['admin.backup'], <Backup />)} />
                <Route path="/admin/audit" element={P(['admin.audit'], <Audit />)} />
                <Route path="/account" element={<MyAccount />} />
                <Route path="*" element={<div className="empty">Page not found.</div>} />
              </Routes>
            </Suspense>
          </Layout>
        } />
      </Routes>
    </Suspense>
  );
}

export default function App() {
  return (
    <BrowserRouter>
      <ToastProvider>
        <DialogProvider>
          <AuthProvider>
            <AppRoutes />
          </AuthProvider>
        </DialogProvider>
      </ToastProvider>
    </BrowserRouter>
  );
}
