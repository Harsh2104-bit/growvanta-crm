import { useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext.jsx';
import { useTheme } from '../context/ThemeContext.jsx';
import { useToast } from '../context/ToastContext.jsx';
import { avatarColor, initials } from '../lib/format.js';
import Icon from './Icon.jsx';

/**
 * The signed-in chrome: navy sidebar, sticky topbar, routed content.
 *
 * Navigation is built from what the signed-in user can actually reach. An item
 * whose `permission` the user does not hold is not rendered at all — the
 * server would refuse the call anyway, and a dead link is worse than an absent
 * one. Items with no `permission` are available to every signed-in user.
 */
const NAV = [
  { section: 'Overview' },
  { to: '/', label: 'Dashboard', icon: 'dashboard', end: true },
  { section: 'Account' },
  { to: '/profile', label: 'My profile', icon: 'user' },
  { to: '/security', label: 'Security', icon: 'shield' },
  { to: '/sessions', label: 'Active sessions', icon: 'monitor' },
];

export default function AppShell() {
  const { user, organization, signOut } = useAuth();
  const { theme, toggle } = useTheme();
  const toast = useToast();
  const navigate = useNavigate();
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);

  const fullName = [user?.firstName, user?.lastName].filter(Boolean).join(' ');

  async function handleSignOut() {
    try {
      await signOut();
      navigate('/login', { replace: true });
    } catch (err) {
      toast.error('Could not sign out', err.message);
    }
  }

  const classes = ['app', collapsed && 'collapsed', mobileOpen && 'mobile-open'].filter(Boolean).join(' ');

  return (
    <div className={classes}>
      <aside className="sidebar">
        <div className="brand">
          <img className="brand-mark" src="/assets/logo-mark.png" alt="" width="32" height="32" />
          <span className="brand-text">
            GROW<span>VANTA</span>
          </span>
        </div>

        <nav className="nav">
          {NAV.map((item, i) =>
            item.section ? (
              <div key={`s${i}`} className="nav-section-title">
                {item.section}
              </div>
            ) : (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) => `nav-item${isActive ? ' active' : ''}`}
                onClick={() => setMobileOpen(false)}
              >
                <Icon name={item.icon} />
                <span className="nav-label">{item.label}</span>
              </NavLink>
            )
          )}
        </nav>

        <div className="sidebar-footer">
          <div className="u-card" onClick={() => navigate('/profile')}>
            <span
              className="avatar"
              style={{ background: avatarColor(user?.email || ''), color: '#fff' }}
              aria-hidden="true"
            >
              {initials(user?.firstName, user?.lastName)}
            </span>
            <div className="u-meta truncate">
              <div className="u-name truncate">{fullName || user?.email}</div>
              <div className="u-role truncate">{user?.role?.name || '—'}</div>
            </div>
          </div>
        </div>
      </aside>

      <div className="main">
        <header className="topbar">
          <button
            type="button"
            className="icon-btn mobile-only"
            onClick={() => setMobileOpen((v) => !v)}
            aria-label="Toggle navigation"
          >
            <Icon name="menu" />
          </button>
          <button
            type="button"
            className="icon-btn desk-only"
            onClick={() => setCollapsed((v) => !v)}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            <Icon name="menu" />
          </button>

          <div className="tenant-switcher row" style={{ gap: 8 }}>
            <Icon name="building" size={16} />
            <span className="tenant-name truncate strong">{organization?.name || '—'}</span>
          </div>

          <div className="grow" />

          <button
            type="button"
            className="icon-btn"
            onClick={toggle}
            aria-label={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
            title={theme === 'dark' ? 'Light theme' : 'Dark theme'}
          >
            <Icon name={theme === 'dark' ? 'sun' : 'moon'} />
          </button>
          <button type="button" className="icon-btn" onClick={handleSignOut} aria-label="Sign out" title="Sign out">
            <Icon name="logout" />
          </button>
        </header>

        <main className="content">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
