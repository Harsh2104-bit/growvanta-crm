import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../lib/api.js';
import { useAuth } from '../context/AuthContext.jsx';
import { dateTime } from '../lib/format.js';
import { SCOPE_LABELS, enumLabel } from '../lib/modules.js';
import Alert from '../components/Alert.jsx';
import Icon from '../components/Icon.jsx';

/**
 * Everything on this page comes from the signed-in session: /auth/me returns
 * the user with their effective permissions, the active organisation, the
 * visibility scope and team membership, and /api/health reports the database.
 * Nothing is sampled or placeholder — if a figure is not yet available from a
 * ported endpoint it is not shown.
 */
export default function Dashboard() {
  const { user, organization, teams, scope, platform } = useAuth();
  const [health, setHealth] = useState(null);

  useEffect(() => {
    api
      .get('/api/health')
      .then((res) => setHealth(res.data))
      .catch(() => setHealth({ status: 'unreachable', database: 'down' }));
  }, []);

  const moduleCount = Object.keys(user?.permissions ?? {}).length;
  const actionCount = Object.values(user?.permissions ?? {}).reduce((n, list) => n + list.length, 0);

  return (
    <>
      <div className="page-head">
        <div>
          <h1>
            Welcome, {user?.firstName}
          </h1>
          <div className="sub">
            {organization?.name}
            {organization?.companyType ? ` · ${enumLabel(organization.companyType)}` : ''}
            {organization?.isPlatform ? ' · Platform tier' : ''}
          </div>
        </div>
      </div>

      <div className="kpi-grid" style={{ marginBottom: 18 }}>
        <div className="kpi">
          <div className="k-label">
            <Icon name="shield" size={14} className="" /> Role
          </div>
          <div className="k-value" style={{ fontSize: 20 }}>
            {user?.role?.name ?? '—'}
          </div>
          <div className="k-foot">Level {user?.role?.level ?? '—'}</div>
        </div>

        <div className="kpi">
          <div className="k-label">
            <Icon name="user" size={14} className="" /> Visibility scope
          </div>
          <div className="k-value" style={{ fontSize: 20 }}>
            {SCOPE_LABELS[user?.role?.dataScope] ?? enumLabel(user?.role?.dataScope)}
          </div>
          <div className="k-foot">
            {scope?.unrestricted ? 'Sees every record in the company' : `${scope?.userIds?.length ?? 0} user(s) in view`}
          </div>
        </div>

        <div className="kpi">
          <div className="k-label">
            <Icon name="key" size={14} className="" /> Permissions
          </div>
          <div className="k-value">{actionCount}</div>
          <div className="k-foot">across {moduleCount} modules</div>
        </div>

        <div className="kpi" style={{ '--accent': health?.database === 'up' ? 'var(--success)' : 'var(--danger)' }}>
          <div className="k-label">
            <Icon name="monitor" size={14} className="" /> API &amp; database
          </div>
          <div className="k-value" style={{ fontSize: 20 }}>
            {health ? (health.database === 'up' ? 'Connected' : 'Unreachable') : '…'}
          </div>
          <div className="k-foot">{health?.environment ? `${health.environment} environment` : 'checking…'}</div>
        </div>
      </div>

      <div className="chart-grid half">
        <section className="card">
          <div className="card-head">
            <h3>Your account</h3>
          </div>
          <div className="card-body">
            <div className="info-list">
              <div className="info-row">
                <span className="i-label">Name</span>
                <span className="i-value">{[user?.firstName, user?.lastName].filter(Boolean).join(' ')}</span>
              </div>
              <div className="info-row">
                <span className="i-label">Email</span>
                <span className="i-value">{user?.email}</span>
              </div>
              <div className="info-row">
                <span className="i-label">Designation</span>
                <span className="i-value">{user?.designation || user?.jobTitle || '—'}</span>
              </div>
              <div className="info-row">
                <span className="i-label">Teams</span>
                <span className="i-value">
                  {teams.length ? teams.map((t) => t.name).join(', ') : 'Not in a team'}
                </span>
              </div>
              <div className="info-row">
                <span className="i-label">Two-factor</span>
                <span className="i-value">
                  {user?.twoFactorEnabled ? (
                    <span className="badge badge-success">On</span>
                  ) : (
                    <Link to="/security">Turn on</Link>
                  )}
                </span>
              </div>
              <div className="info-row">
                <span className="i-label">Last sign-in</span>
                <span className="i-value">{dateTime(user?.lastLoginAt)}</span>
              </div>
            </div>
          </div>
        </section>

        <section className="card">
          <div className="card-head">
            <h3>Workspace</h3>
          </div>
          <div className="card-body">
            <div className="info-list">
              <div className="info-row">
                <span className="i-label">Company</span>
                <span className="i-value">{organization?.name}</span>
              </div>
              <div className="info-row">
                <span className="i-label">Type</span>
                <span className="i-value">{enumLabel(organization?.companyType)}</span>
              </div>
              <div className="info-row">
                <span className="i-label">Currency</span>
                <span className="i-value">{organization?.currency}</span>
              </div>
              <div className="info-row">
                <span className="i-label">Time zone</span>
                <span className="i-value">{organization?.timezone}</span>
              </div>
              <div className="info-row">
                <span className="i-label">Shared projects</span>
                <span className="i-value">{organization?.usesGlobalProjects ? 'Yes' : 'No'}</span>
              </div>
              {platform && (
                <div className="info-row">
                  <span className="i-label">Client companies</span>
                  <span className="i-value">{platform.clients.length}</span>
                </div>
              )}
            </div>
          </div>
        </section>
      </div>

      <div style={{ marginTop: 18 }}>
        <Alert tone="info" title="Port in progress">
          This build of the React client covers authentication, the session shell and account security. Lead, project,
          reporting, automation and platform screens are not in this build yet — they are listed in{' '}
          <code className="code-inline">docs/PORT-STATUS.md</code> with the API modules each one needs. No screen here
          displays sample or placeholder data.
        </Alert>
      </div>
    </>
  );
}
