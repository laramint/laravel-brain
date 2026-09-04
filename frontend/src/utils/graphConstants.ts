/**
 * The frame drawn around work that runs inside a database transaction, and around the path that
 * runs only after one rolled back.
 *
 * Deliberately not one of the node-type accents: a transaction is not a kind of thing, it is a
 * condition several things share, and borrowing a type colour would read as "these became
 * services". Amber for the span, because it is a caution rather than a fault; a colder red for
 * the compensation path, which is where a failure has already happened.
 */
export const TRANSACTION_FRAME = '#d99a2b'
export const ROLLBACK_FRAME = '#c2554a'

/**
 * The frames drawn around jobs dispatched together — a chain, and a batch.
 *
 * Muted on purpose, like the two above and for the same reason: a region is a condition its
 * members share, not a type they became, and a saturated frame around a job card competes with
 * the card. Steel for the chain, which runs in order; a colder violet for the batch, which does
 * not. Neither is the job accent (#607D8B), so a boundary is never mistaken for a node's own edge.
 */
export const CHAIN_FRAME = '#5f8fa8'
export const BATCH_FRAME = '#8a7fb5'

/** Frame colour by region kind — the one place a new kind has to be given a colour. */
export const REGION_FRAME: Record<string, string> = {
  transaction: TRANSACTION_FRAME,
  rollback: ROLLBACK_FRAME,
  chain: CHAIN_FRAME,
  batch: BATCH_FRAME,
}

/**
 * The dash each kind's boundary is drawn with.
 *
 * A second channel beside colour, because the frames are thin and dashed and a reader with a
 * colour deficiency would otherwise have only the name to go on. Short dashes for the rollback,
 * which is the anxious one; a long dash for the chain, which reads as a run of links.
 */
export const REGION_DASH: Record<string, string> = {
  transaction: '6 5',
  rollback: '2 4',
  chain: '10 4',
  batch: '4 4',
}

/**
 * Vertical step between two region labels that share an anchor.
 *
 * A node in two regions — a chained job dispatched inside a transaction — has both names
 * written to the same corner of it. Stacking them by a line is what keeps either readable.
 */
export const REGION_LABEL_LINE = 11

export const LARGE_GRAPH_THRESHOLD = 80
export const PACKET_ANIMATION_THRESHOLD = 40

export const ACCENT_COLORS: Record<string, string> = {
  route:      '#4CAF50',
  middleware: '#FF9800',
  controller: '#2196F3',
  livewire_component: '#FB7185',
  action:     '#03A9F4',
  service:    '#9C27B0',
  action_class: '#84cc16',
  validation_request: '#0d9488',
  model:      '#F44336',
  event:      '#FFD600',
  listener:   '#C9A227',
  job:        '#607D8B',
  command:    '#14b8a6',
  channel:    '#8b5cf6',
  schedule:   '#f97316',
  view:       '#ec4899',
  mail:       '#f472b6',
  notification: '#db2777',
  enum:       '#0ea5e9',
  interface:  '#38bdf8',
  trait:      '#a78bfa',
  abstract_class: '#94a3b8',
  service_provider: '#ca8a04',
  facade:     '#00BCD4',
  ai_agent:   '#A3E635',
  ai_tool:    '#65A30D',
  filament_panel:            '#7C3AED',
  filament_resource:         '#A855F7',
  filament_page:             '#C084FC',
  filament_page_method:      '#E879F9',
  filament_widget:           '#06B6D4',
  filament_relation_manager: '#0891B2',
  entry_point:               '#22D3EE',
  entry_point_group:         '#0E7490',
  // Deliberately grey. Everything on the unreached side of the Reachability tab is a
  // question, not a verdict, and a red node reads as one.
  unreached_class:           '#94A3B8',
  unreached_group:           '#475569',
}

/** Darkened accent colours for text / icons on light card backgrounds */
export const ACCENT_COLORS_LIGHT: Record<string, string> = {
  route:      '#2e7d32',
  middleware: '#e65100',
  controller: '#1565c0',
  livewire_component: '#e11d48',
  action:     '#0277bd',
  service:    '#6a1b9a',
  action_class: '#4d7c0f',
  validation_request: '#0f766e',
  model:      '#c62828',
  event:      '#b45309',
  listener:   '#8a6d1f',
  job:        '#37474f',
  command:    '#0d7d6e',
  channel:    '#5b21b6',
  schedule:   '#c2410c',
  view:       '#be185d',
  mail:       '#c026d3',
  notification: '#9d174d',
  enum:       '#0369a1',
  interface:  '#0c4a6e',
  trait:      '#5b21b6',
  abstract_class: '#64748b',
  service_provider: '#a16207',
  facade:     '#00838f',
  ai_agent:   '#4d7c0f',
  ai_tool:    '#3f6212',
  filament_panel:            '#5b21b6',
  filament_resource:         '#7e22ce',
  filament_page:             '#9333ea',
  filament_page_method:      '#a21caf',
  filament_widget:           '#0369a1',
  filament_relation_manager: '#075985',
  entry_point:               '#0E7490',
  entry_point_group:         '#155E75',
  unreached_class:           '#475569',
  unreached_group:           '#334155',
}

