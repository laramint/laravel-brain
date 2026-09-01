import type { GraphData, GraphEdge, GraphNode, SequenceActor, SequenceMessage, SequenceDiagram } from '../types/graph'
import { ACCENT_COLORS } from './graphConstants'

/** Order columns left-to-right (tokens match `normalizeType` where applicable). */
const TYPE_PRIORITY = [
  'route', 'middleware', 'controller', 'action', 'validation_request', 'action_class', 'service', 'model', 'event', 'job',
  'command', 'channel', 'schedule', 'view', 'mail', 'notification', 'enum', 'interface', 'trait', 'abstract_class', 'service_provider',
  'ai_agent', 'ai_tool',
]

function normalizeType(type: string): string {
  return type === 'action' ? 'controller' : type
}

function typeSortRank(node: GraphNode | undefined): number {
  if (!node) return 99
  const t = normalizeType(node.type)
  const p = TYPE_PRIORITY.indexOf(t)
  return p === -1 ? 99 : p
}

function shortLabel(label: string): string {
  const parts = label.split('\\')
  const last = parts[parts.length - 1]
  if (last.length <= 20) return last
  return last.substring(0, 18) + '…'
}

function buildOutgoingMap(edges: GraphEdge[]): Map<string, GraphEdge[]> {
  const map = new Map<string, GraphEdge[]>()
  for (const edge of edges) {
    if (!map.has(edge.source)) map.set(edge.source, [])
    map.get(edge.source)!.push(edge)
  }
  return map
}

/** Async / fire-and-forget style edges from the Laravel Brain graph. */
function edgeIsAsync(edgeType: string): boolean {
  return (
    edgeType.includes('-to-job') ||
    edgeType.includes('-to-event') ||
    edgeType === 'model-to-event'
  )
}

export function buildSequenceDiagram(routeNodeId: string, graphData: GraphData): SequenceDiagram {
  const nodeMap = new Map(graphData.nodes.map(n => [n.id, n]))
  const outMap = buildOutgoingMap(graphData.edges)

  const visited = new Set<string>()
  const orderedNodeIds: string[] = []
  const orderedEdges: GraphEdge[] = []
  const queue: string[] = [routeNodeId]
  visited.add(routeNodeId)

  while (queue.length > 0) {
    const current = queue.shift()!
    orderedNodeIds.push(current)
    for (const edge of outMap.get(current) ?? []) {
      orderedEdges.push(edge)
      if (!visited.has(edge.target)) {
        visited.add(edge.target)
        queue.push(edge.target)
      }
    }
  }

  // One participant per graph node so multiple middleware (and services, models, …) match the graph.
  const actors: SequenceActor[] = []
  const nodeToActorIndex = new Map<string, number>()

  const sortedNodeIds = [...orderedNodeIds].sort((a, b) => {
    const ra = typeSortRank(nodeMap.get(a))
    const rb = typeSortRank(nodeMap.get(b))
    if (ra !== rb) return ra - rb
    return a.localeCompare(b)
  })

  for (const nodeId of sortedNodeIds) {
    const node = nodeMap.get(nodeId)
    if (!node) continue
    const idx = actors.length
    nodeToActorIndex.set(nodeId, idx)
    const displayType = normalizeType(node.type)
    actors.push({
      id: node.id,
      label: shortLabel(node.label),
      type: displayType,
      color: ACCENT_COLORS[node.type] ?? ACCENT_COLORS[displayType] ?? '#888',
    })
  }

  actors.unshift({ id: '__client__', label: 'Client', type: 'client', color: '#78909C' })
  for (const id of [...nodeToActorIndex.keys()]) {
    nodeToActorIndex.set(id, nodeToActorIndex.get(id)! + 1)
  }

  const modelNodeIds = sortedNodeIds.filter(id => nodeMap.get(id)?.type === 'model')
  let dbActorIndex: number | null = null
  if (modelNodeIds.length > 0) {
    dbActorIndex = actors.length
    actors.push({ id: '__db__', label: 'Database', type: 'db', color: '#78909C' })
  }

  const messages: SequenceMessage[] = []

  const routeActorIndex = nodeToActorIndex.get(routeNodeId)
  if (routeActorIndex !== undefined) {
    messages.push({ fromIndex: 0, toIndex: routeActorIndex, label: 'request', isReturn: false })
  }

  for (const edge of orderedEdges) {
    const from = nodeToActorIndex.get(edge.source)
    const to = nodeToActorIndex.get(edge.target)
    if (from === undefined || to === undefined || from === to) continue
    const isAsync = edgeIsAsync(edge.type)
    messages.push({
      fromIndex: from,
      toIndex: to,
      label: edge.label || '',
      isAsync,
    })
  }

  if (dbActorIndex !== null) {
    for (const modelId of modelNodeIds) {
      const modelIdx = nodeToActorIndex.get(modelId)
      if (modelIdx === undefined) continue
      messages.push({ fromIndex: modelIdx, toIndex: dbActorIndex, label: 'query', isReturn: false })
      messages.push({ fromIndex: dbActorIndex, toIndex: modelIdx, label: 'result', isReturn: true })
    }
  }

  if (routeActorIndex !== undefined) {
    messages.push({ fromIndex: routeActorIndex, toIndex: 0, label: 'response', isReturn: true })
  }

  const seen = new Map<string, { idx: number; count: number }>()
  const dedupedMessages: SequenceMessage[] = []
  for (const msg of messages) {
    const key = `${msg.fromIndex}|${msg.toIndex}|${msg.label}|${msg.isReturn ? 'r' : ''}|${msg.isAsync ? 'a' : ''}`
    const existing = seen.get(key)
    if (existing) {
      existing.count++
      const baseLabel = msg.label
      dedupedMessages[existing.idx] = {
        ...dedupedMessages[existing.idx],
        label: `${baseLabel} ×${existing.count}`,
      }
    } else {
      seen.set(key, { idx: dedupedMessages.length, count: 1 })
      dedupedMessages.push(msg)
    }
  }

  return { actors, messages: dedupedMessages }
}

export function sequenceDiagramToMermaid(diagram: SequenceDiagram, routeLabel: string): string {
  const lines: string[] = [
    `%% Sequence Diagram — ${routeLabel}`,
    `sequenceDiagram`,
    `  autonumber`,
  ]

  // Stable P0..Pn ids avoid collisions when node ids sanitize to the same string.
  for (let i = 0; i < diagram.actors.length; i++) {
    const actor = diagram.actors[i]
    const pid = `P${i}`
    const displayLabel = actor.label.replace(/"/g, "'")
    lines.push(`  participant ${pid} as "${displayLabel}"`)
  }

  lines.push(``)

  for (const msg of diagram.messages) {
    const fromId = `P${msg.fromIndex}`
    const toId = `P${msg.toIndex}`
    const label = msg.label.replace(/"/g, "'")

    let arrow: string
    if (msg.isAsync) {
      arrow = '->>'
    } else if (msg.isReturn) {
      arrow = '-->>'
    } else {
      arrow = '->>'
    }

    lines.push(`  ${fromId}${arrow}${toId}: ${label}`)
  }

  return lines.join('\n')
}
