import { useRef, useState, useCallback, useMemo, useEffect } from 'react'
import { FilterPanel } from './FilterPanel'
import type { TabEntry } from '../types/graph'

interface PrefixGroup {
  prefix: string
  tabs: TabEntry[]
}

interface FileGroup {
  fileName: string
  prefixGroups: PrefixGroup[]
}

interface Props {
  fileGroups: FileGroup[]
  activeId: string | null
  loadingId: string | null
  onSelect: (tab: TabEntry) => void
  visibleTypes: Set<string>
  counts: Record<string, number>
  onToggle: (type: string) => void
  onShowAll: () => void
  onHideAll: () => void
}

const METHOD_COLORS: Record<string, string> = {
  GET: '#4ade80',
  POST: '#60a5fa',
  PUT: '#f59e0b',
  PATCH: '#a78bfa',
  DELETE: '#f87171',
}

function RouteItem({ tab, isActive, isLoading, onSelect }: {
  tab: TabEntry
  isActive: boolean
  isLoading: boolean
  onSelect: (tab: TabEntry) => void
}) {
  const [first, ...rest] = tab.label.split(' ')
  const hasMethod = first in METHOD_COLORS
  const method = hasMethod ? first : null
  const uri = hasMethod ? rest.join(' ') : tab.label
  const color = method ? METHOD_COLORS[method] : '#94a3b8'

  return (
    <button
      className={`left-nav-item ${isActive ? 'left-nav-item--active' : ''}`}
      type="button"
      onClick={() => onSelect(tab)}
      title={`${tab.nodeCount} nodes · ${tab.edgeCount} edges`}
    >
      {method
        ? <span className="left-nav-method" style={{ color }}>{method}</span>
        : <span className="left-nav-method" style={{ color }}>›</span>
      }
      <span className="left-nav-uri">{uri}</span>
      {isLoading && <span className="left-nav-badge">…</span>}
    </button>
  )
}

