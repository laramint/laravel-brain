import type { GraphData, FlowStep } from '../types/graph'
import { ACCENT_COLORS, BG_COLORS } from './graphConstants'
import { splitNodeLabel } from './graphLayoutD3'

// ── Mermaid from full graph data ──────────────────────────────────────────────

const TYPE_ORDER = [
  'route', 'middleware', 'controller', 'action', 'action_class', 'service', 'validation_request', 'repository', 'model', 'job', 'event',
  'view', 'mail', 'notification', 'enum', 'interface', 'trait', 'abstract_class', 'service_provider',
  'ai_agent', 'ai_tool',
  'filament_panel', 'filament_resource', 'filament_page', 'filament_page_method',
  'filament_widget', 'filament_relation_manager',
]

/**
 * Convert the current tab's graph (nodes + edges) into Mermaid flowchart syntax.
 * Styling matches the graph card design exactly (same bg/accent colors, label format).
 */
export function graphToMermaid(data: GraphData, tabLabel: string): string {
  const lines: string[] = []

  // Dark theme init — matches graph background
  lines.push(`%%{init: {'theme': 'dark', 'themeVariables': {`)
  lines.push(`  'background': '#0a0c10',`)
  lines.push(`  'mainBkg': '#0d1117',`)
  lines.push(`  'lineColor': 'rgba(255,255,255,0.35)',`)
  lines.push(`  'edgeLabelBackground': '#111218',`)
  lines.push(`  'edgeLabelColor': 'rgba(255,255,255,0.5)'`)
  lines.push(`}}}%%`)
  lines.push(`%% Laravel Brain — ${tabLabel}`)
  lines.push(`flowchart TD`)
  lines.push(``)

  // Node ID → safe Mermaid id
  const idMap = new Map<string, string>()
  const used = new Set<string>()
  const safeId = (raw: string): string => {
    if (idMap.has(raw)) return idMap.get(raw)!
    let base = raw
      .replace(/[^a-zA-Z0-9_]/g, '_')
      .replace(/^_+/, '')
      .replace(/_+$/, '')
      .substring(0, 40)
    if (!base) base = 'node'
    let id = base
    let n = 0
    while (used.has(id)) id = `${base}_${++n}`
    used.add(id)
    idMap.set(raw, id)
    return id
  }

  // Group nodes by type
  const byType = new Map<string, typeof data.nodes>()
  for (const node of data.nodes) {
    if (!byType.has(node.type)) byType.set(node.type, [])
    byType.get(node.type)!.push(node)
  }
  const allTypes = [...new Set([...TYPE_ORDER, ...byType.keys()])]
  const typesWithNodes = allTypes.filter(t => (byType.get(t)?.length ?? 0) > 0)

  // Emit nodes grouped by type
  for (const type of typesWithNodes) {
    const nodes = byType.get(type)!
    lines.push(`  %% ${type}`)
    for (const node of nodes) {
      const id = safeId(node.id)
      const label = buildCardLabel(node)
      lines.push(`  ${id}["${escapeLabel(label)}"]`)
    }
    lines.push(``)
  }

  // Edges
  lines.push(`  %% Edges`)
  for (const edge of data.edges) {
    const src = safeId(edge.source)
    const tgt = safeId(edge.target)
    const lbl = edge.label ? `|"${escapeLabel(edge.label)}"| ` : ''
    lines.push(`  ${src} -->${lbl}${tgt}`)
  }
  lines.push(``)

  // classDef — exact bg + accent from graphConstants
  lines.push(`  %% Styles`)
  for (const type of typesWithNodes) {
    const accent = ACCENT_COLORS[type] ?? '#c9d1d9'
    const bg = BG_COLORS[type] ?? '#0d1117'
    lines.push(`  classDef cls_${type} fill:${bg},stroke:${accent},stroke-width:2px,color:#e6edf3`)
  }
  lines.push(``)

  // Apply classes
  for (const type of typesWithNodes) {
    const nodes = byType.get(type)!
    const ids = nodes.map(n => safeId(n.id)).join(',')
    lines.push(`  class ${ids} cls_${type}`)
  }

  return lines.join('\n')
}

