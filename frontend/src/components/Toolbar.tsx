import { BASE } from '../lib/base'
import { useState, useRef, useEffect, useMemo } from 'react'
import type { GraphViewportRef } from '../types/graph'
import { LARGE_GRAPH_THRESHOLD } from '../utils/graphConstants'
import { ExportModal } from './ExportModal'
import { AiRulesModal } from './AiRulesModal'
import { graphToMermaid, downloadPng } from '../utils/exportUtils'
import type { GraphData } from '../types/graph'
import { Tooltip } from './Tooltip'

interface Props {
  nodeCount: number
  edgeCount: number
  visibleCount: number
  activeTabLabel: string
  graphData: GraphData | null
  analyzedAt?: string
  highRiskCount: number
  onOpenRisks: () => void
  theme: 'dark' | 'light'
  onSearch: (query: string) => void
  onToggleTheme: () => void
  graphRef: React.MutableRefObject<GraphViewportRef | null>
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

function Dropdown({ label, active, children }: { label: string; active?: boolean; children: React.ReactNode }) {
  const [isOpen, setIsOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const handleClick = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setIsOpen(false)
    }
    document.addEventListener('mousedown', handleClick, true)
    return () => document.removeEventListener('mousedown', handleClick, true)
  }, [])

  return (
    <div className="seg-dropdown" ref={ref}>
      <button
        type="button"
        className={`seg-btn ${active || isOpen ? 'seg-btn--active' : ''}`}
        onClick={() => setIsOpen(!isOpen)}
      >
        {label}
      </button>
      {isOpen && <div className="seg-dropdown-menu">{children}</div>}
    </div>
  )
}

export function Toolbar({
  nodeCount, edgeCount, visibleCount, activeTabLabel,
  graphData, analyzedAt, highRiskCount, onOpenRisks, theme, onSearch,
  onToggleTheme, graphRef,
}: Props) {
  const [searchValue, setSearchValue] = useState('')
  const [showMermaid, setShowMermaid] = useState(false)
  const [showAiRules, setShowAiRules] = useState(false)
  const [scanning, setScanning] = useState(false)
  const timeoutRef = useRef<number | null>(null)
  const searchRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (timeoutRef.current) clearTimeout(timeoutRef.current)
    timeoutRef.current = setTimeout(() => onSearch(searchValue), 250)
    return () => { if (timeoutRef.current) clearTimeout(timeoutRef.current) }
  }, [searchValue, onSearch])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        searchRef.current?.focus()
        searchRef.current?.select()
      } else if (e.key === 'Escape' && document.activeElement === searchRef.current) {
        searchRef.current?.blur()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])


  const handleExportPng = () => {
    void graphRef.current?.toPng({ scale: 2 }).then((dataUrl) => {
      if (!dataUrl) return
      const safe = activeTabLabel.replace(/[^a-z0-9]/gi, '_')
      downloadPng(dataUrl, `${safe}_graph.png`)
    })
  }

  const handleExportMermaid = () => { if (graphData) setShowMermaid(true) }

  const handleScan = async () => {
    if (!window.confirm('This will re-scan the entire project. Proceed?')) return
    setScanning(true)
    try {
      const res = await fetch(BASE + 'api/scan', { method: 'POST' })
      if (res.ok) window.location.reload()
      else alert('Scan failed.')
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

  const ageLabel = useMemo(() => {
    if (!analyzedAt) return null
    return `scanned ${formatAge(now - new Date(analyzedAt).getTime())} ago`
  }, [analyzedAt, now])

  const isLarge = nodeCount > LARGE_GRAPH_THRESHOLD

  return (
    <>
      <div className="toolbar">
        <div className="toolbar-brand">
          <img
            src={`${BASE}logo.png`}
            alt="Laravel Brain"
            className="toolbar-logo-img"
            width={28}
            height={28}
            decoding="async"
          />
          <div className="toolbar-brand-text">
            <span className="toolbar-brand-name">Laravel Brain</span>
            {ageLabel && <span className="toolbar-brand-sub">{ageLabel}</span>}
          </div>
        </div>

        <div className="toolbar-center">
          <div className="toolbar-search-wrapper">
            <svg className="toolbar-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" />
            </svg>
            <input
              ref={searchRef}
              type="search"
              placeholder="Search routes, nodes, files…"
              className="toolbar-search"
              value={searchValue}
              onChange={(e) => setSearchValue(e.target.value)}
            />
            <kbd className="toolbar-kbd">⌘K</kbd>
          </div>
          <Tooltip content="Routes flagged high or critical risk. Click to open the Risks list.">
            <button
              type="button"
              className={`risk-pill ${highRiskCount > 0 ? 'risk-pill--alert' : ''}`}
              onClick={onOpenRisks}
            >
              <span className="risk-pill-dot" />
              High-risk
              <span className="risk-pill-count">{highRiskCount}</span>
            </button>
          </Tooltip>
          {isLarge && (
            <Tooltip content="Large graph: dagre auto-switched to breadthfirst">
              <span className="stat-chip stat-chip--warn">⚠ large</span>
            </Tooltip>
          )}
          <Tooltip content="Nodes / edges in this graph (visible respects type filters).">
            <span className="stat-chip">{visibleCount}/{nodeCount} · {edgeCount}e</span>
          </Tooltip>
        </div>

        <div className="toolbar-right">
          <Tooltip content={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}>
            <button type="button" onClick={onToggleTheme} className="icon-btn">
              {theme === 'dark' ? '☀' : '☾'}
            </button>
          </Tooltip>
          <Dropdown label="↧">
            <button type="button" onClick={handleExportPng} className="seg-menu-btn">Download PNG</button>
            <button type="button" onClick={handleExportMermaid} className="seg-menu-btn" disabled={!graphData}>Copy Mermaid</button>
            <button type="button" onClick={() => setShowAiRules(true)} className="seg-menu-btn">Generate AI Rules</button>
          </Dropdown>
          <button
            type="button"
            onClick={handleScan}
            className={`rescan-btn ${scanning ? 'rescan-btn--loading' : ''}`}
            disabled={scanning}
            aria-busy={scanning}
          >
            {scanning ? (
              <><span className="btn-spinner btn-spinner--small" aria-hidden /><span>Scanning…</span></>
            ) : (
              <>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                  <path d="M3 3v5h5" />
                  <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16" />
                  <path d="M16 16h5v5" />
                </svg>
                <span>Re-scan</span>
              </>
            )}
          </button>
        </div>
      </div>

      {showAiRules && <AiRulesModal onClose={() => setShowAiRules(false)} />}

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
