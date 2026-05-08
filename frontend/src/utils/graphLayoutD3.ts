import dagre from 'dagre'
import {
  forceCenter,
  forceCollide,
  forceLink,
  forceManyBody,
  forceSimulation,
} from 'd3-force'
import type { GraphElement } from '../types/graph'

export interface LayoutNode {
  id: string
  x: number
  y: number
  width: number
  height: number
  lines: string[]
  data: GraphElement['data']
}

export interface LayoutEdge {
  id: string
  source: string
  target: string
  data: GraphElement['data']
}

export function nodePrefixFromData(d: GraphElement['data']): string {
  let prefix = ''
  if (d.hasN1) prefix += '⚠️ '
  if (d.fatMethod) prefix += '🧱 '
  if (d.fatClass) prefix += '🏗️ '
  const vis = d.visibility
  if (vis === 'private') prefix += '🔒 '
  if (vis === 'protected') prefix += '🛡️ '
  return prefix
}

/** Split "ClassName@method" or "ClassName::method" into [className, method]. */
export function splitNodeLabel(label: string, dataMethod?: string): { className: string; method: string } {
  const atIdx = label.indexOf('@')
  const colIdx = label.indexOf('::')
  if (atIdx !== -1) {
    return { className: label.slice(0, atIdx), method: dataMethod ?? label.slice(atIdx + 1) }
  }
  if (colIdx !== -1) {
    return { className: label.slice(0, colIdx), method: label.slice(colIdx + 2) }
  }
  return { className: label, method: dataMethod ?? '' }
}

/** Card-style node dimensions (fixed height, width based on longest visible text). */
export const CARD_H = 90
export const COMPACT_CARD_H = 40
export const CARD_W_MIN = 185
export const CARD_W_MAX = 270
export const COMPACT_CARD_W_MIN = 120

export function buildLayoutNode(d: GraphElement['data'], compact = false): LayoutNode {
  const rawLabel = String(d.label ?? d.id)
  const { className, method } = splitNodeLabel(rawLabel, d.method as string | undefined)
  const longestText = compact
    ? className
    : className.length > method.length ? className : method
  const wMin = compact ? COMPACT_CARD_W_MIN : CARD_W_MIN
  const width = Math.max(wMin, Math.min(CARD_W_MAX, longestText.length * 7.6 + 44))
  const height = compact ? COMPACT_CARD_H : CARD_H
  return {
    id: d.id,
    x: 0,
    y: 0,
    width,
    height,
    lines: [className, method].filter(Boolean),
    data: d,
  }
}

export function wrapLabel(text: string, maxChars = 18): string[] {
  const words = text.split(/\s+/)
  const lines: string[] = []
  let cur = ''
  for (const w of words) {
    const next = cur ? `${cur} ${w}` : w
    if (next.length <= maxChars) {
      cur = next
    } else {
      if (cur) lines.push(cur)
      cur = w.length > maxChars ? w.slice(0, maxChars) + '…' : w
    }
  }
  if (cur) lines.push(cur)
  return lines.length ? lines : ['']
}

export function centerNodes(nodes: LayoutNode[]): void {
  if (!nodes.length) return
  let sx = 0
  let sy = 0
  for (const n of nodes) {
    sx += n.x
    sy += n.y
  }
  const cx = sx / nodes.length
  const cy = sy / nodes.length
  for (const n of nodes) {
    n.x -= cx
    n.y -= cy
  }
}

export function layoutDagre(nodes: LayoutNode[], edges: LayoutEdge[], rankDir: 'LR' | 'TB'): void {
  const g = new dagre.graphlib.Graph()
  g.setGraph({
    rankdir: rankDir,
    nodesep: rankDir === 'TB' ? 70 : 50,
    ranksep: rankDir === 'TB' ? 100 : 120,
    marginx: 60,
    marginy: 60,
  })
  g.setDefaultEdgeLabel(() => ({}))
  for (const n of nodes) {
    g.setNode(n.id, { width: n.width, height: n.height })
  }
  for (const e of edges) {
    if (g.hasNode(e.source) && g.hasNode(e.target)) {
      g.setEdge(e.source, e.target)
    }
  }
  dagre.layout(g)
  for (const n of nodes) {
    const nd = g.node(n.id)
    if (nd) {
      n.x = nd.x
      n.y = nd.y
    }
  }
}