/** Dark-mode node background colours (deep tinted darks) */
export const BG_COLORS: Record<string, string> = {
  route:      '#0C1A0C',
  middleware: '#1C1408',
  controller: '#08141C',
  livewire_component: '#1C0711',
  action:     '#07151D',
  service:    '#150C1C',
  action_class: '#131C06',
  validation_request: '#042f2e',
  model:      '#1C0C0C',
  event:      '#1C1A08',
  listener:   '#181405',
  job:        '#0D1113',
  command:    '#061514',
  channel:    '#110c1c',
  schedule:   '#1c1008',
  view:       '#1c0a14',
  mail:       '#1c0f18',
  notification: '#1c0510',
  enum:       '#071318',
  interface:  '#081420',
  trait:      '#140822',
  abstract_class: '#0f172a',
  service_provider: '#422006',
  facade:     '#001F28',
  ai_agent:   '#131A08',
  ai_tool:    '#101705',
  filament_panel:            '#150C2A',
  filament_resource:         '#1A0C26',
  filament_page:             '#1E0F2E',
  filament_page_method:      '#240E30',
  filament_widget:           '#071A1E',
  filament_relation_manager: '#06161A',
  entry_point:               '#04171C',
  entry_point_group:         '#03151A',
  unreached_class:           '#111827',
  unreached_group:           '#0B1120',
}

/** Light-mode node background colours (soft tinted pastels) */
export const BG_COLORS_LIGHT: Record<string, string> = {
  route:      '#f0fdf4',
  middleware: '#fff7ed',
  controller: '#eff6ff',
  livewire_component: '#fff1f2',
  action:     '#e0f7fa',
  service:    '#fdf4ff',
  action_class: '#f7fee7',
  validation_request: '#ccfbf1',
  model:      '#fff1f2',
  event:      '#fefce8',
  listener:   '#fdf6dd',
  job:        '#f1f5f9',
  command:    '#f0fdfa',
  channel:    '#f5f3ff',
  schedule:   '#fff7ed',
  view:       '#fdf2f8',
  mail:       '#fce7f3',
  notification: '#fce7f3',
  enum:       '#f0f9ff',
  interface:  '#ecfeff',
  trait:      '#f5f3ff',
  abstract_class: '#f1f5f9',
  service_provider: '#fef9c3',
  facade:     '#e0f7fa',
  ai_agent:   '#f7fee7',
  ai_tool:    '#ecfccb',
  filament_panel:            '#f5f3ff',
  filament_resource:         '#faf5ff',
  filament_page:             '#fdf4ff',
  filament_page_method:      '#fef0ff',
  filament_widget:           '#ecfeff',
  filament_relation_manager: '#e0f2fe',
  entry_point:               '#ecfeff',
  entry_point_group:         '#cffafe',
  unreached_class:           '#f8fafc',
  unreached_group:           '#f1f5f9',
}

export const HIGHLIGHT_COLOR = '#8B6FE8'

// ── Security Surface Map ─────────────────────────────────────────────────────

/**
 * Route exposure level:
 *   public  – no auth required          (red — danger)
 *   guest   – redirects authed users    (amber — caution)
 *   authed  – requires authentication   (emerald — safe)
 *   admin   – elevated permission       (violet — restricted)
 */
export const SECURITY_EXPOSURE_COLORS: Record<string, { bg: string; border: string; accent: string; label: string }> = {
  public: { bg: '#1c0808', border: '#ef4444', accent: '#f87171', label: 'Public'  },
  guest:  { bg: '#1c1408', border: '#f59e0b', accent: '#fbbf24', label: 'Guest'   },
  authed: { bg: '#081c10', border: '#10b981', accent: '#34d399', label: 'Auth'    },
  admin:  { bg: '#110c1c', border: '#8b5cf6', accent: '#a78bfa', label: 'Admin'   },
}

