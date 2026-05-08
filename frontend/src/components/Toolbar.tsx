import { useState, useRef, useEffect, useMemo } from 'react'
import type { GraphViewportRef } from '../types/graph'
import { LARGE_GRAPH_THRESHOLD } from '../utils/graphConstants'
import { ExportModal } from './ExportModal'
import { AiRulesModal } from './AiRulesModal'
import { graphToMermaid, downloadPng } from '../utils/exportUtils'
import type { GraphData } from '../types/graph'
import { Tooltip } from './Tooltip'

interface Props {
  layout: string
  nodeCount: number
  edgeCount: number
  visibleCount: number
  activeTabLabel: string
  graphData: GraphData | null
  analyzedAt?: string
  theme: 'dark' | 'light'
  onLayoutChange: (layout: string) => void
  rankDir: 'LR' | 'TB'
  onRankDirChange: (dir: 'LR' | 'TB') => void
  onSearch: (query: string) => void
  onToggleTheme: () => void
  graphRef: React.MutableRefObject<GraphViewportRef | null>
  complexityOverlay: boolean
  onToggleComplexityOverlay: () => void
  compact: boolean
  onToggleCompact: () => void
}

function formatAge(ms: number): string {
  const secs = Math.floor(ms / 1000)
  if (secs < 60) return `${secs}s`
  const mins = Math.floor(secs / 60)
  if (mins < 60) return `${mins}m`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h`
  const days = Math.floor(hrs / 24)
  return `${days}d`
}

const LAYOUTS = [
  { value: 'dagre', label: 'Hierarchical (dagre)' },
  { value: 'breadthfirst', label: 'Breadth-first' },
  { value: 'cose-bilkent', label: 'Force (cose-bilkent)' },
  { value: 'circle', label: 'Circle' },
  { value: 'grid', label: 'Grid' },
]



function ActionDropdown({ label, icon, children, buttonTitle }: { label: string, icon: React.ReactNode, children: React.ReactNode, buttonTitle?: string }) {
  const [isOpen, setIsOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const handleClick = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setIsOpen(false)
    }
    // Capture phase: GraphView stops propagation on the graph so bubble listeners
    // on window never run; capture on document still sees outside clicks first.
    document.addEventListener('mousedown', handleClick, true)
    return () => document.removeEventListener('mousedown', handleClick, true)
  }, [])

  return (
    <div className="action-dropdown" ref={ref}>
      {buttonTitle ? (
        <Tooltip content={buttonTitle}>
          <button
            type="button"
            className={`toolbar-btn ${isOpen ? 'toolbar-btn--active' : ''}`}
            onClick={() => setIsOpen(!isOpen)}
          >
            {icon} <span>{label}</span> <span className="dropdown-chevron">▾</span>
          </button>
        </Tooltip>
      ) : (
        <button
          type="button"
          className={`toolbar-btn ${isOpen ? 'toolbar-btn--active' : ''}`}
          onClick={() => setIsOpen(!isOpen)}
        >
          {icon} <span>{label}</span> <span className="dropdown-chevron">▾</span>
        </button>
      )}
      {isOpen && (
        <div className="action-dropdown-menu">
          {children}
        </div>
      )}
    </div>
  )
}

export function Toolbar({ layout, rankDir, onRankDirChange, nodeCount, edgeCount, visibleCount, activeTabLabel, graphData, analyzedAt, theme, onLayoutChange, onSearch, onToggleTheme, graphRef, complexityOverlay, onToggleComplexityOverlay, compact, onToggleCompact }: Props) {
  const [searchValue, setSearchValue] = useState('')
  const [showMermaid, setShowMermaid] = useState(false)
  const [showAiRules, setShowAiRules] = useState(false)
  const [scanning, setScanning] = useState(false)
  const timeoutRef = useRef<number | null>(null)

  useEffect(() => {
    if (timeoutRef.current) clearTimeout(timeoutRef.current)
    timeoutRef.current = setTimeout(() => {
      onSearch(searchValue)
    }, 250)
    return () => { if (timeoutRef.current) clearTimeout(timeoutRef.current) }
  }, [searchValue, onSearch])

  const handleFit = () => graphRef.current?.fit()

  const handleExportPng = () => {
    void graphRef.current?.toPng({ scale: 2 }).then((dataUrl) => {
      if (!dataUrl) return
      const safe = activeTabLabel.replace(/[^a-z0-9]/gi, '_')
      downloadPng(dataUrl, `${safe}_graph.png`)
    })
  }

  const handleExportMermaid = () => {
    if (graphData) setShowMermaid(true)
  }

  const handleScan = async () => {
    if (!window.confirm('This will re-scan the entire project. Proceed?')) return
    setScanning(true)
    try {
      const res = await fetch(import.meta.env.BASE_URL + 'api/scan', { method: 'POST' })
      if (res.ok) {
        window.location.reload()
      } else {
        alert('Scan failed.')
      }
    } catch {
      alert('Scan failed.')
    } finally {
      setScanning(false)
    }
  }

  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 60000)
    return () => clearInterval(timer)
  }, [])

  const ageData = useMemo(() => {
    if (!analyzedAt) return null
    const ageMs = now - new Date(analyzedAt).getTime()
    return {
      ageMs,
      isStale: ageMs > 24 * 3600 * 1000,
      label: `Scanned ${formatAge(ageMs)} ago`
    }
  }, [analyzedAt, now])

  const isLarge = nodeCount > LARGE_GRAPH_THRESHOLD

  return (
    <>
      <div className="toolbar">
        <Tooltip content="Laravel Brain — static analysis graph of routes, classes, and dependencies.">
          <div className="toolbar-brand">
            <img
              src={`${import.meta.env.BASE_URL}logo.png`}
              alt="Laravel Brain"
              className="toolbar-logo-img"
              width={38}
              height={38}
              decoding="async"
            />
          </div>
        </Tooltip>

        <div className="toolbar-stats">
          <Tooltip content="Nodes are classes, routes, views, and other symbols in this graph. Visible count respects sidebar type filters.">
            <span className="stat-chip">
              {visibleCount}/{nodeCount} nodes
            </span>
          </Tooltip>
          <Tooltip content="Edges are references between nodes (calls, type-hints, dispatch, views, etc.).">
            <span className="stat-chip">
              {edgeCount} edges
            </span>
          </Tooltip>
          {isLarge && (
            <Tooltip content="Large graph: dagre auto-switched to breadthfirst">
              <span className="stat-chip stat-chip--warn">
                ⚠ large graph
              </span>
            </Tooltip>
          )}
          {ageData && (
            ageData.isStale ? (
              <Tooltip content={`Graph is over 24h old (last scanned ${analyzedAt ? new Date(analyzedAt).toLocaleString() : ''}). Click to re-scan.`}>
                <button
                  type="button"
                  className="stat-chip stat-chip--stale"
                  onClick={handleScan}
                >
                  ⚠ {ageData.label} — Re-scan?
                </button>
              </Tooltip>
            ) : (
              <Tooltip content={`Last scanned: ${analyzedAt ? new Date(analyzedAt).toLocaleString() : ''}`}>
                <span className="stat-chip stat-chip--age">
                  {ageData.label}
                </span>
              </Tooltip>
            )
          )}
        </div>

        <div className="toolbar-controls">
          {/* Group 1: View */}
          <div className="toolbar-group">
            <ActionDropdown 
              label="View" 
              icon={<span>⊡</span>}
              buttonTitle="Arrange the graph: layout algorithm, orientation, and zoom to fit."
            >
              <div className="dropdown-item">
                <label>Layout</label>
                <Tooltip content="How node positions are computed. Try breadth-first or force layouts if hierarchical (dagre) is cluttered.">
                  <select
                    value={layout}
                    onChange={(e) => onLayoutChange(e.target.value)}
                    className="toolbar-select"
                  >
                    {LAYOUTS.map(({ value, label }) => (
                      <option key={value} value={value}>{label}</option>
                    ))}
                  </select>
                </Tooltip>
              </div>

              {layout === 'dagre' && (
                <div className="dropdown-item">
                  <label>Orientation</label>
                  <Tooltip content="For hierarchical layout: draw graph left-to-right or top-to-bottom.">
                    <button
                      type="button"
                      className="toolbar-btn toolbar-btn--rank"
                      onClick={() => onRankDirChange(rankDir === 'LR' ? 'TB' : 'LR')}
                    >
                      {rankDir === 'LR' ? 'Horizontal (LR)' : 'Vertical (TB)'}
                    </button>
                  </Tooltip>
                </div>
              )}

              <div className="dropdown-item">
                <Tooltip content="Zoom and pan so all visible nodes fit in the viewport.">
                  <button type="button" onClick={handleFit} className="toolbar-btn w-full">
                    <span>⊡</span> <span>Fit to Screen</span>
                  </button>
                </Tooltip>
              </div>
            </ActionDropdown>
          </div>
 
          {/* Group 2: Complexity overlay toggle + Compact toggle */}
          <div className="toolbar-group">
            <Tooltip content="Cyclomatic complexity: number of independent paths through code (branches, loops). Higher values often mean harder-to-test methods. Colors nodes by this metric when enabled.">
              <button
                type="button"
                className={`toolbar-btn ${complexityOverlay ? 'toolbar-btn--active' : ''}`}
                onClick={onToggleComplexityOverlay}
              >
                <span>◈</span> <span>Complexity</span>
              </button>
            </Tooltip>
            <Tooltip content="Compact mode: shrink graph nodes to show only the class name, reducing visual clutter on large graphs.">
              <button
                type="button"
                className={`toolbar-btn ${compact ? 'toolbar-btn--active' : ''}`}
                onClick={onToggleCompact}
              >
                <span>⊟</span> <span>Compact</span>
              </button>
            </Tooltip>
          </div>

          {/* Group 3: Search */}
          <div className="toolbar-group">
            <div className="toolbar-search-wrapper">
              <Tooltip content="Filter highlighted matches in the graph by class name, path, or label.">
                <input
                  type="search"
                  placeholder="Search nodes..."
                  className="toolbar-search"
                  value={searchValue}
                  onChange={(e) => setSearchValue(e.target.value)}
                />
              </Tooltip>
            </div>
          </div>
 
          {/* Group 4: Exports */}
          <div className="toolbar-group">
            <ActionDropdown 
              label="Export" 
              icon={<span>🖼</span>}
              buttonTitle="Save the graph as an image, Mermaid diagram text, or AI context rules."
            >
              <div className="dropdown-item">
                <Tooltip content="Raster image of the current graph viewport (useful for docs or slides).">
                  <button type="button" onClick={handleExportPng} className="toolbar-btn w-full">
                    <span>🖼</span> <span>Download PNG</span>
                  </button>
                </Tooltip>
              </div>
              <div className="dropdown-item">
                <Tooltip content="Mermaid flowchart syntax you can paste into Markdown, Notion, or mermaid.live.">
                  <span className="tooltip-trigger-wrap tooltip-trigger-wrap--block">
                    <button
                      type="button"
                      onClick={handleExportMermaid}
                      className="toolbar-btn w-full"
                      disabled={!graphData}
                    >
                      <span>🗺</span> <span>Copy Mermaid Code</span>
                    </button>
                  </span>
                </Tooltip>
              </div>
              <div className="dropdown-item">
                <Tooltip content="Build a rules snippet describing this project for AI coding assistants.">
                  <button
                    type="button"
                    onClick={() => setShowAiRules(true)}
                    className="toolbar-btn w-full"
                  >
                    <span>🤖</span> <span>Generate AI Rules</span>
                  </button>
                </Tooltip>
              </div>
            </ActionDropdown>
          </div>
 
          {/* Group 5: System */}
          <div className="toolbar-group">
            <Tooltip content="Re-run project scan to refresh routes, graphs, and analysis from disk.">
              <span className="tooltip-trigger-wrap tooltip-trigger-wrap--block">
                <button
                  type="button"
                  onClick={handleScan}
                  className={`toolbar-btn toolbar-btn--scan ${scanning ? 'toolbar-btn--loading' : ''}`}
                  disabled={scanning}
                  aria-busy={scanning}
                >
                  {scanning ? (
                    <>
                      <div className="btn-spinner btn-spinner--small" aria-hidden />
                      <span>Scanning…</span>
                    </>
                  ) : (
                    <>
                      <span className="toolbar-scan__glyph" aria-hidden>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                          <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                          <path d="M3 3v5h5" />
                          <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16" />
                          <path d="M16 16h5v5" />
                        </svg>
                      </span>
                      <span className="toolbar-scan__label">Re-scan</span>
                    </>
                  )}
                </button>
              </span>
            </Tooltip>
 
            <Tooltip content={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}>
              <button
                type="button"
                onClick={onToggleTheme}
                className="theme-toggle"
              >
                {theme === 'dark' ? '☀️' : '🌙'}
              </button>
            </Tooltip>
          </div>
        </div>
      </div>

      {showAiRules && (
        <AiRulesModal onClose={() => setShowAiRules(false)} />
      )}

      {showMermaid && graphData && (
        <ExportModal
          mermaidCode={graphToMermaid(graphData, activeTabLabel)}
          filename={`${activeTabLabel.replace(/[^a-z0-9]/gi, '_')}_graph.mmd`}
          title={`${activeTabLabel} — Full Lifecycle Graph`}
          onClose={() => setShowMermaid(false)}
        />
      )}
    </>
  )
}
