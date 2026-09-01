import { useMemo } from 'react'
import type { GraphData, GraphNodeMetrics } from '../types/graph'
import { ccTier, ACCENT_COLORS } from '../utils/graphConstants'
import { Tooltip } from './Tooltip'

interface Props {
  graphData: GraphData | null
  filter: 'all' | 'complex' | 'critical'
  onFilterChange: (f: 'all' | 'complex' | 'critical') => void
  onNodeSelect: (id: string) => void
  selectedId: string | null
}

const COMPLEXITY_TYPES = new Set(['action', 'action_class', 'service', 'validation_request', 'controller', 'job', 'command'])

const FILTER_TITLES: Record<'all' | 'complex' | 'critical', string> = {
  all: 'All analyzed methods with cyclomatic complexity above 0 (actions, services, controllers, jobs, commands).',
  complex: 'Methods with cyclomatic complexity greater than 10 (increasing risk of bugs and hard tests).',
  critical: 'Methods with cyclomatic complexity greater than 15 (very high branching — strong refactor candidate).',
}

export function ComplexityPanel({ graphData, filter, onFilterChange, onNodeSelect, selectedId }: Props) {
  const ranked = useMemo(() => {
    if (!graphData) return []
    return graphData.nodes
      .filter(n => COMPLEXITY_TYPES.has(n.type))
      .map(n => ({
        node: n,
        cc: (n.data?.metrics as GraphNodeMetrics | undefined)?.cyclomaticComplexity ?? 0,
      }))
      .filter(item => {
        if (filter === 'complex') return item.cc > 10
        if (filter === 'critical') return item.cc > 15
        return item.cc > 0
      })
      .sort((a, b) => b.cc - a.cc)
  }, [graphData, filter])

  return (
    <div className="complexity-panel">
      <div className="complexity-filters">
        {(['all', 'complex', 'critical'] as const).map(f => (
          <Tooltip key={f} content={FILTER_TITLES[f]}>
            <button
              type="button"
              className={`complexity-filter-btn ${filter === f ? 'complexity-filter-btn--active' : ''}`}
              onClick={() => onFilterChange(f)}
            >
              {f === 'all' ? 'All' : f === 'complex' ? '>10' : '>15'}
            </button>
          </Tooltip>
        ))}
      </div>

      <Tooltip content="Cyclomatic complexity counts decision points (if, loop, case, &&, ||, catch, etc.) in each method. Sorted highest first.">
        <div className="complexity-summary">
          {ranked.length} method{ranked.length !== 1 ? 's' : ''}
        </div>
      </Tooltip>

      {!graphData && (
        <div className="complexity-empty">Select a route tab to see complexity data.</div>
      )}

      {graphData && ranked.length === 0 && (
        <div className="complexity-empty">No methods match the current filter.</div>
      )}

      <div className="complexity-list">
        {ranked.map(item => {
          const tier = ccTier(item.cc)
          const typeColor = ACCENT_COLORS[item.node.type] ?? '#94a3b8'
          return (
            <Tooltip key={item.node.id} content={`Cyclomatic complexity ${item.cc}. Click to focus this node in the graph.`}>
              <button
                type="button"
                className={`complexity-row ${selectedId === item.node.id ? 'complexity-row--active' : ''}`}
                onClick={() => onNodeSelect(item.node.id)}
              >
                <span
                  className="complexity-badge"
                  style={{ color: tier.border, borderColor: tier.border }}
                >
                  {item.cc}
                </span>
                <span className="complexity-label">{item.node.label}</span>
                <span className="complexity-type" style={{ color: typeColor }}>
                  {item.node.type}
                </span>
              </button>
            </Tooltip>
          )
        })}
      </div>
    </div>
  )
}
