import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { select, zoom, zoomIdentity, type ZoomBehavior, type ZoomTransform } from 'd3'
import html2canvas from 'html2canvas'
import type { GraphElement, GraphViewportRef } from '../types/graph'
import {
  LARGE_GRAPH_THRESHOLD,
  PACKET_ANIMATION_THRESHOLD,
  ACCENT_COLORS,
  ACCENT_COLORS_LIGHT,
  BG_COLORS,
  BG_COLORS_LIGHT,
  HIGHLIGHT_COLOR,
  CC_TIERS,
  CC_TIERS_LIGHT,
} from '../utils/graphConstants'
import {
  type LayoutEdge,
  type LayoutNode,
  centerNodes,
  layoutBreadthFirst,
  layoutCircle,
  layoutDagre,
  layoutForce,
  layoutGrid,
  partitionElements,
  pickLayoutKind,
  splitNodeLabel,
} from '../utils/graphLayoutD3'

// ── Geometry ───────────────────────────────────────────────────────────────

interface Packet {
  id: string
  waypoints: Array<{ x: number; y: number }> // model-space bend points along the edge path
  progress: number
  speed: number
  color: string
  pulse: number
  sparkCooldown: number
  tgtNodeId: string
  chained: boolean
  arrived: boolean
  // Latency simulation (stress-test mode)
  stallAt: number        // progress (0–1) at which the packet pauses; 0 = no stall
  stallRemaining: number // ms left in current stall
  timedOut: boolean      // packet times out mid-flight and dies with a red burst
  timeoutAt: number      // progress at which timeout fires; 0 = no timeout
}

interface Spark {
  x: number
  y: number
  vx: number
  vy: number
  life: number
  decay: number
  size: number
  color: string
}

function hex2(n: number) {
  return Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0')
}

function modelToScreen(mx: number, my: number, t: ZoomTransform) {
  return { x: t.applyX(mx), y: t.applyY(my) }
}

/**
 * Evaluate a position at parameter t (0–1) along a piecewise-linear polyline,
 * distributing t proportionally by segment length so the packet moves at
 * consistent apparent speed regardless of segment count or direction.
 */
function evalPolyline(
  t: number,
  pts: Array<{ x: number; y: number }>,
): { x: number; y: number } {
  if (pts.length === 0) return { x: 0, y: 0 }
  if (pts.length === 1) return pts[0]
  if (t <= 0) return pts[0]
  if (t >= 1) return pts[pts.length - 1]

  let totalLen = 0
  const segLens: number[] = []
  for (let i = 0; i < pts.length - 1; i++) {
    const dx = pts[i + 1].x - pts[i].x
    const dy = pts[i + 1].y - pts[i].y
    const len = Math.sqrt(dx * dx + dy * dy)
    segLens.push(len)
    totalLen += len
  }
  if (totalLen === 0) return pts[0]

  const target = t * totalLen
  let accumulated = 0
  for (let i = 0; i < segLens.length; i++) {
    const segLen = segLens[i]
    if (segLen === 0) continue
    if (accumulated + segLen >= target || i === segLens.length - 1) {
      const localT = (target - accumulated) / segLen
      const p0 = pts[i]
      const p1 = pts[i + 1]
      return {
        x: p0.x + (p1.x - p0.x) * localT,
        y: p0.y + (p1.y - p0.y) * localT,
      }
    }
    accumulated += segLen
  }
  return pts[pts.length - 1]
}

/**
 * Return the orthogonal-path waypoints for an edge in model space.
 * These mirror the geometry produced by `orthogonalPath` so packets
 * travel along exactly the same route as the drawn SVG edge.
 */
/**
 * Pick exit/entry ports for an edge based on actual relative node positions.
 * Compares the axis-gap between the two nodes and routes along the dominant axis.
 * This makes edges re-route naturally when nodes are dragged.
 */
function pickPorts(
  ns: LayoutNode,
  nt: LayoutNode,
): { ex: number; ey: number; tx: number; ty: number; vertical: boolean } {
  const cdx = nt.x - ns.x
  const cdy = nt.y - ns.y
  // Gap between node bounding boxes on each axis (negative = overlapping)
  const hGap = Math.abs(cdx) - (ns.width + nt.width) / 2
  const vGap = Math.abs(cdy) - (ns.height + nt.height) / 2
  const useVertical = vGap >= hGap
  if (useVertical) {
    if (cdy >= 0) {
      return { ex: ns.x, ey: ns.y + ns.height / 2, tx: nt.x, ty: nt.y - nt.height / 2, vertical: true }
    } else {
      return { ex: ns.x, ey: ns.y - ns.height / 2, tx: nt.x, ty: nt.y + nt.height / 2, vertical: true }
    }
  } else {
    if (cdx >= 0) {
      return { ex: ns.x + ns.width / 2, ey: ns.y, tx: nt.x - nt.width / 2, ty: nt.y, vertical: false }
    } else {
      return { ex: ns.x - ns.width / 2, ey: ns.y, tx: nt.x + nt.width / 2, ty: nt.y, vertical: false }
    }
  }
}

function orthogonalWaypoints(
  ns: LayoutNode,
  nt: LayoutNode,
): Array<{ x: number; y: number }> {
  const { ex, ey, tx, ty, vertical } = pickPorts(ns, nt)
  if (vertical) {
    if (Math.abs(ex - tx) < 3 || Math.abs(ty - ey) <= 8) {
      return [{ x: ex, y: ey }, { x: tx, y: ty }]
    }
    const midY = (ey + ty) / 2
    return [{ x: ex, y: ey }, { x: ex, y: midY }, { x: tx, y: midY }, { x: tx, y: ty }]
  } else {
    if (Math.abs(ey - ty) < 3 || Math.abs(tx - ex) <= 8) {
      return [{ x: ex, y: ey }, { x: tx, y: ty }]
    }
    const midX = (ex + tx) / 2
    return [{ x: ex, y: ey }, { x: midX, y: ey }, { x: midX, y: ty }, { x: tx, y: ty }]
  }
}

