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
  // Compound, so that work sharing a transaction can be asked to stay together. Dagre places a
  // cluster's children as a unit; without that they scatter by call structure and a boundary
  // drawn around them afterwards inevitably encloses something that was never in the span.
  const g = new dagre.graphlib.Graph({ compound: true })
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

  for (const [cluster, members] of clusterable(nodes)) {
    g.setNode(cluster, {})
    for (const member of members) g.setParent(member.id, cluster)
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

/**
 * Groups of nodes that should be laid out together, keyed by a synthetic cluster id.
 *
 * Only groups of two or more: a single node is already contiguous with itself, and giving it a
 * cluster costs dagre a rank without changing anything on screen.
 */
function clusterable(nodes: LayoutNode[]): Map<string, LayoutNode[]> {
  const groups = new Map<string, LayoutNode[]>()

  for (const node of nodes) {
    const id = (node.data as { transactionId?: unknown } | undefined)?.transactionId

    if (typeof id === 'string' && id !== '') {
      groups.set(id, [...(groups.get(id) ?? []), node])
    }
  }

  for (const [id, members] of groups) {
    if (members.length < 2) groups.delete(id)
  }

  return new Map([...groups].map(([id, members]) => [`cluster::${id}`, members]))
}

/** Layered layout similar to Cytoscape breadthfirst (good for large graphs). */
export function layoutBreadthFirst(
  nodes: LayoutNode[],
  edges: LayoutEdge[],
  rankDir: 'LR' | 'TB',
  // Gaps BETWEEN card edges, not the distance between their centres: a card is 185–270 wide
  // and 90 tall, so a constant centre pitch cannot know whether it clears one. Matches the
  // nodesep/ranksep given to dagre, so the two layouts read alike.
  gapWithinLayer = 60,
  gapBetweenLayers = 110,
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
  // Breadth-first: a node is levelled by the first path that reaches it, and never again.
  // Keeping the deepest level instead makes every trip around a cycle a longer path, so the
  // node is re-queued forever — and an inverse pair of Eloquent relations (hasMany plus its
  // belongsTo) is a cycle, which is to say most model graphs contain one.
  let head = 0
  const drain = (): void => {
    while (head < q.length) {
      const u = q[head++]
      const lv = level.get(u)!
      for (const v of adj.get(u) ?? []) {
        if (!level.has(v)) {
          level.set(v, lv + 1)
          q.push(v)
        }
      }
    }
  }
  drain()

  // Not every node is reachable from a node with nothing pointing at it. Two models joined
  // only by an inverse relation are a component with no entry, and there is nothing to walk
  // in from. Levelling those at 0 collapses them into one undifferentiated row beside the
  // real roots; seeding the least depended-on of them instead gives each component its own
  // hierarchy. `indeg` is only a heuristic for "most root-like", which is all it needs to be.
  for (;;) {
    let seed: LayoutNode | null = null
    for (const n of nodes) {
      if (level.has(n.id)) continue
      if (seed === null || indeg.get(n.id)! < indeg.get(seed.id)!) seed = n
    }
    if (seed === null) break
    level.set(seed.id, 0)
    q.push(seed.id)
    drain()
  }
  const layers = new Map<number, string[]>()
  for (const n of nodes) {
    const l = level.get(n.id)!
    if (!layers.has(l)) layers.set(l, [])
    layers.get(l)!.push(n.id)
  }

  // Within a layer, keep work that shares a transaction next to each other. This layout places
  // by level and would otherwise leave a span's members with unrelated nodes between them, which
  // is enough for a boundary drawn around them to enclose something that was never in the span.
  // Only the order inside a layer changes; no node moves between layers, so the hierarchy the
  // levelling produced is untouched.
  const spanOf = new Map<string, string>()
  for (const n of nodes) {
    const id = (n.data as { transactionId?: unknown } | undefined)?.transactionId
    if (typeof id === 'string' && id !== '') spanOf.set(n.id, id)
  }

  if (spanOf.size > 0) {
    for (const [, ids] of layers) {
      const order = new Map<string, number>()
      let next = 0
      for (const id of ids) {
        const span = spanOf.get(id) ?? `\u0000${id}`
        if (!order.has(span)) order.set(span, next++)
      }
      ids.sort((a, b) => order.get(spanOf.get(a) ?? `\u0000${a}`)! - order.get(spanOf.get(b) ?? `\u0000${b}`)!)
    }
  }
  for (const arr of layers.values()) arr.sort()
  const byId = new Map(nodes.map((n) => [n.id, n]))
  // Pack each layer from the cards' own measurements. `x`/`y` are centres, as dagre reports
  // them, so a card contributes half its size on each side.
  let cursor = 0
  for (const l of [...layers.keys()].sort((a, b) => a - b)) {
    const layerNodes = layers.get(l)!.map((id) => byId.get(id)!)

    // A layer is one line only while it fits on one. Past that it is packed into a block of
    // roughly screen-shaped proportions, because a layer's width is set by how many nodes
    // happen to sit at that depth and nothing bounds it: a graph whose entry points are mostly
    // unconnected — 187 models, or 211 events of which half nothing listens to — puts every one
    // of them at level 0 and lays them in a single row kilometres wide, which fits on screen
    // only at a zoom where no label can be read. Wrapping keeps the layer a layer: the reading
    // order within it is unchanged, and it still occupies its own band between its neighbours.
    const perLine = wrapWidth(layerNodes.length)

    if (rankDir === 'TB') {
      const lines = chunk(layerNodes, perLine)
      let lineTop = cursor
      for (const line of lines) {
        const rowWidth =
          line.reduce((sum, n) => sum + n.width, 0) + gapWithinLayer * (line.length - 1)
        const rowHeight = largestOf(line, (n) => n.height)
        let x = -rowWidth / 2
        for (const node of line) {
          node.x = x + node.width / 2
          node.y = lineTop + rowHeight / 2
          x += node.width + gapWithinLayer
        }
        lineTop += rowHeight + gapWithinLayer
      }
      cursor = lineTop - gapWithinLayer + gapBetweenLayers
    } else {
      const lines = chunk(layerNodes, perLine)
      let lineLeft = cursor
      for (const line of lines) {
        const columnHeight =
          line.reduce((sum, n) => sum + n.height, 0) + gapWithinLayer * (line.length - 1)
        const columnWidth = largestOf(line, (n) => n.width)
        let y = -columnHeight / 2
        for (const node of line) {
          node.x = lineLeft + columnWidth / 2
          node.y = y + node.height / 2
          y += node.height + gapWithinLayer
        }
        lineLeft += columnWidth + gapWithinLayer
      }
      cursor = lineLeft - gapWithinLayer + gapBetweenLayers
    }
  }
}

/**
 * How many nodes of an oversized layer go on one line.
 *
 * Square-root, so the block grows in both directions instead of one; widened slightly because a
 * card is wider than it is tall and a literal square reads as a column. Layers at or under the
 * threshold are returned whole and lay out exactly as before.
 */
function wrapWidth(count: number, maxOnOneLine = 12): number {
  return count <= maxOnOneLine ? count : Math.ceil(Math.sqrt(count) * 1.4)
}

function chunk<T>(items: T[], size: number): T[][] {
  if (size >= items.length) return [items]
  const out: T[][] = []
  for (let i = 0; i < items.length; i += size) out.push(items.slice(i, i + size))
  return out
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

/**
 * The largest value a callback yields over the nodes, without spreading them onto the stack.
 *
 * `Math.max(...array)` passes every element as an argument and throws `RangeError` past roughly
 * 124,000 of them. Nothing observed comes close — the largest single tab measured across three
 * applications is 177 nodes and the largest whole graph around 10,000 — so this is not a fix for
 * a bug anyone has hit. It is one line either way, and a layout that throws is a blank screen.
 */
function largestOf(nodes: LayoutNode[], of: (node: LayoutNode) => number): number {
  return nodes.reduce((largest, node) => Math.max(largest, of(node)), -Infinity)
}

export function layoutCircle(nodes: LayoutNode[], gap = 40): void {
  const n = nodes.length
  if (!n) return

  // The radius has to come from the cards, not from a constant. Nodes sit at uniform angles,
  // so each gets `2πr / n` of arc — a fixed radius therefore shrinks the space per card as
  // cards are added, which is backwards. The caller used `min(280, 90 + n * 4)`, so twenty
  // 185-270px cards were placed 53px apart and the ring was a pile.
  //
  // Every card gets the same arc, so it is sized for the largest, and by its longer side:
  // which side faces the tangent depends on where on the ring a card sits.
  const arc = largestOf(nodes, (node) => Math.max(node.width, node.height)) + gap
  const radius = Math.max(arc, (n * arc) / (2 * Math.PI))

  nodes.forEach((node, i) => {
    const a = (i / n) * Math.PI * 2 - Math.PI / 2
    node.x = radius * Math.cos(a)
    node.y = radius * Math.sin(a)
  })
}

export function layoutGrid(nodes: LayoutNode[], gapX = 60, gapY = 60): void {
  if (!nodes.length) return
  // Same reasoning as the layered layout: a fixed 200-wide cell clipped the 270-wide cards.
  const cellW = largestOf(nodes, (n) => n.width) + gapX
  const cellH = largestOf(nodes, (n) => n.height) + gapY
  const cols = Math.ceil(Math.sqrt(nodes.length))
  nodes.forEach((node, i) => {
    node.x = (i % cols) * cellW
    node.y = Math.floor(i / cols) * cellH
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
