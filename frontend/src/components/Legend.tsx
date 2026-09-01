const NODE_TYPES = [
  { type: 'route', label: 'Route', color: '#4CAF50' },
  { type: 'middleware', label: 'Middleware', color: '#FF9800' },
  { type: 'controller', label: 'Controller', color: '#2196F3' },
  { type: 'action', label: 'Controller action', color: '#03A9F4' },
  { type: 'action_class', label: 'Action', color: '#84cc16' },
  { type: 'service', label: 'Service', color: '#9C27B0' },
  { type: 'validation_request', label: 'Validation request', color: '#0d9488' },
  { type: 'model', label: 'Model', color: '#F44336' },
  { type: 'event', label: 'Event', color: '#FFD600' },
  { type: 'job', label: 'Job', color: '#607D8B' },
  { type: 'view', label: 'View', color: '#ec4899' },
  { type: 'mail', label: 'Mail', color: '#f472b6' },
  { type: 'notification', label: 'Notification', color: '#db2777' },
  { type: 'enum', label: 'Enum', color: '#0ea5e9' },
  { type: 'interface', label: 'Interface', color: '#38bdf8' },
  { type: 'trait', label: 'Trait', color: '#a78bfa' },
  { type: 'abstract_class', label: 'Abstract class', color: '#94a3b8' },
  { type: 'service_provider', label: 'Service provider', color: '#ca8a04' },
  { type: 'facade', label: 'Facade', color: '#00BCD4' },
  { type: 'ai_agent', label: 'AI Agent', color: '#A3E635' },
  { type: 'ai_tool', label: 'AI Tool', color: '#65A30D' },
  { type: 'filament_panel',            label: 'Filament Panel',    color: '#7C3AED' },
  { type: 'filament_resource',         label: 'Filament Resource', color: '#A855F7' },
  { type: 'filament_page',             label: 'Filament Page',     color: '#C084FC' },
  { type: 'filament_widget',           label: 'Filament Widget',   color: '#06B6D4' },
  { type: 'filament_relation_manager', label: 'Relation Manager',  color: '#0891B2' },
  { type: 'entry_point',               label: 'Entry point',       color: '#22D3EE' },
  { type: 'unreached_class',           label: 'Not reached',       color: '#94A3B8' },
]

export function Legend() {
  return (
    <div className="legend">
      {NODE_TYPES.map(({ type, label, color }) => (
        <div key={type} className="legend-item">
          <span className="legend-dot" style={{ backgroundColor: color }} />
          <span className="legend-label">{label}</span>
        </div>
      ))}
    </div>
  )
}