/** Build a card label that mirrors the graph node: "● type\nClassName\n↻ method()" */
function buildCardLabel(node: { type: string; label: string; data: Record<string, unknown> }): string {
  const rawLabel = String(node.label ?? '')
  const dataMethod = node.data?.method as string | undefined
  const { className, method } = splitNodeLabel(rawLabel, dataMethod)
  const methodDisplay = method && !method.includes('(') ? method + '()' : method
  const parts = [`● ${node.type}`, className]
  if (methodDisplay) parts.push(`↻ ${methodDisplay}`)
  return parts.join('\n')
}

// ── Mermaid from FlowStep[] ───────────────────────────────────────────────────

/**
 * Convert a method's FlowStep[] into Mermaid flowchart syntax.
 */
export function flowStepsToMermaid(steps: FlowStep[], methodLabel: string): string {
  const lines: string[] = [
    `%% Method Flow — ${methodLabel}`,
    `flowchart TD`,
  ]

  let counter = 0
  const nextId = () => `s${counter++}`

  const entryId = nextId()
  lines.push(`  ${entryId}([" 🚀 ${escapeLabel(methodLabel)} "])`)

  const processSteps = (stepsArr: FlowStep[], parentId: string): string => {
    let prevId = parentId
    for (const step of stepsArr) {
      const id = nextId()

      if (step.type === 'if') {
        const [open, close] = ['{', '}']
        lines.push(`  ${id}${open}"${escapeLabel(step.label)}"${close}`)
        lines.push(`  ${prevId} --> ${id}`)
        lines.push(`  class ${id} cls_if`)

        if (step.then && step.then.length > 0) {
          const thenFirst = nextId()
          const thenLabel = step.then[0]
          lines.push(`  ${thenFirst}${stepShape(thenLabel.type)}"${escapeLabel(thenLabel.label)}"${stepShapeClose(thenLabel.type)}`)
          lines.push(`  ${id} -->|"yes"| ${thenFirst}`)
          lines.push(`  class ${thenFirst} cls_${thenLabel.type}`)
          processSteps(step.then.slice(1), thenFirst)
        }
        if (step.else && step.else.length > 0) {
          const elseFirst = nextId()
          const elseLabel = step.else[0]
          lines.push(`  ${elseFirst}${stepShape(elseLabel.type)}"${escapeLabel(elseLabel.label)}"${stepShapeClose(elseLabel.type)}`)
          lines.push(`  ${id} -->|"no"| ${elseFirst}`)
          lines.push(`  class ${elseFirst} cls_${elseLabel.type}`)
          processSteps(step.else.slice(1), elseFirst)
        }

        prevId = id
      } else if (step.type === 'loop') {
        const n1Label = step.n1 ? ' ⚠️ N+1 ' : ''
        // `Cache::remember(…, fn)` is re-typed to `loop` so the chart can descend into the
        // closure; without this it would export as an unlabelled block of work.
        const cacheLabel = step.cache ? ` [cache ${step.cache.kind}] ` : ''
        lines.push(`  ${id}[/"${n1Label}${cacheLabel}${escapeLabel(step.label)}"/]`)
        lines.push(`  ${prevId} --> ${id}`)
        lines.push(`  class ${id} ${step.n1 ? 'cls_n1' : step.cache ? 'cls_cache' : 'cls_loop'}`)
        if (step.body && step.body.length > 0) {
          processSteps(step.body, id)
        }
        prevId = id
      } else {
        const [open, close] = [stepShape(step.type), stepShapeClose(step.type)]
        const icon = stepIcon(step.type)
        const n1Label = step.n1 ? ' ⚠️ N+1 ' : ''
        // A returned cache read keeps its `return` type, so the kind has to travel in the label
        // or the exported chart loses what the panel showed.
        const cacheLabel = step.cache ? ` [cache ${step.cache.kind}] ` : ''
        lines.push(`  ${id}${open}"${n1Label}${cacheLabel}${icon}${escapeLabel(step.label)}"${close}`)
        lines.push(`  ${prevId} --> ${id}`)
        lines.push(`  class ${id} ${step.n1 ? 'cls_n1' : `cls_${step.type}`}`)
        prevId = id
      }
    }
    return prevId
  }

  processSteps(steps, entryId)

  lines.push(``)
  lines.push(`  %% STYLES`)
  lines.push(`  classDef cls_call     fill:#0d47a1,stroke:#2196F3,color:#fff`)
  lines.push(`  classDef cls_assign   fill:#212121,stroke:#616161,color:#ccc`)
  lines.push(`  classDef cls_return   fill:#1b5e20,stroke:#4CAF50,color:#fff`)
  lines.push(`  classDef cls_throw    fill:#b71c1c,stroke:#F44336,color:#fff`)
  lines.push(`  classDef cls_if       fill:#f9a825,stroke:#fbc02d,color:#000`)
  lines.push(`  classDef cls_loop     fill:#6a1b9a,stroke:#9c27b0,color:#fff`)
  lines.push(`  classDef cls_n1       fill:#b71c1c,stroke:#ff5252,color:#fff`)
  lines.push(`  classDef cls_dispatch fill:#bf360c,stroke:#FF5722,color:#fff`)
  lines.push(`  classDef cls_event    fill:#0e47a1,stroke:#00BCD4,color:#fff`)
  lines.push(`  classDef cls_cache    fill:#004d40,stroke:#009688,color:#fff`)

  return lines.join('\n')
}