const ORTHO_R = 7 // corner rounding radius

/** Clamp corner radius so it never overflows any of the given segment lengths. */
function clampR(...dists: number[]): number {
  return Math.max(0, Math.min(ORTHO_R, ...dists.map((d) => d - 1)))
}

/**
 * Build an orthogonal (elbow) SVG path between two nodes.
 * Ports are chosen dynamically based on the relative node positions so that
 * edges re-route correctly when nodes are dragged to a new position.
 */
function orthogonalPath(
  ns: LayoutNode,
  nt: LayoutNode,
): { d: string; lx: number; ly: number; exitX: number; exitY: number; entryX: number; entryY: number } {
  const { ex, ey, tx, ty, vertical } = pickPorts(ns, nt)

  if (vertical) {
    if (Math.abs(ex - tx) < 3 || Math.abs(ty - ey) <= 8) {
      return { d: `M${ex},${ey} L${tx},${ty}`, lx: ex + 6, ly: (ey + ty) / 2, exitX: ex, exitY: ey, entryX: tx, entryY: ty }
    }
    const midY = (ey + ty) / 2
    const dySign = ty > ey ? 1 : -1
    const r = clampR(Math.abs(midY - ey), Math.abs(ty - midY), Math.abs(tx - ex))
    const sx = tx > ex ? r : -r
    const d = r > 0
      ? `M${ex},${ey} V${midY - r * dySign} Q${ex},${midY} ${ex + sx},${midY} H${tx - sx} Q${tx},${midY} ${tx},${midY + r * dySign} V${ty}`
      : `M${ex},${ey} V${midY} H${tx} V${ty}`
    return { d, lx: (ex + tx) / 2, ly: midY - 14 * dySign, exitX: ex, exitY: ey, entryX: tx, entryY: ty }
  } else {
    if (Math.abs(ey - ty) < 3 || Math.abs(tx - ex) <= 8) {
      return { d: `M${ex},${ey} L${tx},${ty}`, lx: (ex + tx) / 2, ly: ey - 10, exitX: ex, exitY: ey, entryX: tx, entryY: ty }
    }
    const midX = (ex + tx) / 2
    const dxSign = tx > ex ? 1 : -1
    const r = clampR(Math.abs(midX - ex), Math.abs(tx - midX), Math.abs(ty - ey))
    const sy = ty > ey ? r : -r
    const d = r > 0
      ? `M${ex},${ey} H${midX - r * dxSign} Q${midX},${ey} ${midX},${ey + sy} V${ty - sy} Q${midX},${ty} ${midX + r * dxSign},${ty} H${tx}`
      : `M${ex},${ey} H${midX} V${ty} H${tx}`
    return { d, lx: midX + 6 * dxSign, ly: (ey + ty) / 2, exitX: ex, exitY: ey, entryX: tx, entryY: ty }
  }
}

function labelForEdge(d: LayoutEdge['data'], dark: boolean) {
  const lbl = String(d.label ?? '')
  if (!lbl) return null
  const fill = dark ? 'rgba(255,255,255,0.4)' : 'rgba(0,0,0,0.5)'
  const bg = dark ? '#111218' : '#fff'
  return { text: lbl, fill, bg }
}

function cardColors(
  n: LayoutNode,
  dark: boolean,
  complexityOverlay: boolean,
  selected: boolean,
  stN: boolean,
): { bg: string; border: string; borderW: number; accent: string } {
  const type = String(n.data.type ?? '')
  const accent = dark
    ? (ACCENT_COLORS[type] ?? '#c9d1d9')
    : (ACCENT_COLORS_LIGHT[type] ?? '#333')
  const bg = dark
    ? (BG_COLORS[type] ?? '#0d1117')
    : (BG_COLORS_LIGHT[type] ?? '#ffffff')
  const cc = Number(n.data.metrics_cc ?? 0) || 0

  if (complexityOverlay) {
    const tiers = dark ? CC_TIERS : CC_TIERS_LIGHT
    const tier = tiers.find((t) => cc >= t.min && cc <= t.max) ?? tiers[0]
    const border = stN ? '#a855f7' : n.data.hasN1 ? '#F44336' : tier.border
    return { bg: tier.fill, border, borderW: 1.5, accent: tier.border }
  }

  let border = dark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.12)'
  let borderW = 1
  if (n.data.hasN1) { border = '#F44336'; borderW = 2 }
  if (selected) { border = accent; borderW = 2 }
  if (stN) { border = '#a855f7'; borderW = 2 }

  return { bg, border, borderW, accent }
}

// ── Props ───────────────────────────────────────────────────────────────────

interface Props {
  elements: GraphElement[]
  layout: string
  rankDir: 'LR' | 'TB'
  searchQuery: string
  visibleTypes: Set<string>
  theme: 'dark' | 'light'
  onNodeSelect: (id: string | null) => void
  graphRef: React.MutableRefObject<GraphViewportRef | null>
  stressTestNodeId?: string | null
  stressRunKey?: number
  complexityOverlay: boolean
  compact?: boolean
}

