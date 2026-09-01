import type { GraphNode } from '../types/graph'
import { ACCENT_COLORS } from '../utils/graphConstants'
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

interface Props {
  visibleTypes: Set<string>
  counts: Record<string, number>
  onToggle: (type: string) => void
  onShowAll: () => void
  onHideAll: () => void
}

export function FilterPanel({ visibleTypes, counts, onToggle, onShowAll, onHideAll }: Props) {
  const present = ORDER.filter((t) => (counts[t] ?? 0) > 0)

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
          const color = ACCENT_COLORS[type] ?? '#94a3b8'
          return (
            <Tooltip key={type} content={`${checked ? 'Hide' : 'Show'} ${TYPE_LABELS[type] ?? type} nodes`}>
              <button
                type="button"
                className={`show-graph-item ${!checked ? 'show-graph-item--off' : ''}`}
                onClick={() => onToggle(type)}
              >
                <span className="show-graph-dot" style={{ backgroundColor: color }} />
                <span className="show-graph-label">{TYPE_LABELS[type] ?? type}</span>
                <span className="show-graph-count">{count}</span>
              </button>
            </Tooltip>
          )
        })}
      </div>
    </div>
  )
}
