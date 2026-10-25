import Icon from './Icon.jsx';

/** tone: 'info' | 'success' | 'warning' | 'danger' */
export default function Alert({ tone = 'info', title, children }) {
  return (
    <div className={`alert ${tone}`} role={tone === 'danger' ? 'alert' : 'status'}>
      <Icon name={tone === 'success' ? 'check' : 'alert'} size={16} className="" />
      <div>
        {title && <strong>{title}</strong>}
        {children}
      </div>
    </div>
  );
}
