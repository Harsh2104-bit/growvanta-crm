/**
 * Labelled form control with inline validation text.
 *
 * The server returns 422 with a `details` array keyed by field name; `error`
 * is whatever that array said about this one, so a validation failure lands
 * next to the input that caused it instead of in a banner at the top.
 */
export default function Field({ label, error, hint, required, children, htmlFor }) {
  return (
    <div className="field">
      {label && (
        <label htmlFor={htmlFor}>
          {label} {required && <span className="req-star">*</span>}
        </label>
      )}
      {children}
      {error && <span className="err">{error}</span>}
      {!error && hint && <span className="hint">{hint}</span>}
    </div>
  );
}
