import type { LayoutNode } from './graphLayoutD3'

/**
 * What a region says about the work inside it.
 *
 * All four are a set of nodes with a boundary drawn round them, which is why they are one shape
 * here rather than four. They differ in one thing that shows on screen: whether the members are
 * an ordered sequence. `Bus::chain([A, B, C])` runs A, then B, then C, and B never runs if A
 * fails — so a chain is drawn with arrows through its members and the others are not, because a
 * transaction and a batch state no order at all and arrows would invent one.
 */
export type RegionKind = 'transaction' | 'rollback' | 'chain' | 'batch'

/** The kinds whose membership is a sequence rather than a set. */
export const ORDERED_REGION_KINDS: RegionKind[] = ['chain']

/** What each kind is called on screen, singular. */
export const REGION_KIND_LABELS: Record<RegionKind, string> = {
  transaction: 'transaction',
  rollback: 'rollback',
  chain: 'chain',
  batch: 'batch',
}

/** Spelled out rather than derived, because "batchs" is what deriving it gives. */
export const REGION_KIND_PLURALS: Record<RegionKind, string> = {
  transaction: 'transactions',
  rollback: 'rollbacks',
  chain: 'chains',
  batch: 'batches',
}

/** The order the kinds are listed in wherever all of them are: the two spans, then the two queues. */
export const REGION_KIND_ORDER: RegionKind[] = ['transaction', 'rollback', 'chain', 'batch']

/**
 * One region to draw around a set of nodes that belong together.
 *
 * `pure` is the part that matters. The layout arranges nodes by call structure and knows nothing
 * about regions, so the members of one are not guaranteed to sit together — and a shape drawn
 * around them can end up enclosing a node that was never in it. That would be a lie told
 * confidently, which is worse than saying less, so it is measured rather than hoped for: when an
 * outsider falls inside the region, the caller is told and draws each member's own outline
 * instead of one enclosing boundary.
 */
export interface GraphRegion {
  id: string
  kind: RegionKind
  /** 1-based within the kind, stable across renders: what the label says, and how two are told apart. */
  index: number
  /** Padded convex hull, in graph coordinates, ready for an SVG polygon. */
  points: [number, number][]
  /** In run order for an ordered kind; in whatever order the nodes arrived for the others. */
  members: LayoutNode[]
  /** The members run one after another, so the region is drawn with arrows through them. */
  ordered: boolean
  pure: boolean
}

/** One node's membership of one region, as the analyzer wrote it onto the node. */
interface RegionMembership {
  id?: unknown
  kind?: unknown
  position?: unknown
}

/** The corners of a node's card, which is what a region has to contain — not its centre. */
function corners(node: LayoutNode): [number, number][] {
  const hw = node.width / 2
  const hh = node.height / 2

  return [
    [node.x - hw, node.y - hh],
    [node.x + hw, node.y - hh],
    [node.x + hw, node.y + hh],
    [node.x - hw, node.y + hh],
  ]
}

/** Convex hull by Andrew's monotone chain — O(n log n), and stable for the handful of points here. */
function hull(points: [number, number][]): [number, number][] {
  if (points.length < 3) return points

  const sorted = [...points].sort((a, b) => (a[0] - b[0]) || (a[1] - b[1]))
  const cross = (o: [number, number], a: [number, number], b: [number, number]): number =>
    (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0])

  const build = (input: [number, number][]): [number, number][] => {
    const out: [number, number][] = []
    for (const point of input) {
      while (out.length >= 2 && cross(out[out.length - 2], out[out.length - 1], point) <= 0) out.pop()
      out.push(point)
    }
    out.pop()
    return out
  }

  return [...build(sorted), ...build([...sorted].reverse())]
}

/** Push every hull vertex outwards from the centroid, so the boundary clears the cards. */
function inflate(points: [number, number][], by: number): [number, number][] {
  if (points.length === 0) return points

  const cx = points.reduce((sum, p) => sum + p[0], 0) / points.length
  const cy = points.reduce((sum, p) => sum + p[1], 0) / points.length

  return points.map(([x, y]) => {
    const dx = x - cx
    const dy = y - cy
    const length = Math.hypot(dx, dy) || 1

    return [x + (dx / length) * by, y + (dy / length) * by] as [number, number]
  })
}

function contains(polygon: [number, number][], x: number, y: number): boolean {
  let inside = false

  for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
    const [xi, yi] = polygon[i]
    const [xj, yj] = polygon[j]
    const straddles = (yi > y) !== (yj > y)

    if (straddles && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) inside = !inside
  }

  return inside
}

