import React, { useMemo, useRef, useCallback, useState } from 'react'
import type { GraphData, GraphNode, GraphEdge, FlowStep, DbQuery } from '../types/graph'
import { SECURITY_EXPOSURE_COLORS, SECURITY_EXPOSURE_COLORS_LIGHT, SECURITY_RISK_COLORS, SECURITY_ISSUE_META, SECURITY_SEVERITY_LABELS } from '../utils/graphConstants'
import { FlowchartView } from './FlowchartView'
import { FlowchartModal } from './FlowchartModal'
import { SourceView } from './SourceView'
import { SourceModal } from './SourceModal'
import { UsagesView } from './UsagesView'
import { StressTestPanel } from './StressTestPanel'
import { SequenceDiagramView } from './SequenceDiagramView'
import { SequenceDiagramModal } from './SequenceDiagramModal'
import { buildSequenceDiagram } from '../utils/sequenceUtils'
import { Tooltip } from './Tooltip'

const MIN_WIDTH = 360
const MAX_WIDTH = 640
const DEFAULT_WIDTH = 380

interface Props {
  selectedId: string | null
  graphData: GraphData | null
  theme: 'dark' | 'light'
  onClose: () => void
  onStressChange: (nodeId: string | null) => void
}

const TYPE_COLORS: Record<string, string> = {
  route:      '#4CAF50',
  middleware: '#FF9800',
  controller: '#2196F3',
  action:     '#03A9F4',
  service:    '#9C27B0',
  validation_request: '#0d9488',
  model:      '#F44336',
  event:      '#FFD600',
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
  filament_panel:            '#7C3AED',
  filament_resource:         '#A855F7',
  filament_page:             '#C084FC',
  filament_page_method:      '#E879F9',
  filament_widget:           '#06B6D4',
  filament_relation_manager: '#0891B2',
}

type TabId = 'info' | 'risks' | 'schema' | 'flow' | 'source' | 'edges' | 'usages' | 'stress'

/** Bytes as the unit a person would say out loud: 28.8 MB, not 30220288. */
function formatBytes(bytes: number | null): string {
  if (bytes === null) return '—'
  if (bytes < 1024) return `${bytes} B`

  const units = ['KB', 'MB', 'GB', 'TB']
  let value = bytes / 1024
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }

  return `${value < 10 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`
}

/**
 * A row count, marked as approximate when it is. Most engines report the planner's estimate
 * rather than a counted figure, and the difference matters enough to show — an exact count would
 * have meant a sequential scan of every table in the application.
 */
function formatRows(rows: number | null, estimated: boolean): string {
  if (rows === null) return '—'

  return `${estimated ? '~' : ''}${rows.toLocaleString('en-US')}`
}

