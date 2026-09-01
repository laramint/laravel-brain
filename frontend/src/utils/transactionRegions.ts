import type { LayoutNode } from './graphLayoutD3'

/**
 * One region to draw around everything that ran inside a single transaction.
 *
 * `pure` is the part that matters. The layout arranges nodes by call structure and knows nothing
 * about transactions, so the members of one span are not guaranteed to sit together — and a shape
 * drawn around them can end up enclosing a node that was never in the transaction. That would be
 * a lie told confidently, which is worse than saying less, so it is measured rather than hoped
 * for: when an outsider falls inside the region, the caller is told and draws each member's own
 * outline instead of one enclosing boundary.
 */
export interface TransactionRegion {
  id: string
  /** Padded convex hull, in graph coordinates, ready for an SVG polygon. */
  points: [number, number][]
  members: LayoutNode[]
  kind: 'transaction' | 'rollback'
  pure: boolean
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

/**
 * Group laid-out nodes into one region per transaction.
 *
 * A span of one node gets a region too — it is still the truthful shape, just a small one — but a
 * span nothing marked is skipped rather than drawn as an empty box.
 */
export function transactionRegions(nodes: LayoutNode[], padding = 22): TransactionRegion[] {
  const groups = new Map<string, LayoutNode[]>()

  for (const node of nodes) {
    const id = (node.data as { transactionId?: unknown } | undefined)?.transactionId

    if (typeof id === 'string' && id !== '') {
      groups.set(id, [...(groups.get(id) ?? []), node])
    }
  }

  const regions: TransactionRegion[] = []

  for (const [id, members] of groups) {
    const points = inflate(hull(members.flatMap(corners)), padding)

    if (points.length < 3) continue

    const memberIds = new Set(members.map((m) => m.id))

    // Tested against the card's corners, not its centre. A wide node can sit with its centre
    // outside the boundary and half its body inside it, which passes a centre test and still
    // reads, to anyone looking, as a node the transaction contains.
    const pure = !nodes.some(
      (n) => !memberIds.has(n.id) && corners(n).some(([x, y]) => contains(points, x, y))
    )

    regions.push({
      id,
      points,
      members,
      kind: members.some((m) => (m.data as { inRollback?: unknown } | undefined)?.inRollback)
        ? 'rollback'
        : 'transaction',
      pure,
    })
  }

  return regions
}