const KINDS = new Set<string>(['transaction', 'rollback', 'chain', 'batch'])

/**
 * The run between two cards of an ordered region, cut at the edge of each.
 *
 * Drawn from the borders rather than from the centres so the arrowhead lands where a reader
 * expects it — on the card it points at, not somewhere under it. Two cards on top of one another
 * have no run between them and get none: an arrow of zero length renders as a dot that reads,
 * misleadingly, like a mark of its own.
 */
export function regionStep(
  from: LayoutNode,
  to: LayoutNode,
  clearance = 4,
): { x1: number; y1: number; x2: number; y2: number } | null {
  const dx = to.x - from.x
  const dy = to.y - from.y

  if (dx === 0 && dy === 0) return null

  /** How far along the run the card's own border sits. */
  const exit = (node: LayoutNode): number => {
    const byWidth = dx === 0 ? Infinity : (node.width / 2 + clearance) / Math.abs(dx)
    const byHeight = dy === 0 ? Infinity : (node.height / 2 + clearance) / Math.abs(dy)

    return Math.min(byWidth, byHeight)
  }

  const start = exit(from)
  const end = 1 - exit(to)

  if (start >= end) return null

  return {
    x1: from.x + dx * start,
    y1: from.y + dy * start,
    x2: from.x + dx * end,
    y2: from.y + dy * end,
  }
}

/** Every region a node was stamped with. A node can be in several — a chain inside a transaction. */
export function membershipsOf(node: { data?: unknown }): { id: string; kind: RegionKind; position: number | null }[] {
  const raw = (node.data as { regions?: unknown } | undefined)?.regions

  if (!Array.isArray(raw)) return []

  const out: { id: string; kind: RegionKind; position: number | null }[] = []

  for (const entry of raw as RegionMembership[]) {
    const id = entry?.id
    const kind = entry?.kind

    if (typeof id !== 'string' || id === '' || typeof kind !== 'string' || !KINDS.has(kind)) continue

    out.push({
      id,
      kind: kind as RegionKind,
      position: typeof entry.position === 'number' ? entry.position : null,
    })
  }

  return out
}

/**
 * Group laid-out nodes into one region per id.
 *
 * A region of one node gets drawn too — it is still the truthful shape, just a small one — but an
 * id nothing was stamped with is skipped rather than drawn as an empty box.
 */
export function graphRegions(nodes: LayoutNode[], padding = 22): GraphRegion[] {
  const groups = new Map<string, { kind: RegionKind; members: { node: LayoutNode; position: number | null }[] }>()

  for (const node of nodes) {
    for (const membership of membershipsOf(node)) {
      const group = groups.get(membership.id) ?? { kind: membership.kind, members: [] }
      group.members.push({ node, position: membership.position })
      groups.set(membership.id, group)
    }
  }

  const regions: GraphRegion[] = []

  // Numbered within the kind and by id rather than by discovery order, so a region keeps its
  // number when the layout changes or a node is dragged — a label that renumbers itself is a
  // label nobody can refer to. Per kind, because "chain 1" beside "transaction 1" reads as two
  // regions, where a single running count would leave a canvas holding "chain 2" and no chain 1.
  const numbering = new Map<string, number>()
  const counts = new Map<RegionKind, number>()

  for (const id of [...groups.keys()].sort()) {
    const kind = groups.get(id)!.kind
    const next = (counts.get(kind) ?? 0) + 1
    counts.set(kind, next)
    numbering.set(id, next)
  }

  for (const [id, group] of groups) {
    const ordered = ORDERED_REGION_KINDS.includes(group.kind)

    // Sorted by the position the analyzer recorded, which is the order the jobs run in — not the
    // order the nodes happen to arrive in, which is the order the graph file was written.
    const sorted = ordered
      ? [...group.members].sort((a, b) => (a.position ?? 0) - (b.position ?? 0))
      : group.members

    const members = sorted.map((m) => m.node)
    const points = inflate(hull(members.flatMap(corners)), padding)

    if (points.length < 3) continue

    const memberIds = new Set(members.map((m) => m.id))

    // Tested against the card's corners, not its centre. A wide node can sit with its centre
    // outside the boundary and half its body inside it, which passes a centre test and still
    // reads, to anyone looking, as a node the region contains.
    const pure = !nodes.some(
      (n) => !memberIds.has(n.id) && corners(n).some(([x, y]) => contains(points, x, y))
    )

    regions.push({
      id,
      kind: group.kind,
      index: numbering.get(id) ?? 1,
      points,
      members,
      ordered,
      pure,
    })
  }

  return regions
}
