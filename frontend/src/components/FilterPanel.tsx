import type { GraphNode } from '../types/graph'
import { ACCENT_COLORS, REGION_FRAME } from '../utils/graphConstants'
import { Tooltip } from './Tooltip'

const TYPE_LABELS: Partial<Record<GraphNode['type'], string>> = {
  route: 'Routes',
  middleware: 'Middleware',
  controller: 'Controllers',
  livewire_component: 'Livewire',
  action: 'Actions',
  action_class: 'Action classes',
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
  'route', 'middleware', 'controller', 'livewire_component', 'action', 'action_class', 'service',
  'validation_request', 'model', 'event', 'listener', 'job', 'command', 'channel', 'schedule',
  'view', 'mail', 'notification', 'enum', 'interface', 'trait', 'abstract_class',
  'service_provider', 'facade', 'ai_agent', 'ai_tool',
  'filament_panel', 'filament_resource', 'filament_page',
  'filament_page_method', 'filament_widget', 'filament_relation_manager',
]

/**
 * Listed last, and not node types.
 *
 * A region is a boundary drawn around nodes rather than a node, so it has no entry in
 * `GraphNode['type']` and its count is a number of regions, not of nodes. They belong in this
 * list anyway: from the reader's side the question is the same one every other row answers — is
 * this drawn on the graph, and can I turn it off.
 *
 * `rollback` has no row: it is the other half of a transaction's span and is switched with it.
 */
const REGION_TYPES: { type: string; label: string; description: string }[] = [
  { type: 'transaction', label: 'Transactions', description: 'the boundary drawn around work that runs in one transaction' },
  { type: 'chain', label: 'Chains', description: 'the boundary and the arrows drawn around jobs that run one after another' },
  { type: 'batch', label: 'Batches', description: 'the boundary drawn around jobs dispatched together, in no particular order' },
]

interface Props {
  visibleTypes: Set<string>
  counts: Record<string, number>
  onToggle: (type: string) => void
  onShowAll: () => void
  onHideAll: () => void
}

export function FilterPanel({ visibleTypes, counts, onToggle, onShowAll, onHideAll }: Props) {
  const present: string[] = ORDER.filter((t) => (counts[t] ?? 0) > 0)
  const regions = new Map(REGION_TYPES.map((region) => [region.type, region]))

  for (const region of REGION_TYPES) {
    if ((counts[region.type] ?? 0) > 0) present.push(region.type)
  }

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
          const region = regions.get(type)
          const color = region
            ? REGION_FRAME[type] ?? '#94a3b8'
            : ACCENT_COLORS[type] ?? '#94a3b8'
          const label = region?.label ?? TYPE_LABELS[type as GraphNode['type']] ?? type
          return (
            <Tooltip key={type} content={region
              ? `${checked ? 'Hide' : 'Show'} ${region.description}`
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