export function GraphView({
  elements,
  layout,
  rankDir,
  searchQuery,
  visibleTypes,
  theme,
  onNodeSelect,
  graphRef,
  stressTestNodeId,
  stressRunKey,
  complexityOverlay,
  compact = false,
}: Props) {
  const dark = theme === 'dark'
  // Previous alphas (0.07 / 0.1) made edge strokes nearly invisible while marker tips still showed.
  const edgeLine = dark ? 'rgba(255,255,255,0.32)' : 'rgba(0,0,0,0.38)'
  const edgeArrow = dark ? 'rgba(255,255,255,0.55)' : 'rgba(0,0,0,0.55)'

  const { nodes: baseNodes, edges: baseEdges } = useMemo(() => partitionElements(elements, compact), [elements, compact])

  const visibleNodeCount = useMemo(
    () => baseNodes.filter((n) => visibleTypes.has(String(n.data.type))).length,
    [baseNodes, visibleTypes],
  )

  const [layoutTick, setLayoutTick] = useState(0)
  const layoutTimeout = useRef<number | null>(null)
  const layoutDebounceSkipMount = useRef(true)

  useEffect(() => {
    if (layoutDebounceSkipMount.current) {
      layoutDebounceSkipMount.current = false
      return
    }
    if (layoutTimeout.current) window.clearTimeout(layoutTimeout.current)
    layoutTimeout.current = window.setTimeout(() => {
      setLayoutTick((k) => k + 1)
    }, 200)
    return () => {
      if (layoutTimeout.current) window.clearTimeout(layoutTimeout.current)
    }
  }, [visibleTypes, layout, rankDir, compact])

  const { nodes, edges } = useMemo(() => {
    void layoutTick
    const nodesCopy = baseNodes.map((n) => ({ ...n, lines: [...n.lines] }))
    const edgesCopy = baseEdges.map((e) => ({ ...e }))
    const kind = pickLayoutKind(layout, visibleNodeCount, LARGE_GRAPH_THRESHOLD)

    if (kind === 'dagre') layoutDagre(nodesCopy, edgesCopy, rankDir)
    else if (kind === 'breadthfirst') layoutBreadthFirst(nodesCopy, edgesCopy, rankDir)
    else if (kind === 'force') layoutForce(nodesCopy, edgesCopy)
    else if (kind === 'circle') {
      const r = Math.min(280, 90 + nodesCopy.length * 4)
      layoutCircle(nodesCopy, r)
    } else layoutGrid(nodesCopy, 200, 130)

    centerNodes(nodesCopy)
    return { nodes: nodesCopy, edges: edgesCopy }
  }, [baseNodes, baseEdges, layout, rankDir, layoutTick, visibleNodeCount])

  const nodeById = useMemo(() => new Map(nodes.map((n) => [n.id, n])), [nodes])

  // ── Per-node drag overrides ────────────────────────────────────────────────
  const [draggedPositions, setDraggedPositions] = useState<Map<string, { x: number; y: number }>>(new Map())
  const dragStateRef = useRef<{
    nodeId: string
    startSX: number
    startSY: number
    origMX: number
    origMY: number
  } | null>(null)
  const isDraggingRef = useRef(false)

  // ── Collapse state ─────────────────────────────────────────────────────────
  const [collapsedNodes, setCollapsedNodes] = useState<Set<string>>(new Set())

  // Reset drag and collapse state when nodes change (during render, not in an effect).
  const [prevNodes, setPrevNodes] = useState(nodes)
  if (prevNodes !== nodes) {
    setPrevNodes(nodes)
    setDraggedPositions(new Map())
    setCollapsedNodes(new Set())
  }

  const effectiveNodes = useMemo(() => {
    if (draggedPositions.size === 0) return nodes
    return nodes.map((n) => {
      const pos = draggedPositions.get(n.id)
      return pos ? { ...n, x: pos.x, y: pos.y } : n
    })
  }, [nodes, draggedPositions])

  const effectiveNodeById = useMemo(
    () => new Map(effectiveNodes.map((n) => [n.id, n])),
    [effectiveNodes],
  )

  // Keep a ref so packet-spawn callbacks always read the latest dragged positions
  // without needing effectiveNodeById in their dependency arrays.
  const effectiveNodeByIdRef = useRef(effectiveNodeById)
  useEffect(() => { effectiveNodeByIdRef.current = effectiveNodeById }, [effectiveNodeById])

  const isTypeVisible = useCallback((type: unknown) => visibleTypes.has(String(type)), [visibleTypes])

  const edgeVisible = useCallback(
    (e: LayoutEdge) =>
      isTypeVisible(nodeById.get(e.source)?.data.type) &&
      isTypeVisible(nodeById.get(e.target)?.data.type),
    [nodeById, isTypeVisible],
  )

  /** Nodes hidden because they are reachable from a collapsed node (recursive BFS). */
  const hiddenNodeIds = useMemo(() => {
    const childrenMap = new Map<string, string[]>()
    for (const n of nodes) childrenMap.set(n.id, [])
    for (const e of edges) {
      if (!edgeVisible(e)) continue
      childrenMap.get(e.source)?.push(e.target)
    }
    const hidden = new Set<string>()
    for (const collapsedId of collapsedNodes) {
      const queue = [collapsedId]
      const visited = new Set<string>([collapsedId])
      while (queue.length) {
        const id = queue.shift()!
        for (const child of childrenMap.get(id) ?? []) {
          if (!visited.has(child)) {
            visited.add(child)
            hidden.add(child)
            queue.push(child)
          }
        }
      }
    }
    return hidden
  }, [nodes, edges, edgeVisible, collapsedNodes])

  /** Number of currently-visible (non-hidden) outgoing edges per node. */
  const outDegree = useMemo(() => {
    const map = new Map<string, number>()
    for (const e of edges) {
      if (!edgeVisible(e)) continue
      if (hiddenNodeIds.has(e.target)) continue
      map.set(e.source, (map.get(e.source) ?? 0) + 1)
    }
    return map
  }, [edges, edgeVisible, hiddenNodeIds])

  const toggleCollapse = useCallback((ev: React.MouseEvent, nodeId: string) => {
    ev.stopPropagation()
    setCollapsedNodes((prev) => {
      const next = new Set(prev)
      if (next.has(nodeId)) next.delete(nodeId)
      else next.add(nodeId)
      return next
    })
  }, [])

  /** Total hidden descendants per collapsed node (for the badge label). */
  const hiddenDescendantCount = useMemo(() => {
    const map = new Map<string, number>()
    for (const collapsedId of collapsedNodes) {
      let count = 0
      const visited = new Set<string>()
      const queue = [collapsedId]
      while (queue.length) {
        const id = queue.shift()!
        for (const e of edges) {
          if (e.source !== id || !edgeVisible(e)) continue
          const child = e.target
          if (visited.has(child)) continue
          visited.add(child)
          if (hiddenNodeIds.has(child)) {
            count++
            queue.push(child)
          }
        }
      }
      map.set(collapsedId, count)
    }
    return map
  }, [collapsedNodes, hiddenNodeIds, edges, edgeVisible])

  const searchMatch = useMemo(() => {
    if (!searchQuery.trim()) return null
    const q = searchQuery.toLowerCase()
    const set = new Set<string>()
    for (const n of nodes) {
      const label = String(n.data.label ?? n.id).toLowerCase()
      if (label.includes(q)) set.add(n.id)
    }
    return set
  }, [nodes, searchQuery])

  const stressSets = useMemo(() => {
    const emptyN = new Set<string>()
    const emptyE = new Set<string>()
    if (!stressTestNodeId || !nodeById.has(stressTestNodeId)) {
      return { nodes: emptyN, edges: emptyE }
    }
    const visNodes = new Set<string>()
    const visEdges = new Set<string>()
    const visited = new Set<string>()
    const q = [stressTestNodeId]
    while (q.length) {
      const id = q.shift()!
      if (visited.has(id)) continue
      visited.add(id)
      visNodes.add(id)
      for (const e of edges) {
        if (e.source !== id) continue
        if (!edgeVisible(e)) continue
        visEdges.add(e.id)
        const t = e.target
        if (!visited.has(t)) q.push(t)
      }
    }
    return { nodes: visNodes, edges: visEdges }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stressTestNodeId, stressRunKey, edges, edgeVisible, nodeById])

  const [highlightEdgeIds, setHighlightEdgeIds] = useState<Set<string>>(new Set())
  const [selectedNodeId, setSelectedNodeId] = useState<string | null>(null)
  const tapNode = useCallback(
    (id: string) => {
      const next = new Set<string>()
      for (const e of edges) {
        if (e.source === id || e.target === id) next.add(e.id)
      }
      setHighlightEdgeIds(next)
      setSelectedNodeId(id)
      onNodeSelect(id)
    },
    [edges, onNodeSelect],
  )

  const tapBg = useCallback(() => {
    setHighlightEdgeIds(new Set())
    setSelectedNodeId(null)
    onNodeSelect(null)
  }, [onNodeSelect])

  // ── Node drag handlers ─────────────────────────────────────────────────────
  const handleNodePointerDown = useCallback(
    (e: React.PointerEvent<SVGGElement>, nodeId: string, mx: number, my: number) => {
      e.stopPropagation()
      ;(e.currentTarget as SVGGElement).setPointerCapture(e.pointerId)
      isDraggingRef.current = false
      dragStateRef.current = { nodeId, startSX: e.clientX, startSY: e.clientY, origMX: mx, origMY: my }
    },
    [],
  )

  const handleNodePointerMove = useCallback(
    (e: React.PointerEvent<SVGGElement>, nodeId: string) => {
      const ds = dragStateRef.current
      if (!ds || ds.nodeId !== nodeId) return
      const dx = e.clientX - ds.startSX
      const dy = e.clientY - ds.startSY
      if (!isDraggingRef.current && Math.abs(dx) < 4 && Math.abs(dy) < 4) return
      isDraggingRef.current = true
      const k = transformRef.current.k
      setDraggedPositions((prev) => {
        const next = new Map(prev)
        next.set(nodeId, { x: ds.origMX + dx / k, y: ds.origMY + dy / k })
        return next
      })
    },
    [],
  )

  const handleNodePointerUp = useCallback((_e: React.PointerEvent<SVGGElement>, nodeId: string) => {
    if (dragStateRef.current?.nodeId === nodeId) dragStateRef.current = null
  }, [])

  /** Packet animation */
  const containerRef = useRef<HTMLDivElement>(null)
  const svgRef = useRef<SVGSVGElement>(null)
  const innerRef = useRef<SVGGElement>(null)
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const packetsRef = useRef<Packet[]>([])
  const sparksRef = useRef<Spark[]>([])
  const lastFrameRef = useRef<number>(0)
  const nodeLastFiredRef = useRef<Map<string, number>>(new Map())
  const transformRef = useRef<ZoomTransform>(zoomIdentity)
  const zoomBehaviorRef = useRef<ZoomBehavior<SVGSVGElement, unknown> | null>(null)

  const spawnPacket = useCallback(
    (edgeId: string, color: string, delay = 0, chained = false) => {
      const e = edges.find((x) => x.id === edgeId)
      if (!e || !edgeVisible(e)) return
      const ns = effectiveNodeByIdRef.current.get(e.source)
      const nt = effectiveNodeByIdRef.current.get(e.target)
      if (!ns || !nt) return

      const waypoints = orthogonalWaypoints(ns, nt)

      // Latency simulation: only for stress-test packets (chained = true)
      const stallAt = chained && Math.random() < 0.65
        ? 0.15 + Math.random() * 0.55
        : 0
      const stallRemaining = stallAt > 0 ? 120 + Math.random() * 700 : 0
      const timedOut = chained && Math.random() < 0.12
      const timeoutAt = timedOut ? 0.25 + Math.random() * 0.55 : 0

      setTimeout(() => {
        packetsRef.current.push({
          id: `${edgeId}-${Date.now()}-${Math.random()}`,
          waypoints,
          progress: 0,
          speed: 0.0009 + Math.random() * 0.0004,
          color,
          pulse: 0,
          sparkCooldown: 0,
          tgtNodeId: e.target,
          chained,
          arrived: false,
          stallAt,
          stallRemaining,
          timedOut,
          timeoutAt,
        })
      }, delay)
    },
    [edges, edgeVisible, rankDir],
  )

  const spawnChainFromNode = useCallback(
    (nodeId: string, color: string, delay = 0) => {
      const COOLDOWN_MS = 1800
      const now = Date.now()
      const last = nodeLastFiredRef.current.get(nodeId) ?? 0
      if (now - last < COOLDOWN_MS) return
      nodeLastFiredRef.current.set(nodeId, now)

      let i = 0
      for (const edge of edges) {
        if (edge.source !== nodeId) continue
        if (!edgeVisible(edge)) continue
        spawnPacket(edge.id, color, delay + i * 60, true)
        i++
      }
    },
    [edges, edgeVisible, spawnPacket],
  )

  useEffect(() => {
    if (!stressTestNodeId || !nodeById.has(stressTestNodeId)) return

    const fire = () => {
      let i = 0
      for (const edge of edges) {
        if (edge.source !== stressTestNodeId) continue
        if (!edgeVisible(edge)) continue
        spawnPacket(edge.id, '#a855f7', i * 80, true)
        i++
      }
    }

    fire()
    const id = window.setInterval(fire, 700)
    return () => window.clearInterval(id)
  }, [stressTestNodeId, stressRunKey, edges, edgeVisible, nodeById, spawnPacket])

  useEffect(() => {
    let rafId: number

    function loop(now: number) {
      rafId = requestAnimationFrame(loop)
      const canvas = canvasRef.current
      if (!canvas) return

      const dt = Math.min(now - lastFrameRef.current, 50)
      lastFrameRef.current = now

      const ctx = canvas.getContext('2d')
      if (!ctx) return
      ctx.clearRect(0, 0, canvas.width, canvas.height)

      const t = transformRef.current
      const z = Math.max(0.6, t.k)
      ctx.globalCompositeOperation = 'lighter'

      const alive: Packet[] = []
      const nodeCount = nodes.length
      const allowPackets = nodeCount <= PACKET_ANIMATION_THRESHOLD || stressTestNodeId

      // Congestion factor: more packets in flight → everyone slows down (simulates server saturation)
      const inFlight = packetsRef.current.filter((p) => p.progress < 1).length
      const congestionFactor = Math.max(0.12, 1 - Math.max(0, inFlight - 4) * 0.055)

      for (const pkt of packetsRef.current) {
        if (!allowPackets) continue

        // ── Timeout: packet dies mid-flight with a red explosion ─────────────
        if (pkt.timedOut && pkt.timeoutAt > 0 && pkt.progress >= pkt.timeoutAt) {
          const screenWaypoints = pkt.waypoints.map((wp) => modelToScreen(wp.x, wp.y, t))
          const deadPos = evalPolyline(pkt.timeoutAt, screenWaypoints)
          const count = 18
          for (let j = 0; j < count; j++) {
            const a = (j / count) * Math.PI * 2 + Math.random() * 0.4
            const s = 0.06 + Math.random() * 0.14
            sparksRef.current.push({
              x: deadPos.x, y: deadPos.y,
              vx: Math.cos(a) * s, vy: Math.sin(a) * s,
              life: 1, decay: 0.0014 + Math.random() * 0.001,
              size: (1.4 + Math.random() * 2) * z, color: '#ef4444',
            })
          }
          continue // drop the packet
        }

        // ── Stall: pause progress while stallRemaining counts down ───────────
        const isStalling = pkt.stallAt > 0 && pkt.progress >= pkt.stallAt && pkt.stallRemaining > 0
        if (isStalling) {
          pkt.stallRemaining -= dt
        } else if (pkt.progress < 1) {
          pkt.progress = Math.min(1, pkt.progress + pkt.speed * congestionFactor * dt)
        }

        const screenWaypoints = pkt.waypoints.map((wp) => modelToScreen(wp.x, wp.y, t))
        const tgtS = screenWaypoints[screenWaypoints.length - 1]

        const pos = evalPolyline(pkt.progress, screenWaypoints)
        if (!isFinite(pos.x) || !isFinite(pos.y)) {
          alive.push(pkt)
          continue
        }

        // Latency color: stalling → amber; congested → shift toward orange
        const stallFraction = pkt.stallAt > 0 && pkt.stallRemaining > 0
          ? Math.min(1, pkt.stallRemaining / 400)
          : 0
        const drawColor = isStalling
          ? (stallFraction > 0.5 ? '#f59e0b' : '#fb923c') // amber → orange
          : pkt.color

        const trailLength = 0.09
        const trailSteps = 18
        for (let j = trailSteps; j >= 1; j--) {
          const tTrail = pkt.progress - (j / trailSteps) * trailLength
          if (tTrail < 0) continue
          const tp = evalPolyline(tTrail, screenWaypoints)
          const k = 1 - j / trailSteps
          const alpha = k * k * 0.55
          const r = (0.8 + k * 2.6) * z
          ctx.beginPath()
          ctx.arc(tp.x, tp.y, r, 0, Math.PI * 2)
          ctx.fillStyle = drawColor + hex2(alpha * 255)
          ctx.fill()
        }

        ctx.save()
        ctx.shadowBlur = (isStalling ? 34 : 24) * z
        ctx.shadowColor = drawColor
        ctx.beginPath()
        ctx.arc(pos.x, pos.y, 5 * z, 0, Math.PI * 2)
        ctx.fillStyle = drawColor + '66'
        ctx.fill()
        ctx.restore()

        const grad = ctx.createRadialGradient(pos.x, pos.y, 0, pos.x, pos.y, 8 * z)
        grad.addColorStop(0, '#ffffffee')
        grad.addColorStop(0.35, drawColor + 'cc')
        grad.addColorStop(1, drawColor + '00')
        ctx.fillStyle = grad
        ctx.beginPath()
        ctx.arc(pos.x, pos.y, 8 * z, 0, Math.PI * 2)
        ctx.fill()

        // Stalling packet: pulsing warning ring
        if (isStalling) {
          const warnPulse = 0.5 + 0.5 * Math.sin(now * 0.012)
          ctx.beginPath()
          ctx.arc(pos.x, pos.y, (10 + warnPulse * 8) * z, 0, Math.PI * 2)
          ctx.strokeStyle = '#f59e0b' + hex2(warnPulse * 160)
          ctx.lineWidth = 1.5 * z
          ctx.stroke()
        }

        const flicker = 1 + 0.18 * Math.sin(now * 0.018 + pkt.progress * 12)
        ctx.beginPath()
        ctx.arc(pos.x, pos.y, 2.2 * z * flicker, 0, Math.PI * 2)
        ctx.fillStyle = '#ffffff'
        ctx.fill()

        if (pkt.progress < 1) {
          pkt.sparkCooldown -= dt
          if (pkt.sparkCooldown <= 0) {
            pkt.sparkCooldown = 35 + Math.random() * 40
            const angle = Math.random() * Math.PI * 2
            const speed = 0.02 + Math.random() * 0.04
            sparksRef.current.push({
              x: pos.x,
              y: pos.y,
              vx: Math.cos(angle) * speed,
              vy: Math.sin(angle) * speed,
              life: 1,
              decay: 0.0028 + Math.random() * 0.0012,
              size: (0.8 + Math.random() * 1.4) * z,
              color: drawColor,
            })
          }
        }

        if (pkt.progress >= 1) {
          if (!pkt.arrived) {
            pkt.arrived = true
            const count = 14
            for (let j = 0; j < count; j++) {
              const a = (j / count) * Math.PI * 2 + Math.random() * 0.3
              const s = 0.08 + Math.random() * 0.12
              sparksRef.current.push({
                x: tgtS.x,
                y: tgtS.y,
                vx: Math.cos(a) * s,
                vy: Math.sin(a) * s,
                life: 1,
                decay: 0.0018 + Math.random() * 0.0008,
                size: (1.2 + Math.random() * 1.6) * z,
                color: pkt.color,
              })
            }
            if (pkt.chained) {
              const tgtNode = nodeById.get(pkt.tgtNodeId)
              const chainColor = tgtNode
                ? ACCENT_COLORS[String(tgtNode.data.type)] || pkt.color
                : pkt.color
              spawnChainFromNode(pkt.tgtNodeId, chainColor, 120)
            }
          }
          pkt.pulse = Math.min(1, pkt.pulse + 0.025)
          if (pkt.pulse < 1) {
            for (let r = 0; r < 3; r++) {
              const rp = pkt.pulse - r * 0.18
              if (rp <= 0 || rp >= 1) continue
              const ringR = (3 + rp * 38) * z
              const a = (1 - rp) * (1 - rp) * 220
              ctx.beginPath()
              ctx.arc(tgtS.x, tgtS.y, ringR, 0, Math.PI * 2)
              ctx.strokeStyle = pkt.color + hex2(a)
              ctx.lineWidth = 1.5 * z
              ctx.stroke()
            }
            const flashA = (1 - pkt.pulse) * (1 - pkt.pulse) * 255
            ctx.save()
            ctx.shadowBlur = 18 * z
            ctx.shadowColor = pkt.color
            ctx.beginPath()
            ctx.arc(tgtS.x, tgtS.y, 4 * z, 0, Math.PI * 2)
            ctx.fillStyle = '#ffffff' + hex2(flashA)
            ctx.fill()
            ctx.restore()
            alive.push(pkt)
          }
        } else {
          alive.push(pkt)
        }
      }

      const aliveSparks: Spark[] = []
      for (const sp of sparksRef.current) {
        sp.x += sp.vx * dt
        sp.y += sp.vy * dt
        sp.vx *= 0.985
        sp.vy *= 0.985
        sp.life -= sp.decay * dt
        if (sp.life <= 0) continue
        const r = Math.max(0.3, sp.size * sp.life)
        ctx.beginPath()
        ctx.arc(sp.x, sp.y, r, 0, Math.PI * 2)
        ctx.fillStyle = sp.color + hex2(sp.life * 220)
        ctx.fill()
        aliveSparks.push(sp)
      }
      sparksRef.current = aliveSparks
      ctx.globalCompositeOperation = 'source-over'
      packetsRef.current = alive
    }

    lastFrameRef.current = performance.now()
    rafId = requestAnimationFrame(loop)
    return () => cancelAnimationFrame(rafId)
  }, [nodeById, spawnChainFromNode, nodes.length, stressTestNodeId])

  useEffect(() => {
    if (nodes.length > PACKET_ANIMATION_THRESHOLD && !stressTestNodeId) {
      packetsRef.current = []
      sparksRef.current = []
    }
  }, [nodes.length, stressTestNodeId])

  useEffect(() => {
    const container = containerRef.current
    const canvas = canvasRef.current
    if (!container || !canvas) return
    const ro = new ResizeObserver(() => {
      canvas.width = container.clientWidth
      canvas.height = container.clientHeight
    })
    ro.observe(container)
    canvas.width = container.clientWidth
    canvas.height = container.clientHeight
    return () => ro.disconnect()
  }, [])

  useEffect(() => {
    const svg = svgRef.current
    const g = innerRef.current
    if (!svg || !g) return

    const zr = zoom<SVGSVGElement, unknown>()
      .scaleExtent([0.02, 5])
      .filter((ev) => !dragStateRef.current && (!ev.ctrlKey || ev.type === 'wheel') && !ev.button)
      .on('zoom', (ev) => {
        transformRef.current = ev.transform
        select(g).attr('transform', ev.transform.toString())
      })

    select(svg).call(zr)
    zoomBehaviorRef.current = zr
    return () => {
      select(svg).on('.zoom', null)
    }
  }, [])

  const fit = useCallback(() => {
    const svg = svgRef.current
    const container = containerRef.current
    const zb = zoomBehaviorRef.current
    if (!svg || !container || !zb || !nodes.length) return

    let minX = Infinity
    let minY = Infinity
    let maxX = -Infinity
    let maxY = -Infinity
    for (const n of nodes) {
      minX = Math.min(minX, n.x - n.width / 2)
      maxX = Math.max(maxX, n.x + n.width / 2)
      minY = Math.min(minY, n.y - n.height / 2)
      maxY = Math.max(maxY, n.y + n.height / 2)
    }
    const pad = 48
    const gw = maxX - minX + pad * 2
    const gh = maxY - minY + pad * 2
    const w = container.clientWidth
    const h = container.clientHeight
    const scale = Math.min(w / gw, h / gh, 2) * 0.92
    const cx = (minX + maxX) / 2
    const cy = (minY + maxY) / 2
    const tx = w / 2 - scale * cx
    const ty = h / 2 - scale * cy
    const tr = zoomIdentity.translate(tx, ty).scale(scale)
    select(svg).call(zb.transform, tr)
  }, [nodes])

  const toPng = useCallback(
    async (options?: { scale?: number }) => {
      const el = containerRef.current
      if (!el) return null
      const canvas = await html2canvas(el, {
        scale: options?.scale ?? 2,
        useCORS: true,
        backgroundColor: dark ? '#0a0c10' : '#f6f7f9',
      })
      return canvas.toDataURL('image/png')
    },
    [dark],
  )

  useEffect(() => {
    graphRef.current = { fit, toPng }
    return () => {
      graphRef.current = null
    }
  }, [graphRef, fit, toPng])

  const didInitialFitRef = useRef(false)
  useEffect(() => {
    didInitialFitRef.current = false
  }, [elements])

  useEffect(() => {
    if (!nodes.length || didInitialFitRef.current) return
    didInitialFitRef.current = true
    const id = requestAnimationFrame(() => fit())
    return () => cancelAnimationFrame(id)
  }, [nodes.length, fit, elements])

  return (
    <div ref={containerRef} style={{ position: 'relative', width: '100%', height: '100%' }}>
      <svg
        ref={svgRef}
        role="img"
        aria-label="Execution graph"
        style={{ width: '100%', height: '100%', display: 'block', cursor: 'grab', touchAction: 'none' }}
      >
        <defs>
          <marker
            id="arrow-def"
            markerWidth="9"
            markerHeight="9"
            refX="8"
            refY="4.5"
            orient="auto"
            markerUnits="strokeWidth"
          >
            <path d="M0,0 L0,9 L9,4.5 z" fill={edgeArrow} />
          </marker>
          <marker
            id="arrow-hi"
            markerWidth="9"
            markerHeight="9"
            refX="8"
            refY="4.5"
            orient="auto"
            markerUnits="strokeWidth"
          >
            <path d="M0,0 L0,9 L9,4.5 z" fill={HIGHLIGHT_COLOR} />
          </marker>
          <marker
            id="arrow-st"
            markerWidth="9"
            markerHeight="9"
            refX="8"
            refY="4.5"
            orient="auto"
            markerUnits="strokeWidth"
          >
            <path d="M0,0 L0,9 L9,4.5 z" fill="#a855f7" />
          </marker>
        </defs>
        <g ref={innerRef}>
          <rect
            x={-100000}
            y={-100000}
            width={200000}
            height={200000}
            fill="transparent"
            onClick={tapBg}
            style={{ pointerEvents: 'all' }}
          />
          {edges.map((e) => {
            if (!edgeVisible(e)) return null
            if (collapsedNodes.has(e.source) || hiddenNodeIds.has(e.source) || hiddenNodeIds.has(e.target)) return null
            const ns = effectiveNodeById.get(e.source)
            const nt = effectiveNodeById.get(e.target)
            if (!ns || !nt) return null
            const { d: dStr, lx, ly } = orthogonalPath(ns, nt)
            const mid = { x: lx, y: ly }
            const lbl = labelForEdge(e.data, dark)
            const hi = highlightEdgeIds.has(e.id)
            const st = stressSets.edges.has(e.id)
            let stroke = edgeLine
            let sw = 1.75
            let mo = 'url(#arrow-def)'
            let op = 1
            if (st) {
              stroke = '#a855f7'
              sw = 2
              mo = 'url(#arrow-st)'
              op = 0.7
            }
            if (hi) {
              stroke = HIGHLIGHT_COLOR
              sw = 1.5
              mo = 'url(#arrow-hi)'
              op = 1
            }
            if (searchMatch && !(searchMatch.has(e.source) || searchMatch.has(e.target))) {
              op *= 0.02
            }
            return (
              <g key={e.id}>
                <path
                  d={dStr}
                  fill="none"
                  stroke={stroke}
                  strokeWidth={sw}
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  opacity={op}
                  markerEnd={mo}
                  style={{ pointerEvents: 'auto' }}
                />
                {lbl && op > 0.05 && (
                  <g transform={`translate(${mid.x},${mid.y})`}>
                    <text
                      textAnchor="middle"
                      dominantBaseline="middle"
                      fill={lbl.fill}
                      fontSize={9}
                      fontFamily="ui-monospace, monospace"
                      style={{ pointerEvents: 'none' }}
                    >
                      <tspan
                        dx={0}
                        dy={-8}
                        paintOrder="stroke fill"
                        stroke={lbl.bg}
                        strokeWidth={6}
                        strokeLinejoin="round"
                      >
                        {lbl.text}
                      </tspan>
                    </text>
                  </g>
                )}
              </g>
            )
          })}

          {effectiveNodes.map((n) => {
            if (hiddenNodeIds.has(n.id)) return null
            const typeOk = isTypeVisible(n.data.type)
            const nodeDim = searchMatch && !searchMatch.has(n.id)
            const opacity = !typeOk ? 0 : nodeDim ? 0.07 : 1
            const stN = stressSets.nodes.has(n.id)
            const selected = selectedNodeId === n.id
            const { bg, border, borderW, accent } = cardColors(n, dark, complexityOverlay, selected, stN)

            const rawLabel = String(n.data.label ?? n.id)
            const { className, method } = splitNodeLabel(rawLabel, n.data.method as string | undefined)
            const methodDisplay = method && !method.includes('(') ? method + '()' : method
            const type = String(n.data.type ?? '')
            const w = n.width
            const h = n.height
            const hw = w / 2
            const hh = h / 2

            const labelColor = dark ? '#e6edf3' : '#0d1117'
            const mutedColor = dark ? 'rgba(255,255,255,0.5)' : 'rgba(0,0,0,0.5)'

            const MAX_CLASS = 24
            const MAX_METHOD = 26
            const classDisplay = className.length > MAX_CLASS ? className.slice(0, MAX_CLASS - 1) + '…' : className
            const methodTrimmed = methodDisplay.length > MAX_METHOD ? methodDisplay.slice(0, MAX_METHOD - 1) + '…' : methodDisplay

            return (
              <g
                key={n.id}
                transform={`translate(${n.x},${n.y})`}
                opacity={opacity}
                style={{ pointerEvents: typeOk && opacity > 0.05 ? 'auto' : 'none', cursor: 'grab' }}
                onPointerDown={(ev) => handleNodePointerDown(ev, n.id, n.x, n.y)}
                onPointerMove={(ev) => handleNodePointerMove(ev, n.id)}
                onPointerUp={(ev) => handleNodePointerUp(ev, n.id)}
                onClick={(ev) => { ev.stopPropagation(); if (!isDraggingRef.current) tapNode(n.id) }}
              >
                {/* Glow ring when selected */}
                {selected && (
                  <rect x={-hw - 3} y={-hh - 3} width={w + 6} height={h + 6}
                    rx={compact ? 7 : 13} fill="none" stroke={accent} strokeWidth={6} opacity={0.15} />
                )}

                {/* Card background */}
                <rect x={-hw} y={-hh} width={w} height={h} rx={compact ? 6 : 10}
                  fill={bg} stroke={border} strokeWidth={borderW}
                  filter={Boolean(n.data.hasN1) && !complexityOverlay
                    ? 'drop-shadow(0 0 8px rgba(244,67,54,0.4))' : undefined}
                />

                {compact ? (
                  <>
                    {/* Compact: type dot + class name only, vertically centered */}
                    <circle cx={-hw + 10} cy={0} r={3.5} fill={accent} />
                    <text x={-hw + 20} y={0} fontSize={11} fontWeight={700}
                      fontFamily="ui-sans-serif, system-ui, -apple-system, sans-serif"
                      fill={labelColor} dominantBaseline="middle"
                      style={{ pointerEvents: 'none' }}>
                      {classDisplay}
                    </text>
                    {Boolean(n.data.hasN1) && (
                      <text x={hw - 6} y={0} fontSize={9} textAnchor="end"
                        fontFamily="ui-monospace, monospace" fill="#F44336"
                        dominantBaseline="middle" style={{ pointerEvents: 'none' }}>
                        N+1
                      </text>
                    )}
                  </>
                ) : (
                  <>
                    {/* Type dot */}
                    <circle cx={-hw + 14} cy={-hh + 18} r={4} fill={accent} />

                    {/* Type label */}
                    <text x={-hw + 24} y={-hh + 22} fontSize={10}
                      fontFamily="ui-monospace, monospace" fill={accent} opacity={0.9}
                      style={{ pointerEvents: 'none' }}>
                      {type}
                    </text>

                    {/* N+1 badge */}
                    {Boolean(n.data.hasN1) && (
                      <text x={hw - 10} y={-hh + 22} fontSize={10} textAnchor="end"
                        fontFamily="ui-monospace, monospace" fill="#F44336"
                        style={{ pointerEvents: 'none' }}>
                        N+1
                      </text>
                    )}

                    {/* Class name */}
                    <text x={-hw + 14} y={-hh + 46} fontSize={13} fontWeight={700}
                      fontFamily="ui-sans-serif, system-ui, -apple-system, sans-serif"
                      fill={labelColor} style={{ pointerEvents: 'none' }}>
                      {classDisplay}
                    </text>

                    {/* Method */}
                    {methodTrimmed && (
                      <text x={-hw + 14} y={-hh + 64} fontSize={11}
                        fontFamily="ui-monospace, monospace" fill={mutedColor}
                        style={{ pointerEvents: 'none' }}>
                        ↻ {methodTrimmed}
                      </text>
                    )}
                  </>
                )}



                {/* Collapse / expand toggle — shown when node has > 4 visible children or is already collapsed */}
                {(collapsedNodes.has(n.id) || (outDegree.get(n.id) ?? 0) > 4) && (
                  <g
                    transform={`translate(${hw + 2}, 0)`}
                    onPointerDown={(ev) => ev.stopPropagation()}
                    onClick={(ev) => toggleCollapse(ev, n.id)}
                    style={{ cursor: 'pointer', pointerEvents: 'all' }}
                  >
                    <rect x={0} y={-10} width={64} height={20} rx={10}
                      fill={collapsedNodes.has(n.id) ? accent : (dark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.10)')}
                      stroke={accent} strokeWidth={1}
                    />
                    <text
                      x={32} y={0}
                      textAnchor="middle" dominantBaseline="middle"
                      fill={collapsedNodes.has(n.id) ? '#fff' : accent}
                      fontSize={10} fontWeight={700}
                      fontFamily="ui-monospace, monospace"
                      style={{ pointerEvents: 'none' }}
                    >
                      {collapsedNodes.has(n.id)
                        ? `▶ ${hiddenDescendantCount.get(n.id) ?? outDegree.get(n.id)} hidden`
                        : '▾ fold'}
                    </text>
                  </g>
                )}
              </g>
            )
          })}
        </g>
      </svg>

      <canvas
        ref={canvasRef}
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          pointerEvents: 'none',
          width: '100%',
          height: '100%',
        }}
      />

      {complexityOverlay && (
        <div className="cc-legend">
          <div className="cc-legend-title">Cyclomatic Complexity</div>
          {CC_TIERS.map((tier) => (
            <div key={tier.label} className="cc-legend-row">
              <span className="cc-legend-swatch" style={{ background: tier.border }} />
              <span className="cc-legend-label" style={{ color: tier.border }}>
                {tier.label}
              </span>
              <span className="cc-legend-range">
                {tier.max === Infinity ? `≥${tier.min}` : `${tier.min}–${tier.max}`}
              </span>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}