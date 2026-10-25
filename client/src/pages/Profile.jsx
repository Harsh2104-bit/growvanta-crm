import { useAuth } from '../context/AuthContext.jsx';
import { avatarColor, dateTime, initials } from '../lib/format.js';
import Alert from '../components/Alert.jsx';
import { MODULE_LABELS, SCOPE_LABELS, enumLabel } from '../lib/modules.js';

/**
 * Read-only account view.
 *
 * The permission matrix is the server's own answer: /auth/me returns effective
 * permissions, meaning the role grant with per-user GRANT/REVOKE overrides
 * already applied, plus the data scope resolved per action. Editing a profile
 * is part of the users module, which this build does not include yet, so this
 * page does not offer a save button it could not honour.
 */
export default function Profile() {
  const { user, organization, teams, scope } = useAuth();
  const permissions = user?.permissions ?? {};
  const scopes = user?.permissionScopes ?? {};
  const special = user?.specialPermissions ?? [];
  const modules = Object.keys(permissions).sort();

  const teamList = teams.map((t) => t.name + ' (' + enumLabel(t.role) + ')').join(', ');

  return (
    <>
      <div className="page-head">
        <div>
          <h1>My profile</h1>
          <div className="sub">What this account is, and exactly what it can reach.</div>
        </div>
      </div>

      <section className="card" style={{ marginBottom: 16 }}>
        <div className="card-body">
          <div className="row" style={{ gap: 14, alignItems: 'center' }}>
            <span className="avatar xl" style={{ background: avatarColor(user?.email || '') }} aria-hidden="true">
              {initials(user?.firstName, user?.lastName)}
            </span>
            <div style={{ minWidth: 0 }}>
              <h2 className="truncate">{[user?.firstName, user?.lastName].filter(Boolean).join(' ')}</h2>
              <div className="muted small">{user?.designation || user?.jobTitle || '—'}</div>
              <div className="row" style={{ gap: 6, marginTop: 6 }}>
                <span className="badge badge-brand">{user?.role?.name ?? '—'}</span>
                <span className="badge">{SCOPE_LABELS[user?.role?.dataScope] ?? enumLabel(user?.role?.dataScope)}</span>
                {user?.isPlatformAdmin && <span className="badge badge-warning">Platform admin</span>}
              </div>
            </div>
          </div>
        </div>
      </section>

      <div className="chart-grid half" style={{ marginBottom: 16 }}>
        <section className="card">
          <div className="card-head">
            <h3>Details</h3>
          </div>
          <div className="card-body">
            <div className="info-list">
              <div className="info-row">
                <span className="i-label">Email</span>
                <span className="i-value">{user?.email}</span>
              </div>
              <div className="info-row">
                <span className="i-label">Phone</span>
                <span className="i-value">{user?.phone || '—'}</span>
              </div>
              <div className="info-row">
                <span className="i-label">Region</span>
                <span className="i-value">{user?.region || '—'}</span>
              </div>
              <div className="info-row">
                <span className="i-label">Teams</span>
                <span className="i-value">{teams.length ? teamList : 'Not in a team'}</span>
              </div>
              <div className="info-row">
                <span className="i-label">Last sign-in</span>
                <span className="i-value">{dateTime(user?.lastLoginAt)}</span>
              </div>
              <div className="info-row">
                <span className="i-label">User ID</span>
                <span className="i-value mono tiny">{user?.id}</span>
              </div>
            </div>
          </div>
        </section>

        <section className="card">
          <div className="card-head">
            <h3>Visibility</h3>
          </div>
          <div className="card-body">
            <div className="info-list">
              <div className="info-row">
                <span className="i-label">Scope</span>
                <span className="i-value">
                  {SCOPE_LABELS[scope?.scope ?? user?.role?.dataScope] ?? enumLabel(scope?.scope)}
                </span>
              </div>
              <div className="info-row">
                <span className="i-label">Reach</span>
                <span className="i-value">
                  {scope?.unrestricted
                    ? 'Every record in this company'
                    : (scope?.userIds?.length ?? 0) + ' user(s), ' + (scope?.teamIds?.length ?? 0) + ' team(s)'}
                </span>
              </div>
              <div className="info-row">
                <span className="i-label">Unassigned records</span>
                <span className="i-value">{scope?.canSeeUnassigned ? 'Visible' : 'Hidden'}</span>
              </div>
              <div className="info-row">
                <span className="i-label">Workspace</span>
                <span className="i-value">{organization?.name}</span>
              </div>
              <div className="info-row">
                <span className="i-label">Home workspace</span>
                <span className="i-value">
                  {user?.homeOrganizationId === organization?.id ? 'This one' : user?.homeOrganizationId}
                </span>
              </div>
            </div>
          </div>
        </section>
      </div>

      <section className="card" style={{ marginBottom: 16 }}>
        <div className="card-head">
          <h3>Effective permissions</h3>
          <span className="muted small">Role grant with personal overrides applied</span>
        </div>
        <div className="card-body">
          {modules.length === 0 ? (
            <p className="muted">This account holds no module permissions.</p>
          ) : (
            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Module</th>
                    <th>Actions</th>
                    <th>Data scope</th>
                  </tr>
                </thead>
                <tbody>
                  {modules.map((mod) => {
                    const actions = [...permissions[mod]].sort();
                    const modScopes = [...new Set(Object.values(scopes[mod] ?? {}))];
                    return (
                      <tr key={mod}>
                        <td className="strong">{MODULE_LABELS[mod] ?? enumLabel(mod)}</td>
                        <td>
                          <div className="row wrap" style={{ gap: 5 }}>
                            {actions.map((a) => (
                              <span key={a} className="chip">
                                {a}
                              </span>
                            ))}
                          </div>
                        </td>
                        <td className="nowrap">
                          {modScopes.length ? modScopes.map((s) => enumLabel(s)).join(', ') : '—'}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </section>

      {special.length > 0 && (
        <section className="card">
          <div className="card-head">
            <h3>Personal overrides</h3>
          </div>
          <div className="card-body">
            <div className="table-wrap">
              <table className="data">
                <thead>
                  <tr>
                    <th>Permission</th>
                    <th>Effect</th>
                    <th>Scope</th>
                    <th>Reason</th>
                    <th>Expires</th>
                  </tr>
                </thead>
                <tbody>
                  {special.map((s) => (
                    <tr key={s.module + ':' + s.action}>
                      <td className="mono tiny">
                        {s.module}:{s.action}
                      </td>
                      <td>
                        <span className={`badge ${s.effect === 'REVOKE' ? 'badge-danger' : 'badge-success'}`}>
                          {enumLabel(s.effect)}
                        </span>
                      </td>
                      <td>{s.dataScope ? enumLabel(s.dataScope) : '—'}</td>
                      <td>{s.reason || '—'}</td>
                      <td className="nowrap">{s.expiresAt ? dateTime(s.expiresAt) : 'Never'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </section>
      )}

      <div style={{ marginTop: 16 }}>
        <Alert tone="info">
          Editing a profile and managing other users belong to the users module, which is not part of this build.
        </Alert>
      </div>
    </>
  );
}