/** Layered layout similar to Cytoscape breadthfirst (good for large graphs). */
export function layoutBreadthFirst(
  nodes: LayoutNode[],
  edges: LayoutEdge[],
  rankDir: 'LR' | 'TB',
  spacingX = 88,
  spacingY = 88,
): void {
  const ids = new Set(nodes.map((n) => n.id))
  const adj = new Map<string, string[]>()
  const indeg = new Map<string, number>()
  for (const n of nodes) {
    adj.set(n.id, [])
    indeg.set(n.id, 0)
  }
  for (const e of edges) {
    if (!ids.has(e.source) || !ids.has(e.target)) continue
    adj.get(e.source)!.push(e.target)
    indeg.set(e.target, (indeg.get(e.target) ?? 0) + 1)
  }
  const roots = nodes.filter((n) => indeg.get(n.id) === 0).map((n) => n.id)
  const level = new Map<string, number>()
  const q = [...roots]
  for (const r of roots) level.set(r, 0)
  while (q.length) {
    const u = q.shift()!
    const lv = level.get(u)!
    for (const v of adj.get(u) ?? []) {
      const nextLv = lv + 1
      if (!level.has(v) || level.get(v)! < nextLv) {
        level.set(v, nextLv)
        q.push(v)
      }
    }
  }
  for (const n of nodes) {
    if (!level.has(n.id)) level.set(n.id, 0)
  }
  const layers = new Map<number, string[]>()
  for (const n of nodes) {
    const l = level.get(n.id)!
    if (!layers.has(l)) layers.set(l, [])
    layers.get(l)!.push(n.id)
  }
  for (const arr of layers.values()) arr.sort()
  for (const [, idsInLayer] of layers) {
    const l = level.get(idsInLayer[0])!
    idsInLayer.forEach((id, i) => {
      const node = nodes.find((n) => n.id === id)!
      if (rankDir === 'TB') {
        node.x = i * spacingX - ((idsInLayer.length - 1) * spacingX) / 2
        node.y = l * spacingY
      } else {
        node.x = l * spacingX
        node.y = i * spacingY - ((idsInLayer.length - 1) * spacingY) / 2
      }
    })
  }
}

export function layoutForce(nodes: LayoutNode[], edges: LayoutEdge[]): void {
  type SimNode = LayoutNode & { vx?: number; vy?: number }
  const simNodes: SimNode[] = nodes.map((n) => Object.assign({}, n))
  const idToSim = new Map(simNodes.map((n) => [n.id, n]))
  const links = edges
    .filter((e) => idToSim.has(e.source) && idToSim.has(e.target))
    .map((e) => ({ source: e.source, target: e.target }))

  const sim = forceSimulation(simNodes as SimNode[])
    .force(
      'link',
      forceLink<SimNode, { source: string; target: string }>(links)
        .id((d) => d.id)
        .distance(90),
    )
    .force('charge', forceManyBody().strength(-420))
    .force('center', forceCenter(0, 0))
    .force(
      'collide',
      forceCollide<SimNode>().radius((d) => Math.hypot(d.width, d.height) / 2 + 14),
    )

  sim.stop()
  for (let i = 0; i < 450 && sim.alpha() > 0.02; i++) sim.tick()

  for (const n of nodes) {
    const sn = idToSim.get(n.id)
    if (sn) {
      n.x = sn.x ?? 0
      n.y = sn.y ?? 0
    }
  }
}

export function layoutCircle(nodes: LayoutNode[], radius: number): void {
  const n = nodes.length
  if (!n) return
  nodes.forEach((node, i) => {
    const a = (i / n) * Math.PI * 2 - Math.PI / 2
    node.x = radius * Math.cos(a)
    node.y = radius * Math.sin(a)
  })
}

export function layoutGrid(nodes: LayoutNode[], cellW = 200, cellH = 120): void {
  const cols = Math.ceil(Math.sqrt(Math.max(1, nodes.length)))
  nodes.forEach((node, i) => {
    const row = Math.floor(i / cols)
    const col = i % cols
    node.x = col * cellW
    node.y = row * cellH
  })
}

export function pickLayoutKind(
  layoutName: string,
  nodeCount: number,
  largeThreshold: number,
): 'dagre' | 'breadthfirst' | 'force' | 'circle' | 'grid' {
  if (layoutName === 'dagre' && nodeCount > largeThreshold) return 'breadthfirst'
  if (layoutName === 'dagre') return 'dagre'
  if (layoutName === 'cose-bilkent') return 'force'
  if (layoutName === 'breadthfirst') return 'breadthfirst'
  if (layoutName === 'circle') return 'circle'
  if (layoutName === 'grid') return 'grid'
  return 'dagre'
}

export function partitionElements(elements: GraphElement[], compact = false): {
  nodes: LayoutNode[]
  edges: LayoutEdge[]
} {
  const nodes: LayoutNode[] = []
  const edges: LayoutEdge[] = []
  for (const el of elements) {
    const d = el.data
    if (d.source != null && d.target != null) {
      edges.push({
        id: d.id,
        source: String(d.source),
        target: String(d.target),
        data: d,
      })
    } else {
      nodes.push(buildLayoutNode(d, compact))
    }
  }
  return { nodes, edges }
}
