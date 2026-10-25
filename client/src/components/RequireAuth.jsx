import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext.jsx';
import { PageLoader } from './Spinner.jsx';
import Alert from './Alert.jsx';

/**
 * Route guard. This is convenience, not security — every endpoint behind it
 * re-checks the session and the permission server-side, because a client-side
 * guard is only a redirect and anyone can skip a redirect.
 */
export default function RequireAuth({ children }) {
  const { status, error } = useAuth();
  const location = useLocation();

  if (status === 'loading') return <PageLoader label="Loading Growvanta CRM…" />;

  if (status === 'error') {
    return (
      <div style={{ padding: 40, maxWidth: 560, margin: '0 auto' }}>
        <Alert tone="danger" title="Cannot reach the server">
          {error?.message || 'The API did not respond.'} Check that the Node server is running and that the database
          is reachable.
        </Alert>
      </div>
    );
  }

  if (status !== 'signed-in') {
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  }

  return children;
}