export function LeftSidebar({
  fileGroups, activeId, loadingId, onSelect,
  visibleTypes, counts, onToggle, onShowAll, onHideAll,
}: Props) {
  const [topHeight, setTopHeight] = useState(320)
  const [search, setSearch] = useState('')
  const containerRef = useRef<HTMLDivElement>(null)
  const dragStartY = useRef(0)
  const dragStartHeight = useRef(0)

  // Track expanded state (collapsed by default)
  const [expandedFiles, setExpandedFiles] = useState<Set<string>>(new Set())
  const [expandedPrefixes, setExpandedPrefixes] = useState<Set<string>>(new Set())

  useEffect(() => {
    if (fileGroups.length === 0) return

    setExpandedFiles((prev) => {
      const next = new Set(prev)
      for (const fg of fileGroups) next.add(fg.fileName)
      return next
    })

    setExpandedPrefixes((prev) => {
      const next = new Set(prev)
      for (const fg of fileGroups) {
        for (const pg of fg.prefixGroups) {
          if (pg.prefix === '_flat') continue
          next.add(`${fg.fileName}::${pg.prefix}`)
        }
      }
      return next
    })
  }, [fileGroups])

  useEffect(() => {
    if (!activeId) return

    let targetFile: string | null = null
    let targetPrefixKey: string | null = null

    for (const fg of fileGroups) {
      for (const pg of fg.prefixGroups) {
        if (!pg.tabs.some((tab) => tab.id === activeId)) continue

        targetFile = fg.fileName
        if (pg.prefix !== '_flat') {
          targetPrefixKey = `${fg.fileName}::${pg.prefix}`
        }
        break
      }
      if (targetFile) break
    }

    if (targetFile) {
      setExpandedFiles((prev) => {
        if (prev.has(targetFile!)) return prev
        const next = new Set(prev)
        next.add(targetFile!)
        return next
      })
    }

    if (targetPrefixKey) {
      setExpandedPrefixes((prev) => {
        if (prev.has(targetPrefixKey!)) return prev
        const next = new Set(prev)
        next.add(targetPrefixKey!)
        return next
      })
    }
  }, [activeId, fileGroups])

  const toggleFile = (fileName: string) =>
    setExpandedFiles((s) => {
      const n = new Set(s)
      if (n.has(fileName)) {
        n.delete(fileName)
      } else {
        n.add(fileName)
      }
      return n
    })

  const togglePrefix = (key: string) =>
    setExpandedPrefixes((s) => {
      const n = new Set(s)
      if (n.has(key)) {
        n.delete(key)
      } else {
        n.add(key)
      }
      return n
    })

  const onFileHeaderClick = (fg: FileGroup, isOpen: boolean) => {
    toggleFile(fg.fileName)

    if (isOpen) return

    const flatGroup = fg.prefixGroups.length === 1 && fg.prefixGroups[0].prefix === '_flat'
      ? fg.prefixGroups[0]
      : null

    if (flatGroup && flatGroup.tabs.length === 1) {
      onSelect(flatGroup.tabs[0])
    }
  }

  const onMouseDown = useCallback((e: React.MouseEvent) => {
    e.preventDefault()
    dragStartY.current = e.clientY
    dragStartHeight.current = topHeight

    const onMouseMove = (e: MouseEvent) => {
      const container = containerRef.current
      if (!container) return
      const delta = e.clientY - dragStartY.current
      const totalHeight = container.clientHeight
      setTopHeight(Math.max(80, Math.min(totalHeight - 80, dragStartHeight.current + delta)))
    }
    const onMouseUp = () => {
      window.removeEventListener('mousemove', onMouseMove)
      window.removeEventListener('mouseup', onMouseUp)
    }
    window.addEventListener('mousemove', onMouseMove)
    window.addEventListener('mouseup', onMouseUp)
  }, [topHeight])

  const filteredFileGroups = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return fileGroups
    return fileGroups
      .map((fg) => ({
        ...fg,
        prefixGroups: fg.prefixGroups
          .map((pg) => ({ ...pg, tabs: pg.tabs.filter((t) => t.label.toLowerCase().includes(q)) }))
          .filter((pg) => pg.tabs.length > 0),
      }))
      .filter((fg) => fg.prefixGroups.length > 0)
  }, [fileGroups, search])

  return (
    <div className="left-sidebar" ref={containerRef}>
      <div className="left-sidebar-top" style={{ height: topHeight }}>
        <div className="left-nav-search">
          <input
            className="left-nav-search-input"
            type="text"
            placeholder="Search routes…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          {search && (
            <button
              className="left-nav-search-clear"
              type="button"
              onClick={() => setSearch('')}
            >
              ×
            </button>
          )}
        </div>

        <div className="left-nav">
          {filteredFileGroups.map((fg) => {
            const fileOpen = expandedFiles.has(fg.fileName)
            const totalRoutes = fg.prefixGroups.reduce((s, pg) => s + pg.tabs.length, 0)

            return (
              <div key={fg.fileName} className="left-nav-file-group">
                <button
                  className="left-nav-file-header"
                  type="button"
                  onClick={() => onFileHeaderClick(fg, fileOpen)}
                  title={fg.fileName}
                >
                  <span className="left-nav-file-chevron">{fileOpen ? '▾' : '▸'}</span>
                  <span className="left-nav-file-icon">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                      <polyline points="14 2 14 8 20 8" />
                    </svg>
                  </span>
                  <span className="left-nav-file-name">{fg.fileName}</span>
                  <span className="left-nav-file-count">{totalRoutes}</span>
                </button>

                {fileOpen && fg.prefixGroups.map((pg) => {
                  if (pg.prefix === '_flat') {
                    return pg.tabs.map((tab) => (
                      <RouteItem
                        key={tab.id}
                        tab={tab}
                        isActive={tab.id === activeId}
                        isLoading={tab.id === loadingId}
                        onSelect={onSelect}
                      />
                    ))
                  }

                  const prefixKey = `${fg.fileName}::${pg.prefix}`
                  const prefixOpen = expandedPrefixes.has(prefixKey)

                  return (
                    <div key={pg.prefix} className="left-nav-prefix-group">
                      <button
                        className="left-nav-prefix-header"
                        type="button"
                        onClick={() => togglePrefix(prefixKey)}
                      >
                        <span className="left-nav-prefix-chevron">{prefixOpen ? '▾' : '▸'}</span>
                        <span className="left-nav-prefix-icon">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
                          </svg>
                        </span>
                        <span className="left-nav-prefix-name">/{pg.prefix}</span>
                        <span className="left-nav-prefix-count">{pg.tabs.length}</span>
                      </button>

                      {prefixOpen && pg.tabs.map((tab) => (
                        <RouteItem
                          key={tab.id}
                          tab={tab}
                          isActive={tab.id === activeId}
                          isLoading={tab.id === loadingId}
                          onSelect={onSelect}
                        />
                      ))}
                    </div>
                  )
                })}
              </div>
            )
          })}
        </div>
      </div>

      <div className="left-sidebar-handle" onMouseDown={onMouseDown} />

      <div className="left-sidebar-bottom">
        <FilterPanel
          visibleTypes={visibleTypes}
          counts={counts}
          onToggle={onToggle}
          onShowAll={onShowAll}
          onHideAll={onHideAll}
        />
      </div>
    </div>
  )
}