export function Sidebar({ selectedId, graphData, theme, onClose, onStressChange }: Props) {
  const [width, setWidth] = useState(DEFAULT_WIDTH)
  const isDragging = useRef(false)
  const startX = useRef(0)
  const startWidth = useRef(DEFAULT_WIDTH)

  const onMouseDown = useCallback((e: React.MouseEvent) => {
    e.preventDefault()
    isDragging.current = true
    startX.current = e.clientX
    startWidth.current = width

    const onMove = (ev: MouseEvent) => {
      if (!isDragging.current) return
      const delta = startX.current - ev.clientX
      const next = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, startWidth.current + delta))
      setWidth(next)
    }
    const onUp = () => {
      isDragging.current = false
      window.removeEventListener('mousemove', onMove)
      window.removeEventListener('mouseup', onUp)
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }

    document.body.style.cursor = 'col-resize'
    document.body.style.userSelect = 'none'
    window.addEventListener('mousemove', onMove)
    window.addEventListener('mouseup', onUp)
  }, [width])

  const [activeTab, setActiveTab] = useState<TabId>('info')
  const [isFlowModalOpen, setIsFlowModalOpen] = useState(false)
  const [isSourceModalOpen, setIsSourceModalOpen] = useState(false)
  const [isSeqModalOpen, setIsSeqModalOpen] = useState(false)
  const [aiCopied, setAiCopied] = useState(false)
  const [aiLoading, setAiLoading] = useState(false)

  // Reset tab + modal state when selection changes (avoids Effect cascading render)
  const [prevSelectedId, setPrevSelectedId] = useState(selectedId)
  if (selectedId !== prevSelectedId) {
    setPrevSelectedId(selectedId)
    setActiveTab('info')
    setIsFlowModalOpen(false)
    setIsSourceModalOpen(false)
    setIsSeqModalOpen(false)
    setAiCopied(false)
    setAiLoading(false)
  }

  const nodeMap = useMemo(() => {
    const map = new Map<string, GraphNode>()
    if (graphData) graphData.nodes.forEach((n) => map.set(n.id, n))
    return map
  }, [graphData])

  const incomingMap = useMemo(() => {
    const map = new Map<string, GraphEdge[]>()
    if (!graphData) return map
    graphData.edges.forEach((e) => {
      const list = map.get(e.target) ?? []
      list.push(e)
      map.set(e.target, list)
    })
    return map
  }, [graphData])

  const outgoingMap = useMemo(() => {
    const map = new Map<string, GraphEdge[]>()
    if (!graphData) return map
    graphData.edges.forEach((e) => {
      const list = map.get(e.source) ?? []
      list.push(e)
      map.set(e.source, list)
    })
    return map
  }, [graphData])

  const sequenceDiagram = useMemo(() => {
    if (!graphData || !selectedId) return null
    const node = graphData.nodes.find(n => n.id === selectedId)
    if (node?.type !== 'route') return null
    return buildSequenceDiagram(selectedId, graphData)
  }, [selectedId, graphData])

  const handleCopyAiContext = useCallback(async () => {
    if (!selectedId) return
    setAiLoading(true)
    try {
      const res = await fetch(
        import.meta.env.BASE_URL + `api/context?nodeId=${encodeURIComponent(selectedId)}&budget=6000`
      )
      if (!res.ok) throw new Error('Failed to fetch context')
      const text = await res.text()
      await navigator.clipboard.writeText(text)
      setAiCopied(true)
      setTimeout(() => setAiCopied(false), 2500)
    } catch {
      alert('Could not copy AI context.')
    } finally {
      setAiLoading(false)
    }
  }, [selectedId])

  if (!graphData) return null

  if (!selectedId) {
    return (
      <div className="sidebar-resizable" style={{ width }}>
        <Tooltip content="Drag to resize">
          <div className="sidebar-drag-handle" onMouseDown={onMouseDown} />
        </Tooltip>
        <div className="sidebar">
          <div className="sidebar-header">
            <h2>{graphData.meta.project}</h2>
            <span className="sidebar-subtitle">Laravel Lifecycle Graph</span>
          </div>
          <div className="sidebar-stats">
            <Tooltip content="Total symbols in this tab's JSON graph (routes, classes, views, …).">
              <div className="stat">
                <span className="stat-value">{graphData.meta.nodeCount}</span>
                <span className="stat-label">Nodes</span>
              </div>
            </Tooltip>
            <Tooltip content="Directed links between nodes: calls, type-hints, events, views, Eloquent relations, etc.">
              <div className="stat">
                <span className="stat-value">{graphData.meta.edgeCount}</span>
                <span className="stat-label">Edges</span>
              </div>
            </Tooltip>
            <Tooltip content="HTTP route entry nodes only (subset of all node types).">
              <div className="stat">
                <span className="stat-value">
                  {graphData.nodes.filter((n) => n.type === 'route').length}
                </span>
                <span className="stat-label">Routes</span>
              </div>
            </Tooltip>
          </div>
          <Tooltip content="The inspector shows details for the selected node: metrics, flow, source, and incoming/outgoing edges.">
            <p className="sidebar-hint">Click any node to inspect it</p>
          </Tooltip>
        </div>
      </div>
    )
  }

  const node = nodeMap.get(selectedId)
  if (!node) return null

  const incomingEdges = incomingMap.get(selectedId) ?? []
  const outgoingEdges = outgoingMap.get(selectedId) ?? []
  const flowSteps = (node.data?.flowSteps ?? []) as FlowStep[]
  const filePath = (node.data?.file as string) || null
  const highlightLine = (node.data?.line as number) || undefined

  const color = TYPE_COLORS[node.type] ?? '#999'

  const metrics = node.data?.metrics as Record<string, number> | undefined
  const fatMethod = !!node.data?.fatMethod
  const fatClass = !!node.data?.fatClass
  const hasN1 = !!node.data?.hasN1

  const dbQueries = (node.data?.dbQueries ?? []) as DbQuery[]
  const relationships = (node.data?.relationships ?? []) as Array<{ type: string; related: string }>
  const middlewareParams = (node.type === 'middleware' && typeof node.data?.params === 'string' && node.data.params)
    ? (node.data.params as string).split(',').map(s => s.trim()).filter(Boolean)
    : []

  const structureMembers = (node.data?.members ?? []) as Array<Record<string, unknown>>

  const validationRules = (node.data?.validationRules ?? []) as Array<{ field: string; rules: string }>

  const displayData = Object.entries(node.data ?? {}).filter(
    ([key, val]) =>
      key !== 'flowSteps' &&
      key !== 'metrics' &&
      key !== 'fatMethod' &&
      key !== 'fatClass' &&
      key !== 'hasN1' &&
      key !== 'classMetrics' &&
      key !== 'dbQueries' &&
      key !== 'relationships' &&
      key !== 'params' &&
      key !== 'members' &&
      key !== 'validationRules' &&
      key !== 'security' &&
      key !== 'erd' &&
      key !== 'tableStats' &&
      key !== 'schema' &&
      !(Array.isArray(val) && val.length === 0)
  )

  const erd = node.data?.erd as import('../types/graph').ErdModelData | undefined
  const tableStats = node.data?.tableStats as import('../types/graph').TableStatsData | undefined
  const tableSchema = node.data?.schema as import('../types/graph').TableSchemaData | undefined

  const hasFlow = flowSteps.length > 0 || !!sequenceDiagram
  const hasSource = !!filePath
  const hasEdges = incomingEdges.length > 0 || outgoingEdges.length > 0
  const isRoute = node.type === 'route'

  // Findings are no longer a route's alone — a table with an unindexed foreign key carries the
  // same payload, so this is read for any node that has one.
  const securityData = node.data?.security
    ? node.data.security as { exposure: string; riskLevel: string; issues: Array<{ type: string; severity: string; message: string; file: string | null; line: number | null }> }
    : null

  // If the active tab became unavailable after a node change, fall back to info
  const safeTab: TabId =
    (activeTab === 'flow' && !hasFlow) ||
    (activeTab === 'source' && !hasSource) ||
    (activeTab === 'edges' && !hasEdges) ||
    (activeTab === 'stress' && !isRoute) ||
    (activeTab === 'schema' && !tableSchema) ||
    (activeTab === 'risks' && !isRoute && !securityData)
      ? 'info'
      : activeTab
  const securityIssueCount = securityData ? securityData.issues.length : 0
  const exposureColors = theme === 'light' ? SECURITY_EXPOSURE_COLORS_LIGHT : SECURITY_EXPOSURE_COLORS

  const tabs: { id: TabId; label: string; count?: number; alert?: boolean; title: string }[] = [
    { id: 'info', label: 'Info', title: 'Identity, type, smells, and code metrics (lines, cyclomatic complexity, …).' },
    ...(isRoute || securityIssueCount > 0 ? [{ id: 'risks' as TabId, label: 'Risks', count: securityIssueCount || undefined, alert: securityIssueCount > 0, title: 'Findings that need attention: a route\u2019s exposure and rate-limiting, or a table\u2019s missing indexes.' }] : []),
    ...(tableSchema ? [{ id: 'schema' as TabId, label: 'Schema', count: tableSchema.columns.length || undefined, title: 'Columns, indexes and foreign keys as the database itself reports them.' }] : []),
    ...(hasFlow ? [{ id: 'flow' as TabId, label: 'Flow', title: 'Control-flow steps through this method or request (and sequence diagram for routes).' }] : []),
    ...(hasEdges ? [{ id: 'edges' as TabId, label: 'Edges', count: incomingEdges.length + outgoingEdges.length, title: 'What calls or references this node (incoming) and what it calls (outgoing).' }] : []),
    { id: 'usages', label: 'Usages', title: 'Where this symbol is referenced across the whole project, grouped by file.' },
    ...(hasSource ? [{ id: 'source' as TabId, label: 'Source', title: 'Syntax-highlighted PHP source around this symbol.' }] : []),
    ...(isRoute ? [{ id: 'stress' as TabId, label: 'Stress', title: 'Send HTTP requests against this route and inspect responses (dev only).' }] : []),
  ]

  return (
    <div className="sidebar-resizable" style={{ width }}>
      <Tooltip content="Drag to resize">
        <div className="sidebar-drag-handle" onMouseDown={onMouseDown} />
      </Tooltip>
      <div className="sidebar">

        {/* Header */}
        <div className="sidebar-header">
          <div className="sidebar-header-actions">
            <Tooltip content="Copy AI context to clipboard">
              <span className="tooltip-trigger-wrap">
                <button
                  type="button"
                  className="flow-popup-btn sidebar-ai-btn"
                  onClick={handleCopyAiContext}
                  disabled={aiLoading}
                >
                  {aiLoading ? '…' : aiCopied ? '✓' : '🤖'}
                </button>
              </span>
            </Tooltip>
            <Tooltip content="Clear selection (close inspector header)">
              <button className="sidebar-close" type="button" onClick={onClose}>
                ×
              </button>
            </Tooltip>
          </div>
          <div className="sidebar-eyebrow">
            <span className="sidebar-eyebrow-dot" style={{ backgroundColor: color }} />
            <span className="sidebar-eyebrow-type">{node.type.replace(/_/g, ' ')}</span>
          </div>
          <h2 className="sidebar-node-title">{node.label}</h2>
          <div className="sidebar-chips">
            {securityData && exposureColors[securityData.exposure] && (() => {
              // Exposure is a route's word for who can reach it. A table has no answer, and the
              // fallback used to label it `Public` — which reads as a claim, and a frightening
              // one. An unrecognised exposure now shows no chip at all.
              const palette = exposureColors[securityData.exposure]
              return (
                <span className="ins-chip" style={{ '--cc': palette.accent } as React.CSSProperties}>
                  ● {palette.label}
                </span>
              )
            })()}
            {securityData && securityData.riskLevel !== 'none' && (
              <span
                className="ins-chip"
                style={{ '--cc': SECURITY_RISK_COLORS[securityData.riskLevel] } as React.CSSProperties}
              >
                ⚠ {SECURITY_SEVERITY_LABELS[securityData.riskLevel]} risk · {securityIssueCount}
              </span>
            )}
            {(incomingEdges.length + outgoingEdges.length) > 0 && (
              <span className="ins-chip ins-chip--neutral">
                Edges {incomingEdges.length + outgoingEdges.length}
              </span>
            )}
            {filePath && (
              <span className="ins-chip ins-chip--neutral" title={filePath}>
                {filePath.split('/').slice(-2).join('/')}{highlightLine ? ` : ${highlightLine}` : ''}
              </span>
            )}
          </div>
        </div>

        {/* Smell badges */}
        {(fatMethod || fatClass || hasN1) && (
          <div className="sidebar-smells">
            {hasN1 && (
              <Tooltip content="N+1 Query: database query inside a loop">
                <span className="smell-badge smell-badge--n1">
                  ⚠️ N+1 Query
                </span>
              </Tooltip>
            )}
            {fatMethod && (
              <Tooltip content="Fat Method: more than 30 lines or cyclomatic complexity > 10">
                <span className="smell-badge smell-badge--fat-method">
                  🧱 Fat Method
                </span>
              </Tooltip>
            )}
            {fatClass && (
              <Tooltip content="Fat Class: more than 10 methods or 300+ total lines">
                <span className="smell-badge smell-badge--fat-class">
                  🏗️ Fat Class
                </span>
              </Tooltip>
            )}
          </div>
        )}

        {/* Tab bar */}
        <div className="sidebar-tab-bar">
          {tabs.map((tab) => (
            <Tooltip key={tab.id} content={tab.title}>
              <button
                type="button"
                className={`sidebar-tab${safeTab === tab.id ? ' sidebar-tab--active' : ''}`}
                onClick={() => setActiveTab(tab.id)}
              >
                {tab.label}
                {tab.count !== undefined && (
                  <span className={`sidebar-tab-badge${tab.alert ? ' sidebar-tab-badge--alert' : ''}`}>
                    {tab.count}
                  </span>
                )}
              </button>
            </Tooltip>
          ))}
        </div>

        {/* Tab content */}
        <div className="sidebar-tab-content">

          {/* ── Info tab ── */}
          {safeTab === 'info' && (
            <>
              <div className="ins-actions">
                <button
                  type="button"
                  className="ins-action-btn"
                  disabled={!hasSource}
                  onClick={() => setActiveTab('source')}
                >
                  <svg className="ins-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                    <polyline points="15 3 21 3 21 9" />
                    <line x1="10" y1="14" x2="21" y2="3" />
                  </svg>
                  Open file
                </button>
                <button
                  type="button"
                  className="ins-action-btn"
                  onClick={() => navigator.clipboard.writeText(String(node.data?.uri ?? node.label))}
                >
                  <svg className="ins-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                  </svg>
                  Copy URI
                </button>
              </div>

              {(() => {
                const cc = metrics?.cyclomaticComplexity ?? 0
                const fanOut = outgoingEdges.length
                const riskScore = { none: 0, low: 25, medium: 55, high: 80, critical: 100 }[securityData?.riskLevel ?? 'none'] ?? 0
                const meters = [
                  { label: 'Complexity', value: cc, pct: Math.min(100, cc * 6), tone: cc > 15 ? 'var(--danger)' : cc > 10 ? 'var(--warn)' : 'var(--ok)' },
                  { label: 'Fan-out', value: fanOut, pct: Math.min(100, fanOut * 10), tone: fanOut > 8 ? 'var(--danger)' : fanOut > 4 ? 'var(--warn)' : 'var(--ok)' },
                  { label: 'Risk', value: securityIssueCount, pct: riskScore, tone: riskScore >= 80 ? 'var(--danger)' : riskScore >= 55 ? 'var(--warn)' : 'var(--ok)' },
                ]
                return (
                  <div className="ins-meters">
                    {meters.map((m) => (
                      <div key={m.label} className="ins-meter">
                        <span className="ins-meter-label">{m.label}</span>
                        <span className="ins-meter-track">
                          <span className="ins-meter-fill" style={{ width: `${m.pct}%`, background: m.tone }} />
                        </span>
                        <span className="ins-meter-value">{m.value}</span>
                      </div>
                    ))}
                  </div>
                )
              })()}

              {metrics && (
                <div className="sidebar-section sidebar-section--metrics">
                  <h3>Code Metrics</h3>
                  <div className="metrics-grid">
                    <Tooltip content="Physical lines of code in this method (approximate, from static analysis).">
                      <div className="metric-item">
                        <span className="metric-value">{metrics.lineCount}</span>
                        <span className="metric-label">Lines</span>
                      </div>
                    </Tooltip>
                    <Tooltip content="Cyclomatic complexity: decision paths (branches, loops, boolean operators). Rough guide: above 10 is harder to test; above 15 is very complex.">
                      <div className="metric-item">
                        <span
                          className="metric-value"
                          style={{ color: metrics.cyclomaticComplexity > 10 ? '#FF6D00' : 'inherit' }}
                        >
                          {metrics.cyclomaticComplexity}
                        </span>
                        <span className="metric-label">Complexity</span>
                      </div>
                    </Tooltip>
                    <Tooltip content="Executable statements counted in this method body.">
                      <div className="metric-item">
                        <span className="metric-value">{metrics.statementCount}</span>
                        <span className="metric-label">Statements</span>
                      </div>
                    </Tooltip>
                    <Tooltip content="Parameters on this function or method signature.">
                      <div className="metric-item">
                        <span className="metric-value">{metrics.paramCount}</span>
                        <span className="metric-label">Params</span>
                      </div>
                    </Tooltip>
                  </div>
                </div>
              )}

              {node.type === 'filament_resource' && !!node.data?.route && (
                <div className="sidebar-section">
                  <h3>Filament URL</h3>
                  <div className="prop-row">
                    <span className="prop-key">route</span>
                    <span className="prop-value" style={{ fontFamily: 'monospace', color: '#A855F7' }}>
                      {String(node.data.route)}
                    </span>
                  </div>
                </div>
              )}

              {relationships.length > 0 && (
                <div className="sidebar-section">
                  <h3>Relationships</h3>
                  {relationships.map((rel, i) => (
                    <div key={i} className="prop-row">
                      <span className="prop-key" style={{ color: '#9C27B0' }}>{rel.type}</span>
                      <span className="prop-value">
                        {rel.related.split('\\').pop() ?? rel.related}
                      </span>
                    </div>
                  ))}
                </div>
              )}

              {middlewareParams.length > 0 && (
                <div className="sidebar-section">
                  <h3>ATTRIBUTES</h3>
                  {middlewareParams.map((ability, i) => (
                    <div key={i} className="prop-row">
                      <span className="prop-key" style={{ color: '#FF9800' }}>{i + 1}</span>
                      <span className="prop-value">{ability}</span>
                    </div>
                  ))}
                </div>
              )}

              {validationRules.length > 0 && (
                <div className="sidebar-section sidebar-section--validation-rules">
                  <h3>Validation rules</h3>
                  <ul className="sidebar-structure-list">
                    {validationRules.map((row, i) => (
                      <li key={i} className="sidebar-structure-item">
                        <span className="structure-kind">field</span>
                        <span className="structure-name">{row.field}</span>
                        <span className="structure-value">{row.rules}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {dbQueries.length > 0 && (
                <div className="sidebar-section sidebar-section--queries">
                  <h3>DB Queries</h3>
                  <div className="query-list">
                    {dbQueries.map((q, i) => {
                      const tableName = q.table || (q.model ? q.model.split('\\').pop()! : '?')
                      const isWrite = ['insert', 'update', 'delete', 'statement'].includes(q.operation)
                      return (
                        <div key={i} className="query-item">
                          <span className={`query-op query-op--${isWrite ? 'write' : 'read'}`}>
                            {q.operation}
                          </span>
                          <span className="query-table" title={q.model || undefined}>
                            {tableName}
                          </span>
                          {q.type === 'raw' && (
                            <span className="query-badge query-badge--raw">SQL</span>
                          )}
                        </div>
                      )
                    })}
                  </div>
                </div>
              )}

              {structureMembers.length > 0 && (
                <div className="sidebar-section">
                  <h3>Structure</h3>
                  <ul className="sidebar-structure-list">
                    {structureMembers.map((m, i) => (
                      <li key={i} className="sidebar-structure-item">
                        <span className="structure-kind">{String(m.kind ?? 'item')}</span>
                        <span className="structure-name">{String(m.name ?? '')}</span>
                        {typeof m.declaringClass === 'string' && m.declaringClass !== '' && (
                          <span className="structure-decl" title="Declared on parent class">{m.declaringClass}</span>
                        )}
                        {m.value !== undefined && m.value !== null && (
                          <span className="structure-value">{String(m.value)}</span>
                        )}
                        {m.static === true && <span className="structure-flag">static</span>}
                        {typeof m.visibility === 'string' && (
                          <span className="structure-vis">{m.visibility}</span>
                        )}
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {tableStats && (
                <div className="sidebar-section">
                  <h3>Table Data</h3>
                  <div className="prop-row"><span className="prop-key">rows</span><span className="prop-value">{formatRows(tableStats.rows, tableStats.rowsEstimated)}</span></div>
                  <div className="prop-row"><span className="prop-key">table</span><span className="prop-value">{formatBytes(tableStats.tableBytes)}</span></div>
                  <div className="prop-row"><span className="prop-key">indexes</span><span className="prop-value">{formatBytes(tableStats.indexBytes)}</span></div>
                  <div className="prop-row"><span className="prop-key">total</span><span className="prop-value">{formatBytes(tableStats.totalBytes)}</span></div>
                </div>
              )}

              {erd && (
                <div className="sidebar-section">
                  <h3>Model Schema</h3>
                  <div className="prop-row"><span className="prop-key">table</span><span className="prop-value">{erd.table || '—'}</span></div>
                  <div className="prop-row"><span className="prop-key">primary key</span><span className="prop-value">{erd.primaryKey} ({erd.keyType})</span></div>
                  <div className="prop-row"><span className="prop-key">timestamps</span><span className="prop-value">{erd.timestamps ? 'yes' : 'no'}</span></div>
                  <div className="prop-row"><span className="prop-key">soft deletes</span><span className="prop-value">{erd.softDeletes ? 'yes' : 'no'}</span></div>
                  {erd.fillable?.length > 0 && (
                    <div className="prop-row"><span className="prop-key">fillable</span><span className="prop-value">{erd.fillable.join(', ')}</span></div>
                  )}
                  {erd.guarded?.length > 0 && (
                    <div className="prop-row"><span className="prop-key">guarded</span><span className="prop-value">{erd.guarded.join(', ')}</span></div>
                  )}
                  {Object.keys(erd.casts ?? {}).length > 0 && (
                    <div className="prop-row"><span className="prop-key">casts</span><span className="prop-value">{Object.entries(erd.casts).map(([k, v]) => `${k}: ${v}`).join(', ')}</span></div>
                  )}
                  {erd.dates?.length > 0 && (
                    <div className="prop-row"><span className="prop-key">dates</span><span className="prop-value">{erd.dates.join(', ')}</span></div>
                  )}
                  {erd.appends?.length > 0 && (
                    <div className="prop-row"><span className="prop-key">appends</span><span className="prop-value">{erd.appends.join(', ')}</span></div>
                  )}
                  {erd.accessors?.length > 0 && (
                    <div className="prop-row"><span className="prop-key">accessors</span><span className="prop-value">{erd.accessors.join(', ')}</span></div>
                  )}
                  {erd.relationships?.length > 0 && (
                    <div className="prop-row"><span className="prop-key">relationships</span><span className="prop-value">{erd.relationships.map(r => `${r.type}(${r.related})`).join(', ')}</span></div>
                  )}
                </div>
              )}

              <div className="sidebar-section">
                <h3>Properties</h3>
                {displayData.map(([key, val]) => (
                  <div key={key} className="prop-row">
                    <span className="prop-key">{key}</span>
                    <span className="prop-value">
                      {Array.isArray(val)
                        ? val.map(item =>
                            typeof item === 'object' && item !== null
                              ? Object.values(item as Record<string, unknown>).join(' ')
                              : String(item)
                          ).join(', ') || '—'
                        : String(val) || '—'}
                    </span>
                  </div>
                ))}
              </div>
            </>
          )}

          {/* ── Flow tab ── */}
          {safeTab === 'flow' && (
            <>
              {flowSteps.length > 0 && (
                <div className="sidebar-section sidebar-section--flowchart">
                  <div className="sidebar-section-header">
                    <h3>Method Flow</h3>
                    <button
                      className="flow-popup-btn"
                      title="Open in large view"
                      onClick={() => setIsFlowModalOpen(true)}
                    >
                      ⤢
                    </button>
                  </div>
                  <FlowchartView steps={flowSteps} isFatMethod={fatMethod} />
                  {isFlowModalOpen && (
                    <FlowchartModal
                      steps={flowSteps}
                      title={node.label}
                      isFatMethod={fatMethod}
                      onClose={() => setIsFlowModalOpen(false)}
                    />
                  )}
                </div>
              )}

              {sequenceDiagram && (
                <div className="sidebar-section sidebar-section--sequence">
                  <div className="sidebar-section-header">
                    <h3>Sequence Diagram</h3>
                    <button
                      className="flow-popup-btn"
                      title="Open in large view"
                      onClick={() => setIsSeqModalOpen(true)}
                    >
                      ⤢
                    </button>
                  </div>
                  <SequenceDiagramView
                    diagram={sequenceDiagram}
                    title={node.label}
                    theme={theme}
                  />
                  {isSeqModalOpen && (
                    <SequenceDiagramModal
                      diagram={sequenceDiagram}
                      title={node.label}
                      theme={theme}
                      onClose={() => setIsSeqModalOpen(false)}
                    />
                  )}
                </div>
              )}
            </>
          )}

          {/* ── Source tab ── */}
          {safeTab === 'source' && filePath && (
            <div className="sidebar-section sidebar-section--source">
              <div className="sidebar-section-header">
                <h3>Source Code</h3>
                <button
                  className="flow-popup-btn"
                  title="Open in large view"
                  onClick={() => setIsSourceModalOpen(true)}
                >
                  ⤢
                </button>
              </div>
              <SourceView filePath={filePath} highlightLine={highlightLine} theme={theme} />
              {isSourceModalOpen && (
                <SourceModal
                  filePath={filePath}
                  highlightLine={highlightLine}
                  theme={theme}
                  onClose={() => setIsSourceModalOpen(false)}
                />
              )}
            </div>
          )}

          {/* ── Edges tab ── */}
          {safeTab === 'edges' && (
            <>
              {outgoingEdges.length > 0 && (
                <div className="sidebar-section">
                  <h3>Outgoing ({outgoingEdges.length})</h3>
                  {outgoingEdges.map((e) => {
                    const target = nodeMap.get(e.target)
                    return (
                      <div key={e.id} className="edge-row">
                        <span className="edge-label">{e.label}</span>
                        <span className="edge-target">{target?.label ?? e.target}</span>
                      </div>
                    )
                  })}
                </div>
              )}
              {incomingEdges.length > 0 && (
                <div className="sidebar-section">
                  <h3>Incoming ({incomingEdges.length})</h3>
                  {incomingEdges.map((e) => {
                    const source = nodeMap.get(e.source)
                    return (
                      <div key={e.id} className="edge-row">
                        <span className="edge-target">{source?.label ?? e.source}</span>
                        <span className="edge-label">{e.label}</span>
                      </div>
                    )
                  })}
                </div>
              )}
            </>
          )}

          {/* ── Usages tab ── */}
          {safeTab === 'schema' && tableSchema && (
            <div>
              <div className="sidebar-section">
                <h3>Columns <span className="section-count">{tableSchema.columns.length}</span></h3>
                <div className="schema-table">
                  {tableSchema.columns.map((column) => (
                    <div className="schema-row" key={column.name}>
                      <span className="schema-name">{column.name}</span>
                      <span className="schema-type">{column.type}</span>
                      <span className="schema-flags">
                        {column.autoIncrement && <span className="schema-flag">auto</span>}
                        {column.nullable
                          ? <span className="schema-flag schema-flag--muted">null</span>
                          : <span className="schema-flag">not null</span>}
                        {column.default !== null && <span className="schema-flag schema-flag--muted">= {column.default}</span>}
                      </span>
                    </div>
                  ))}
                </div>
              </div>

              <div className="sidebar-section">
                <h3>Indexes <span className="section-count">{tableSchema.indexes.length}</span></h3>
                {tableSchema.indexes.length === 0 && <div className="sidebar-empty">No indexes.</div>}
                <div className="schema-table">
                  {tableSchema.indexes.map((index) => (
                    <div className="schema-row" key={index.name}>
                      <span className="schema-name">{index.columns.join(', ')}</span>
                      <span className="schema-type">{index.name}</span>
                      <span className="schema-flags">
                        {index.primary && <span className="schema-flag">primary</span>}
                        {index.unique && !index.primary && <span className="schema-flag">unique</span>}
                      </span>
                    </div>
                  ))}
                </div>
              </div>

              <div className="sidebar-section">
                <h3>Foreign keys <span className="section-count">{tableSchema.foreignKeys.length}</span></h3>
                {tableSchema.foreignKeys.length === 0 && <div className="sidebar-empty">No foreign keys.</div>}
                <div className="schema-table">
                  {tableSchema.foreignKeys.map((key) => {
                    // The same prefix rule the analyzer applies, so the marker on screen and the
                    // finding in the Risks tab can never disagree about one key.
                    const covered = tableSchema.indexes.some(
                      (index) => index.columns.slice(0, key.columns.length).join('\u0000') === key.columns.join('\u0000')
                    )

                    return (
                      <div className={`schema-row${covered ? '' : ' schema-row--flagged'}`} key={key.name}>
                        <span className="schema-name">{key.columns.join(', ')}</span>
                        <span className="schema-type">→ {key.foreignTable}.{key.foreignColumns.join(', ')}</span>
                        <span className="schema-flags">
                          {key.onDelete && key.onDelete !== 'no action' && (
                            <span className="schema-flag schema-flag--muted">on delete {key.onDelete}</span>
                          )}
                          {!covered && <span className="schema-flag schema-flag--warn">no index</span>}
                        </span>
                      </div>
                    )
                  })}
                </div>
              </div>
            </div>
          )}

          {safeTab === 'usages' && selectedId && (
            <UsagesView nodeId={selectedId} />
          )}

          {/* ── Security tab ── */}
          {safeTab === 'risks' && securityData && (
            <div className="sidebar-section sidebar-section--security">
              {/* Exposure Level — a route's concept, so it is drawn only for a route's exposure.
                  A table carries findings without one, and the card would otherwise announce it
                  as a "Public Route". */}
              {exposureColors[securityData.exposure] && (() => {
                const palette = exposureColors[securityData.exposure]
                const exposureDescriptions: Record<string, string> = {
                  public:  'This route is publicly accessible — no authentication middleware detected.',
                  guest:   'This route is for unauthenticated users and redirects authenticated ones away.',
                  authed:  'This route requires authentication (auth / sanctum / jwt / passport).',
                  admin:   'This route requires elevated permissions (can:, role:, permission:, ability:, gate:).',
                }
                return (
                  <div className="security-exposure-card" style={{ borderColor: palette.border, background: palette.bg + '88' }}>
                    <div className="security-exposure-header">
                      <span className="security-exposure-badge" style={{ color: palette.accent }}>
                        🔒 {palette.label} Route
                      </span>
                    </div>
                    <p className="security-exposure-desc">
                      {exposureDescriptions[securityData.exposure] ?? exposureDescriptions['public']}
                    </p>
                  </div>
                )
              })()}

              {/* Issue List */}
              {securityData.issues.length === 0 ? (
                <div className="security-clean">
                  <span style={{ color: SECURITY_RISK_COLORS['none'] }}>✓</span> Nothing flagged here.
                </div>
              ) : (
                <>
                  <div className="security-issues-title">
                    {securityData.issues.length} Issue{securityData.issues.length !== 1 ? 's' : ''} Detected
                  </div>
                  {securityData.issues.map((issue, i) => {
                    const meta = SECURITY_ISSUE_META[issue.type] ?? { icon: '•', name: issue.type }
                    const riskColor = SECURITY_RISK_COLORS[issue.severity] ?? SECURITY_RISK_COLORS['medium']
                    return (
                      <div key={i} className="security-issue-card" style={{ borderLeftColor: riskColor }}>
                        <div className="security-issue-header">
                          <span className="security-issue-icon">{meta.icon}</span>
                          <span className="security-issue-name" style={{ color: riskColor }}>{meta.name}</span>
                          <span className="security-issue-severity" style={{ color: riskColor }}>
                            {issue.severity.toUpperCase()}
                          </span>
                        </div>
                        <p className="security-issue-message">{issue.message}</p>
                        {issue.file && (
                          <div className="security-issue-location">
                            <span className="prop-key">file</span>
                            <span className="prop-val" title={issue.file}>
                              …{issue.file.split('/').slice(-2).join('/')}
                              {issue.line ? `:${issue.line}` : ''}
                            </span>
                          </div>
                        )}
                      </div>
                    )
                  })}
                </>
              )}
            </div>
          )}

          {/* ── Security tab (no data) ── */}
          {safeTab === 'risks' && isRoute && !securityData && (
            <div className="sidebar-section">
              <p style={{ opacity: 0.6, fontSize: 13 }}>Security data not available. Re-run <code>brain:scan</code> to generate it.</p>
            </div>
          )}

          {/* ── Stress tab ── */}
          {safeTab === 'stress' && isRoute && selectedId && (
            <StressTestPanel
              key={selectedId}
              method={String(node.data?.method ?? 'GET')}
              uri={String(node.data?.uri ?? '/')}
              theme={theme}
              selectedId={selectedId}
              onStressChange={onStressChange}
            />
          )}

        </div>
      </div>
    </div>
  )
}
