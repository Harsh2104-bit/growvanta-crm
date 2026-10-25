import { Link } from 'react-router-dom';
import Icon from '../components/Icon.jsx';

export default function NotFound() {
  return (
    <div className="empty" style={{ paddingTop: 80 }}>
      <div className="e-icon">
        <Icon name="alert" size={22} className="" />
      </div>
      <h3>Page not found</h3>
      <p>That route does not exist in this build.</p>
      <Link className="btn btn-primary" to="/">
        Back to dashboard
      </Link>
    </div>
  );
}
