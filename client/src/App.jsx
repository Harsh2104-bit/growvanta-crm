import { Navigate, Route, Routes } from 'react-router-dom';

import AppShell from './components/AppShell.jsx';
import RequireAuth from './components/RequireAuth.jsx';

import AcceptInvite from './pages/AcceptInvite.jsx';
import AuthLayout from './pages/AuthLayout.jsx';
import Dashboard from './pages/Dashboard.jsx';
import ForgotPassword from './pages/ForgotPassword.jsx';
import Login from './pages/Login.jsx';
import NotFound from './pages/NotFound.jsx';
import Profile from './pages/Profile.jsx';
import Register from './pages/Register.jsx';
import ResetPassword from './pages/ResetPassword.jsx';
import Security from './pages/Security.jsx';
import Sessions from './pages/Sessions.jsx';
import VerifyEmail from './pages/VerifyEmail.jsx';

/**
 * Route table.
 *
 * Two shells: the split-screen brand panel for anything a signed-out visitor
 * can reach, and the sidebar application for everything behind a session.
 * `RequireAuth` only redirects — the server re-checks the session and the
 * permission on every call it serves.
 */
export default function App() {
  return (
    <Routes>
      <Route element={<AuthLayout />}>
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/reset-password" element={<ResetPassword />} />
        <Route path="/accept-invite" element={<AcceptInvite />} />
        <Route path="/verify-email" element={<VerifyEmail />} />
      </Route>

      <Route
        element={
          <RequireAuth>
            <AppShell />
          </RequireAuth>
        }
      >
        <Route index element={<Dashboard />} />
        <Route path="/profile" element={<Profile />} />
        <Route path="/security" element={<Security />} />
        <Route path="/sessions" element={<Sessions />} />
        <Route path="*" element={<NotFound />} />
      </Route>

      <Route path="/index.html" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
