/**
 * Message templating primitives shared by the WhatsApp and Email channels.
 *
 * The rest of the Comms surface (provider calls, send logging, channel health)
 * builds on these; they live at the top of the module because provisioning
 * needs them before any channel is configured.
 */

const VARIABLE_RE = /\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g;

/** Every distinct {{variable}} referenced by a template body, in first-seen order. */
export function templateVariables(body) {
  const found = [];
  for (const match of String(body ?? '').matchAll(VARIABLE_RE)) {
    if (!found.includes(match[1])) found.push(match[1]);
  }
  return found;
}

/**
 * Substitutes {{variables}} from `context`.
 *
 * A variable with no value is replaced with an empty string rather than left
 * as literal `{{customer_name}}` — a customer receiving the raw placeholder is
 * worse than a slightly clipped sentence, and the unmapped-variable check
 * below is what is supposed to catch it before sending.
 */
export function renderTemplate(body, context = {}) {
  return String(body ?? '').replace(VARIABLE_RE, (_, name) => {
    const value = context[name];
    return value === null || value === undefined ? '' : String(value);
  });
}

/**
 * A template is only usable if every variable it references can actually be
 * filled — either it is a known context variable, or the channel config maps
 * it. Returns the unresolvable ones.
 */
export function templateUnmappedVariables(template, variableMap) {
  if (!template) return [];
  const known = new Set(Object.keys(variableMap ?? {}));
  return templateVariables(`${template.body ?? ''} ${template.subject ?? ''}`).filter((v) => !known.has(v));
}
