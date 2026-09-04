import type { GraphNode } from '../types/graph'
import { ACCENT_COLORS, TRANSACTION_FRAME } from '../utils/graphConstants'
import { Tooltip } from './Tooltip'

const TYPE_LABELS: Partial<Record<GraphNode['type'], string>> = {
  route: 'Routes',
  middleware: 'Middleware',
  controller: 'Controllers',
  livewire_component: 'Livewire',
  action: 'Actions',
  service: 'Services',
  validation_request: 'Validation',
  model: 'Models',
  event: 'Events',
  listener: 'Listeners',
  job: 'Jobs',
  command: 'Commands',
  channel: 'Channels',
  schedule: 'Schedules',
  view: 'Views',
  mail: 'Mail',
  notification: 'Notifications',
  enum: 'Enums',
  interface: 'Interfaces',
  trait: 'Traits',
  abstract_class: 'Abstract',
  service_provider: 'Providers',
  facade: 'Facades',
  ai_agent: 'AI Agents',
  ai_tool: 'AI Tools',
  filament_panel: 'F. Panels',
  filament_resource: 'F. Resources',
  filament_page: 'F. Pages',
  filament_page_method: 'F. Methods',
  filament_widget: 'F. Widgets',
  filament_relation_manager: 'F. Relations',
}

// Stable order matching App.tsx ALL_TYPES
const ORDER: GraphNode['type'][] = [
  'route', 'middleware', 'controller', 'livewire_component', 'action', 'service',
  'validation_request', 'model', 'event', 'listener', 'job', 'command', 'channel', 'schedule',
  'view', 'mail', 'notification', 'enum', 'interface', 'trait', 'abstract_class',
  'service_provider', 'facade', 'ai_agent', 'ai_tool',
  'filament_panel', 'filament_resource', 'filament_page',
  'filament_page_method', 'filament_widget', 'filament_relation_manager',
]

/**
 * Listed last, and not a node type.
 *
 * A transaction is a region drawn around nodes rather than a node, so it has no entry in
 * `GraphNode['type']` and its count is a number of spans, not of nodes. It belongs in this list
 * anyway: from the reader's side the question is the same one every other row answers — is this
 * drawn on the graph, and can I turn it off.
 */
const REGION_TYPE = 'transaction'

interface Props {
  visibleTypes: Set<string>
  counts: Record<string, number>
  onToggle: (type: string) => void
  onShowAll: () => void
  onHideAll: () => void
}

export function FilterPanel({ visibleTypes, counts, onToggle, onShowAll, onHideAll }: Props) {
  const present: string[] = ORDER.filter((t) => (counts[t] ?? 0) > 0)

  if ((counts[REGION_TYPE] ?? 0) > 0) present.push(REGION_TYPE)

  return (
    <div className="show-graph">
      <div className="show-graph-header">
        <span className="show-graph-title">Show on graph</span>
        <div className="show-graph-actions">
          <button type="button" onClick={onShowAll} className="show-graph-link">All</button>
          <span className="show-graph-sep">/</span>
          <button type="button" onClick={onHideAll} className="show-graph-link">None</button>
        </div>
      </div>
      <div className="show-graph-grid">
        {present.map((type) => {
          const count = counts[type] ?? 0
          const checked = visibleTypes.has(type)
          const color = type === REGION_TYPE
            ? TRANSACTION_FRAME
            : ACCENT_COLORS[type] ?? '#94a3b8'
          const label = type === REGION_TYPE
            ? 'Transactions'
            : TYPE_LABELS[type as GraphNode['type']] ?? type
          return (
            <Tooltip key={type} content={type === REGION_TYPE
              ? `${checked ? 'Hide' : 'Show'} the boundary drawn around work that runs in one transaction`
              : `${checked ? 'Hide' : 'Show'} ${label} nodes`}>
              <button
                type="button"
                className={`show-graph-item ${!checked ? 'show-graph-item--off' : ''}`}
                onClick={() => onToggle(type)}
              >
                <span className="show-graph-dot" style={{ backgroundColor: color }} />
                <span className="show-graph-label">{label}</span>
                <span className="show-graph-count">{count}</span>
              </button>
            </Tooltip>
          )
        })}
      </div>
    </div>
  )
}