export const SECURITY_EXPOSURE_COLORS_LIGHT: Record<string, { bg: string; border: string; accent: string; label: string }> = {
  public: { bg: '#fff1f2', border: '#ef4444', accent: '#dc2626', label: 'Public'  },
  guest:  { bg: '#fffbeb', border: '#f59e0b', accent: '#d97706', label: 'Guest'   },
  authed: { bg: '#ecfdf5', border: '#10b981', accent: '#059669', label: 'Auth'    },
  admin:  { bg: '#f5f3ff', border: '#8b5cf6', accent: '#7c3aed', label: 'Admin'   },
}

/** Risk-level severity badge colors (dark mode) */
export const SECURITY_RISK_COLORS: Record<string, string> = {
  none:     '#6b7280',
  low:      '#10b981',
  medium:   '#f59e0b',
  high:     '#f97316',
  critical: '#ef4444',
}

/** Map severity → readable label */
export const SECURITY_SEVERITY_LABELS: Record<string, string> = {
  none:     'No Issues',
  low:      'Low',
  medium:   'Medium',
  high:     'High',
  critical: 'Critical',
}

/** Issue type → icon + readable name */
export const SECURITY_ISSUE_META: Record<string, { icon: string; name: string }> = {
  MISSING_FK_INDEX: { icon: '🔑', name: 'Unindexed foreign key' },
  MASS_ASSIGNMENT:    { icon: '⚡', name: 'Mass Assignment'      },
  UNVALIDATED_INPUT:  { icon: '⚠️', name: 'Unvalidated Input'   },
  MISSING_THROTTLE:   { icon: '🔓', name: 'Missing Throttle'    },
  PUBLIC_WRITE:       { icon: '🌐', name: 'Public Write'         },
  XSS_DIRECT_OUTPUT:  { icon: '💉', name: 'XSS — Direct Output' },
  XSS_HTML_DECODE:    { icon: '🔓', name: 'XSS — HTML Decode'   },
  XSS_BLADE_UNESCAPED:{ icon: '📄', name: 'XSS — Blade {!! !!}' },
  SQL_INJECTION:      { icon: '🛢️', name: 'SQL Injection'       },
  OPEN_REDIRECT:      { icon: '↪️', name: 'Open Redirect'       },
  SSRF:               { icon: '🌐', name: 'SSRF'                },
  DEBUG_CODE:         { icon: '🐞', name: 'Debug Code Leak'     },
  ENV_LEAK:           { icon: '🔑', name: 'Env Leak'            },
  CSRF_BYPASS:        { icon: '🛡️', name: 'CSRF Bypass'         },
  INSECURE_COOKIE:    { icon: '🍪', name: 'Insecure Cookie'     },
  UNSAFE_STORAGE_PATH:{ icon: '📁', name: 'Unsafe Storage Path' },
  FILE_UPLOAD_VALIDATION: { icon: '📎', name: 'File Upload Validation' },
  UNSAFE_AUTH:        { icon: '🚪', name: 'Unsafe Auth'         },
  UNSAFE_CRYPT:       { icon: '🔐', name: 'Unsafe Crypt'        },
  ARTISAN_CALL:       { icon: '⚙️', name: 'Tainted Artisan Call' },
  PROCESS_SHELL:      { icon: '💻', name: 'Shell Injection'     },
  CONFIG_INJECTION:   { icon: '🧩', name: 'Config Injection'    },
  TAINTED_VIEW_NAME:  { icon: '🖼️', name: 'Tainted View Name'   },
  SESSION_FIXATION:   { icon: '🎫', name: 'Session Fixation'    },
  MAIL_TAINTED_HEADER:{ icon: '✉️', name: 'Mail Header Injection' },
}

export const CC_TIERS = [
  { label: 'Low',      min: 1,  max: 5,        fill: '#0d2e1a', border: '#4ade80' },
  { label: 'Moderate', min: 6,  max: 10,       fill: '#2e2200', border: '#facc15' },
  { label: 'High',     min: 11, max: 15,       fill: '#2e1200', border: '#fb923c' },
  { label: 'Critical', min: 16, max: Infinity, fill: '#2e0a0a', border: '#f87171' },
]

export const CC_TIERS_LIGHT = [
  { label: 'Low',      min: 1,  max: 5,        fill: '#f0fdf4', border: '#16a34a' },
  { label: 'Moderate', min: 6,  max: 10,       fill: '#fefce8', border: '#ca8a04' },
  { label: 'High',     min: 11, max: 15,       fill: '#fff7ed', border: '#ea580c' },
  { label: 'Critical', min: 16, max: Infinity, fill: '#fff1f2', border: '#dc2626' },
]

export function ccTier(cc: number, dark = true) {
  const tiers = dark ? CC_TIERS : CC_TIERS_LIGHT
  return tiers.find(t => cc >= t.min && cc <= t.max) ?? tiers[0]
}