// ── Download helpers ──────────────────────────────────────────────────────────

export function downloadText(text: string, filename: string): void {
  const blob = new Blob([text], { type: 'text/plain' })
  downloadBlob(blob, filename)
}

export function downloadPng(dataUrl: string, filename: string): void {
  const a = document.createElement('a')
  a.href = dataUrl
  a.download = filename
  a.click()
}

export function downloadBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  setTimeout(() => URL.revokeObjectURL(url), 2000)
}

/**
 * Convert a DOM element to a PNG data URL using Canvas.
 * Works on the sidebar flowchart div.
 */
export async function domToPng(element: HTMLElement, bgColor = '#0d0f14'): Promise<string> {
  const { default: html2canvas } = await import('html2canvas')
  const canvas = await html2canvas(element, {
    backgroundColor: bgColor,
    scale: 2,
    useCORS: true,
    logging: false,
  })
  return canvas.toDataURL('image/png')
}

// ── Helpers ───────────────────────────────────────────────────────────────────


function stepShape(type: string): string {
  switch (type) {
    case 'return':   return '(['
    case 'throw':    return '(['
    case 'dispatch': return '[['
    case 'event':    return '(('
    default:         return '['
  }
}

function stepShapeClose(type: string): string {
  switch (type) {
    case 'return':   return '])'
    case 'throw':    return '])'
    case 'dispatch': return ']]'
    case 'event':    return '))'
    default:         return ']'
  }
}

function stepIcon(type: string): string {
  switch (type) {
    case 'call':     return '→ '
    case 'assign':   return '= '
    case 'return':   return '◀ '
    case 'throw':    return '⚠ '
    case 'dispatch': return '⚡ '
    case 'event':    return '📡 '
    case 'cache':    return '⛃ '
    default:         return ''
  }
}

function escapeLabel(text: string): string {
  return text
    .replace(/"/g, "'")
    .replace(/\n/g, '\\n')
    .replace(/[<>]/g, (c) => (c === '<' ? '&lt;' : '&gt;'))
}
