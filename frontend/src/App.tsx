import { useRef, useState, useMemo, useEffect, useCallback } from 'react'
import type { GraphViewportRef } from './types/graph'
import { useManifest } from './hooks/useManifest'
import { useTabGraph } from './hooks/useTabGraph'
import { useVirtualGraph } from './hooks/useVirtualGraph'
import { useTheme } from './hooks/useTheme'
import { GraphView } from './components/GraphView'
import { Sidebar } from './components/Sidebar'
import { Toolbar } from './components/Toolbar'
import { LeftSidebar } from './components/LeftSidebar'
import type { GraphNode, TabEntry } from './types/graph'
import { Tooltip } from './components/Tooltip'
import './App.css'

const ALL_TYPES: GraphNode['type'][] = [
  'route', 'middleware', 'controller', 'livewire_component', 'action', 'service', 'validation_request', 'model', 'event', 'listener', 'job',
  'command', 'channel', 'schedule',
  'view', 'mail', 'notification', 'enum', 'interface', 'trait', 'abstract_class', 'service_provider',
  'filament_panel', 'filament_resource', 'filament_page', 'filament_page_method', 'filament_widget', 'filament_relation_manager',
]

// Node types that should have their methods expanded on first click

export default function App() {
  const { theme, toggle: toggleTheme } = useTheme()
  const { manifest, loading: manifestLoading, error: manifestError } = useManifest()
  const { state: tabState, elements: allElements, load } = useTabGraph()
  const elements = useVirtualGraph(allElements)

  const [activeTab, setActiveTab] = useState<TabEntry | null>(null)
  const [loadingTabId, setLoadingTabId] = useState<string | null>(null)
  const [layout, setLayout] = useState('dagre')
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [sidebarMode, setSidebarMode] = useState<'routes' | 'risks' | 'recent'>('routes')
  const [searchQuery, setSearchQuery] = useState('')
  const [pendingRouteSelect, setPendingRouteSelect] = useState(false)
  const [visibleTypes, setVisibleTypes] = useState<Set<string>>(new Set(ALL_TYPES))
  const [rankDir, setRankDir] = useState<'LR' | 'TB'>('TB')
  const [stressTestNodeId, setStressTestNodeId] = useState<string | null>(null)
  const [stressRunKey, setStressRunKey] = useState(0)
  const graphRef = useRef<GraphViewportRef | null>(null)

  const handleSelectTab = useCallback((tab: TabEntry) => {
    if (activeTab?.id === tab.id) return
    
    // Update URL if different
    const url = new URL(window.location.href)
    if (url.searchParams.get('tab') !== tab.id) {
      url.searchParams.set('tab', tab.id)
      window.history.pushState({ tabId: tab.id }, '', url.toString())
    }

    setActiveTab(tab)
    setSearchQuery('')
    setPendingRouteSelect(true)
    load(tab.file)
  }, [activeTab, load])

  // Auto-select tab ONLY if tab ID is present in URL
  const [prevManifest, setPrevManifest] = useState(manifest)
  if (manifest !== prevManifest) {
    setPrevManifest(manifest)
    if (manifest && !activeTab) {
      const params = new URLSearchParams(window.location.search)
      const urlTabId = params.get('tab')
      const targetTab = manifest.tabs.find((t) => t.id === urlTabId)
      if (targetTab) {
        handleSelectTab(targetTab)
      }
    }
  }

  // Adjust state during render when tab data changes (avoids Effect cascading render)
  const [prevTabData, setPrevTabData] = useState(tabState.data)
  if (tabState.data !== prevTabData) {
    setPrevTabData(tabState.data)
    if (tabState.data) {
      setVisibleTypes(new Set(ALL_TYPES))
      // When the load came from picking a route/risk/recent card, focus its
      // route node so the graph + inspector jump to it.
      if (pendingRouteSelect) {
        setPendingRouteSelect(false)
        const routeNode = tabState.data.nodes.find((n) => n.type === 'route')
        setSelectedId(routeNode ? routeNode.id : null)
      } else {
        setSelectedId(null)
      }
    } else {
      setSelectedId(null)
    }
  }

  // Sync state with URL on back/forward
  useEffect(() => {
    const handlePopState = () => {
      if (!manifest) return
      const params = new URLSearchParams(window.location.search)
      const tabId = params.get('tab')
      const tab = manifest.tabs.find((t) => t.id === tabId)
      if (tab) {
        setActiveTab(tab)
        load(tab.file)
      }
    }
    window.addEventListener('popstate', handlePopState)
    return () => window.removeEventListener('popstate', handlePopState)
  }, [manifest, load])

  // Node click: select
  const handleNodeSelect = useCallback((id: string | null) => {
    setSelectedId(id)
  }, [])

  // Adjust loading state during render
  const [prevTabLoading, setPrevTabLoading] = useState(tabState.loading)
  if (tabState.loading !== prevTabLoading) {
    setPrevTabLoading(tabState.loading)
    if (!tabState.loading) {
      setLoadingTabId(null)
    }
  }

  const routeTabs = useMemo(() => manifest?.tabs ?? [], [manifest])

  const highRiskCount = useMemo(
    () => routeTabs.filter((t) => t.riskLevel === 'high' || t.riskLevel === 'critical').length,
    [routeTabs],
  )

  const typeCounts = useMemo(() => {
    if (!tabState.data) return {} as Record<string, number>
    return tabState.data.nodes.reduce<Record<string, number>>((acc, n) => {
      acc[n.type] = (acc[n.type] ?? 0) + 1
      return acc
    }, {})
  }, [tabState.data])

  const visibleNodeCount = useMemo(() => {
    if (!tabState.data) return 0
    return tabState.data.nodes.filter((n) => visibleTypes.has(n.type)).length
  }, [tabState.data, visibleTypes])

  const toggleType = useCallback((type: string) => {
    setVisibleTypes((prev) => {
      const next = new Set(prev)
      if (next.has(type)) {
        next.delete(type)
      } else {
        next.add(type)
      }
      return next
    })
  }, [])

  const onShowAll = useCallback(() => setVisibleTypes(new Set(ALL_TYPES)), [])
  const onHideAll = useCallback(() => setVisibleTypes(new Set()), [])

  const [scanning, setScanning] = useState(false)
  const [complexityOverlay, setComplexityOverlay] = useState(false)
  const [complexityFilter, setComplexityFilter] = useState<'all' | 'complex' | 'critical'>('all')
  const [securityOverlay, setSecurityOverlay] = useState(false)
  const [compact, setCompact] = useState(false)

  const handleScan = async () => {
    if (!window.confirm('This will scan the entire project. Proceed?')) return
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



  if (manifestLoading) {
    return (
      <div className="loading-screen">
        <div className="loading-spinner" />
        <p>Loading project graph...</p>
      </div>
    )
  }

  if (manifestError || !manifest) {
    return (
      <div className="error-screen welcome-screen">
        <div className="welcome-card">
          <div className="welcome-icon">
            <img src="/_laravel-brain/logo.png" alt="Laravel Brain" />
          </div>
          <h2>Welcome to Laravel Brain</h2>
          <p>No project analysis found. To begin exploring your code architecture, please run an initial scan.</p>

          {manifestError && manifestError !== 'HTTP 404' && (
            <div className="error-details">
              <small>Error: {manifestError}</small>
            </div>
          )}

          <button
            className={`scan-btn ${scanning ? 'scan-btn--loading' : ''}`}
            onClick={handleScan}
            disabled={scanning}
          >
            {scanning ? (
              <>
                <div className="btn-spinner" />
                Analyzing Project...
              </>
            ) : (
              '🚀 Start Initial Scan'
            )}
          </button>

          <div className="welcome-hint">
            Alternatively, run <code>php artisan brain:scan</code> in your terminal.
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="app">
      <Toolbar
        nodeCount={tabState.data?.meta.nodeCount ?? activeTab?.nodeCount ?? 0}
        edgeCount={tabState.data?.meta.edgeCount ?? activeTab?.edgeCount ?? 0}
        visibleCount={visibleNodeCount}
        activeTabLabel={activeTab?.label ?? 'graph'}
        graphData={tabState.data ?? null}
        analyzedAt={manifest.analyzedAt}
        highRiskCount={highRiskCount}
        onOpenRisks={() => setSidebarMode('risks')}
        theme={theme}
        onSearch={setSearchQuery}
        onToggleTheme={toggleTheme}
        graphRef={graphRef}
      />
      <div className="main">
        <LeftSidebar
          tabs={routeTabs}
          activeId={activeTab?.id ?? null}
          loadingId={loadingTabId}
          onSelect={handleSelectTab}
          mode={sidebarMode}
          onModeChange={setSidebarMode}
          previousAnalyzedAt={manifest.previousAnalyzedAt}
          visibleTypes={visibleTypes}
          counts={typeCounts}
          onToggle={toggleType}
          onShowAll={onShowAll}
          onHideAll={onHideAll}
          graphData={tabState.data ?? null}
          complexityFilter={complexityFilter}
          onComplexityFilterChange={setComplexityFilter}
          onNodeSelect={handleNodeSelect}
          selectedId={selectedId}
        />
        <div className="graph-container">
          {tabState.loading && (
            <div className="graph-loading-overlay">
              <div className="loading-spinner" />
              <p>Loading {activeTab?.label}…</p>
            </div>
          )}
          {tabState.error && (
            <div className="graph-loading-overlay">
              <p style={{ color: '#F44336' }}>Error: {tabState.error}</p>
            </div>
          )}
          {!activeTab && !tabState.loading && (
            <Tooltip content="Pick a route or command in the left sidebar to load its dependency graph.">
              <div className="graph-placeholder">
                <div className="placeholder-icon">
                  <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
                  </svg>
                </div>
                <h3>Select a route to explore</h3>
                <p>Expand the files in the sidebar and choose a route or command to visualize its execution lifecycle and dependencies.</p>
              </div>
            </Tooltip>
          )}
          {!tabState.loading && activeTab && elements.length === 0 && !tabState.error && (
            <Tooltip content="This endpoint produced no analyzable nodes. It may be a closure, a redirect-only route, or outside the scanner’s rules.">
              <div className="graph-placeholder">
                <div className="placeholder-icon">
                  <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="12" y1="8" x2="12" y2="12" />
                    <line x1="12" y1="16" x2="12.01" y2="16" />
                  </svg>
                </div>
                <h3>Empty Graph</h3>
                <p>No nodes or edges found for this route.</p>
              </div>
            </Tooltip>
          )}
          {!tabState.loading && elements.length > 0 && (
            <GraphView
              key={activeTab?.id}
              elements={elements}
              layout={layout}
              searchQuery={searchQuery}
              rankDir={rankDir}
              visibleTypes={visibleTypes}
              theme={theme}
              onNodeSelect={handleNodeSelect}
              graphRef={graphRef}
              stressTestNodeId={stressTestNodeId}
              stressRunKey={stressRunKey}
              complexityOverlay={complexityOverlay}
              securityOverlay={securityOverlay}
              compact={compact}
              onLayoutChange={setLayout}
              onRankDirChange={setRankDir}
              onToggleComplexityOverlay={() => setComplexityOverlay(v => !v)}
              onToggleSecurityOverlay={() => setSecurityOverlay(v => !v)}
              onToggleCompact={() => setCompact(v => !v)}
            />
          )}
        </div>
        {selectedId && (
          <Sidebar
            selectedId={selectedId}
            graphData={tabState.data}
            theme={theme}
            onClose={() => setSelectedId(null)}
            onStressChange={(nodeId) => {
              setStressTestNodeId(nodeId)
              if (nodeId !== null) setStressRunKey((k) => k + 1)
            }}
          />
        )}
      </div>
    </div>
  )
}
